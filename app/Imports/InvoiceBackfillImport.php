<?php

namespace App\Imports;

use App\Enums\InvoiceStatus;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Admin backfill import — one row per historical invoice.
 *
 * Expected headings: contractor_email, invoice_number, period_start,
 * period_end, invoice_date, hours, rate, total, status.
 * `total` and `status` are optional — total falls back to hours * rate
 * plus the contractor's tax rate; status defaults to paid.
 */
class InvoiceBackfillImport implements ToCollection, WithHeadingRow
{
    /**
     * @return Collection<int, array{row: int, data: ?array, error: ?string}>
     */
    public function collection(Collection $rows): Collection
    {
        return $rows->values()->map(function ($row, $i) {
            [$data, $error] = $this->parseRow($row->all());

            return ['row' => $i + 2, 'data' => $data, 'error' => $error];
        });
    }

    /** @return array{0: ?array, 1: ?string} */
    private function parseRow(array $row): array
    {
        $email = trim((string) ($row['contractor_email'] ?? ''));
        $number = trim((string) ($row['invoice_number'] ?? ''));

        $profile = $email !== ''
            ? ContractorProfile::whereHas('user', fn ($q) => $q->where('email', $email))->first()
            : null;

        if ($profile === null) {
            return [null, "No contractor profile for email '{$email}'"];
        }

        if ($number === '') {
            return [null, 'Missing invoice_number'];
        }

        if ($profile->invoices()->where('invoice_number', $number)->exists()) {
            return [null, "Invoice {$number} already exists for {$email} — skipped"];
        }

        $periodStart = $this->parseDate($row['period_start'] ?? null);
        $periodEnd = $this->parseDate($row['period_end'] ?? null);
        $invoiceDate = $this->parseDate($row['invoice_date'] ?? null) ?? $periodEnd;

        if (! $periodStart || ! $periodEnd || ! $invoiceDate) {
            return [null, 'Missing or invalid period/invoice date'];
        }

        $hours = is_numeric($row['hours'] ?? null) ? (float) $row['hours'] : null;
        $rate = is_numeric($row['rate'] ?? null) ? (float) $row['rate'] : (float) ($profile->rateFor($periodEnd)?->hourly_rate ?? 0);

        if ($rate <= 0) {
            return [null, 'No rate on the row and no configured rate for the contractor'];
        }

        $status = InvoiceStatus::tryFrom(strtolower(trim((string) ($row['status'] ?? ''))))
            ?? InvoiceStatus::Paid;

        return [[
            'profile' => $profile,
            'invoice_number' => $number,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'invoice_date' => $invoiceDate,
            'hours' => $hours,
            'rate' => $rate,
            'explicit_total' => is_numeric($row['total'] ?? null) ? (float) $row['total'] : null,
            'status' => $status,
            'description' => filled($row['description'] ?? null) ? trim((string) $row['description']) : null,
        ], null];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(Date::excelToDateTimeObject($value));
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return filled($value) ? Carbon::parse((string) $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
