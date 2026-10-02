<?php

namespace App\Services;

use App\Enums\InvoiceSource;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Two-phase invoice creation: prepare*() computes a draft (lines, number,
 * totals) without touching the database so it can be previewed; create()
 * persists exactly that draft.
 */
class InvoiceBuilder
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
    ) {}

    /**
     * Pull Harvest entries for the period and prepare a single summary line,
     * keeping the raw entries for audit.
     */
    public function prepareFromHarvest(
        ContractorProfile $profile,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): InvoiceDraft {
        if (! $profile->hasHarvestCredentials()) {
            throw new RuntimeException('Harvest credentials are not configured on this profile.');
        }

        $entries = (new HarvestClient(
            $profile->harvest_access_token,
            $profile->harvest_account_id,
        ))->timeEntries($periodStart->toDateString(), $periodEnd->toDateString());

        if ($entries->isEmpty()) {
            throw new RuntimeException('Harvest returned no time entries for this period.');
        }

        $rate = $this->resolveRate($profile, $periodEnd);
        $hours = round($entries->sum('hours'), 2);

        return $this->draft(
            $profile, $periodStart, $periodEnd,
            [$this->summaryLine($periodStart, $periodEnd, $hours, $rate)],
            InvoiceSource::Harvest,
            ['entries' => $entries->all()],
        );
    }

    /**
     * Prepare a draft from caller-supplied lines (Excel or manual). Each
     * line: ['description', 'hours'?, 'rate'?, 'amount'?]. Rate defaults to
     * the profile rate for the period; amount defaults to hours * rate.
     *
     * @param  array<int, array{description: string, hours?: float, rate?: float, amount?: float}>  $lines
     */
    public function prepareFromLines(
        ContractorProfile $profile,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        array $lines,
        InvoiceSource $source = InvoiceSource::Excel,
    ): InvoiceDraft {
        if ($lines === []) {
            throw new RuntimeException('At least one invoice line is required.');
        }

        $rate = $this->resolveRate($profile, $periodEnd);

        $prepared = collect($lines)->map(function (array $line) use ($rate) {
            $hours = isset($line['hours']) && $line['hours'] !== '' ? round((float) $line['hours'], 2) : null;
            $lineRate = isset($line['rate']) && $line['rate'] !== '' ? (float) $line['rate'] : $rate;

            return [
                'description' => trim((string) $line['description']),
                'hours' => $hours,
                'rate' => $lineRate,
                'amount' => isset($line['amount']) && $line['amount'] !== ''
                    ? round((float) $line['amount'], 2)
                    : round(($hours ?? 0) * $lineRate, 2),
            ];
        })->all();

        return $this->draft($profile, $periodStart, $periodEnd, $prepared, $source);
    }

    /**
     * Persist a previewed draft. The number is what the user saw (or typed)
     * on the preview, so a clash is reported rather than silently changed.
     */
    public function create(ContractorProfile $profile, Client $client, InvoiceDraft $draft): Invoice
    {
        if ($draft->invoiceNumber === '') {
            throw new RuntimeException('Invoice number is required.');
        }

        if ($profile->invoices()->where('invoice_number', $draft->invoiceNumber)->exists()) {
            throw new RuntimeException("Invoice number {$draft->invoiceNumber} is already used. Next available: {$this->numbers->generate($profile, $draft->invoiceDate)}.");
        }

        return DB::transaction(function () use ($profile, $client, $draft) {
            $invoice = new Invoice([
                'client_id' => $client->id,
                'invoice_number' => $draft->invoiceNumber,
                'period_start' => $draft->periodStart,
                'period_end' => $draft->periodEnd,
                'invoice_date' => $draft->invoiceDate,
                'status' => InvoiceStatus::Draft,
                'source' => $draft->source,
                'tax_rate' => $draft->taxRate,
                'source_payload' => $draft->sourcePayload,
            ]);
            $invoice->contractorProfile()->associate($profile);
            $invoice->save();

            $invoice->lines()->createMany(
                collect($draft->lines)->map(fn (array $line, int $i) => $line + ['sort_order' => $i])->all()
            );

            $invoice->recalculateTotals();
            $invoice->save();

            return $invoice->fresh('lines');
        });
    }

    /** Convenience: prepare and create in one step (Harvest page, tests). */
    public function buildFromHarvest(
        ContractorProfile $profile,
        Client $client,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): Invoice {
        return $this->create($profile, $client, $this->prepareFromHarvest($profile, $periodStart, $periodEnd));
    }

    /** Convenience: prepare and create in one step. */
    public function buildFromLines(
        ContractorProfile $profile,
        Client $client,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        array $lines,
        InvoiceSource $source = InvoiceSource::Excel,
    ): Invoice {
        return $this->create($profile, $client, $this->prepareFromLines($profile, $periodStart, $periodEnd, $lines, $source));
    }

    private function draft(
        ContractorProfile $profile,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        array $lines,
        InvoiceSource $source,
        ?array $sourcePayload = null,
    ): InvoiceDraft {
        $invoiceDate = CarbonImmutable::today();

        return new InvoiceDraft(
            invoiceNumber: $this->numbers->generate($profile, $invoiceDate),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            invoiceDate: $invoiceDate,
            lines: $lines,
            taxRate: (float) $profile->tax_rate,
            source: $source,
            sourcePayload: $sourcePayload,
        );
    }

    private function resolveRate(ContractorProfile $profile, CarbonImmutable $asOf): float
    {
        $rate = $profile->rateFor($asOf);

        if ($rate === null) {
            throw new RuntimeException('No hourly rate configured for this contractor.');
        }

        return (float) $rate->hourly_rate;
    }

    /** @return array{description: string, hours: float, rate: float, amount: float} */
    private function summaryLine(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        float $hours,
        float $rate,
    ): array {
        $description = $periodStart->month === $periodEnd->month
            ? sprintf('Hours for %s %s - %s, %s',
                $periodStart->format('F'), $periodStart->format('jS'),
                $periodEnd->format('jS'), $periodEnd->format('Y'))
            : sprintf('Hours for %s - %s',
                $periodStart->format('F jS, Y'), $periodEnd->format('F jS, Y'));

        return [
            'description' => $description,
            'hours' => $hours,
            'rate' => $rate,
            'amount' => round($hours * $rate, 2),
        ];
    }
}
