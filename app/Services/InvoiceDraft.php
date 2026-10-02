<?php

namespace App\Services;

use App\Enums\InvoiceSource;
use Carbon\CarbonImmutable;

/**
 * A fully computed, not-yet-saved invoice. What the preview shows is
 * exactly what gets persisted — no recomputation between the two.
 */
final class InvoiceDraft
{
    /**
     * @param  array<int, array{description: string, hours: ?float, rate: float, amount: float}>  $lines
     */
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly CarbonImmutable $invoiceDate,
        public readonly array $lines,
        public readonly float $taxRate,
        public readonly InvoiceSource $source,
        public readonly ?array $sourcePayload = null,
    ) {}

    /** Same draft with a user-chosen number instead of the suggested one. */
    public function withNumber(string $number): self
    {
        return new self(
            trim($number), $this->periodStart, $this->periodEnd, $this->invoiceDate,
            $this->lines, $this->taxRate, $this->source, $this->sourcePayload,
        );
    }

    public function hours(): float
    {
        return round(array_sum(array_map(fn ($l) => (float) ($l['hours'] ?? 0), $this->lines)), 2);
    }

    public function subtotal(): float
    {
        return $this->subtotalCents() / 100;
    }

    public function tax(): float
    {
        return $this->taxCents() / 100;
    }

    public function total(): float
    {
        return ($this->subtotalCents() + $this->taxCents()) / 100;
    }

    private function subtotalCents(): int
    {
        return (int) array_sum(array_map(fn ($l) => (int) round($l['amount'] * 100), $this->lines));
    }

    private function taxCents(): int
    {
        return (int) round($this->subtotalCents() * $this->taxRate);
    }

    /** Serialize for Livewire state between preview and confirm. */
    public function toArray(): array
    {
        return [
            'invoice_number' => $this->invoiceNumber,
            'period_start' => $this->periodStart->toDateString(),
            'period_end' => $this->periodEnd->toDateString(),
            'invoice_date' => $this->invoiceDate->toDateString(),
            'lines' => $this->lines,
            'tax_rate' => $this->taxRate,
            'source' => $this->source->value,
            'source_payload' => $this->sourcePayload,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['invoice_number'],
            CarbonImmutable::parse($data['period_start']),
            CarbonImmutable::parse($data['period_end']),
            CarbonImmutable::parse($data['invoice_date']),
            $data['lines'],
            (float) $data['tax_rate'],
            InvoiceSource::from($data['source']),
            $data['source_payload'] ?? null,
        );
    }
}
