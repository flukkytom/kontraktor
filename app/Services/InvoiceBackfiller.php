<?php

namespace App\Services;

use App\Enums\InvoiceSource;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns validated backfill import rows into invoice records. Historical
 * invoices keep their own numbers and land with the given status.
 */
class InvoiceBackfiller
{
    /**
     * @param  Collection<int, array{data: ?array, error: ?string, row: int}>  $parsedRows
     * @return array{created: int, errors: array<int, string>}
     */
    public function import(Collection $parsedRows): array
    {
        $created = 0;
        $errors = [];

        foreach ($parsedRows as $parsed) {
            if ($parsed['error'] !== null) {
                $errors[] = "Row {$parsed['row']}: {$parsed['error']}";

                continue;
            }

            try {
                $this->createInvoice($parsed['data']);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$parsed['row']}: {$e->getMessage()}";
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    private function createInvoice(array $data): Invoice
    {
        $profile = $data['profile'];

        if ($profile->client === null) {
            throw new RuntimeException("Contractor {$profile->billing_name} has no client assigned.");
        }

        return DB::transaction(function () use ($profile, $data) {
            $invoice = new Invoice([
                'client_id' => $profile->client_id,
                'invoice_number' => $data['invoice_number'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'invoice_date' => $data['invoice_date'],
                'status' => $data['status'],
                'source' => InvoiceSource::Excel,
                'tax_rate' => $profile->tax_rate,
            ]);
            $invoice->contractorProfile()->associate($profile);
            $invoice->save();

            $subtotal = $data['hours'] !== null
                ? round($data['hours'] * $data['rate'], 2)
                : ($data['explicit_total'] !== null
                    ? round($data['explicit_total'] / (1 + (float) $profile->tax_rate), 2)
                    : throw new RuntimeException('Row needs hours+rate or a total.'));

            $invoice->lines()->create([
                'description' => $data['description'] ?? sprintf(
                    'Hours for %s - %s',
                    $data['period_start']->format('F jS'),
                    $data['period_end']->format('F jS, Y')
                ),
                'hours' => $data['hours'],
                'rate' => $data['rate'],
                'amount' => $subtotal,
            ]);

            $invoice->recalculateTotals();

            // An explicit historical total wins over the recomputed one.
            if ($data['explicit_total'] !== null) {
                $invoice->total = number_format($data['explicit_total'], 2, '.', '');
            }

            $invoice->save();

            return $invoice;
        });
    }
}
