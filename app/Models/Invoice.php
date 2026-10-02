<?php

namespace App\Models;

use App\Enums\InvoiceSource;
use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'contractor_profile_id', 'client_id', 'invoice_number',
    'period_start', 'period_end', 'invoice_date', 'status', 'source',
    'subtotal', 'tax_rate', 'tax_amount', 'total', 'currency',
    'source_payload', 'notes', 'pdf_path',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'invoice_date' => 'date',
            'status' => InvoiceStatus::class,
            'source' => InvoiceSource::class,
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'source_payload' => 'array',
        ];
    }

    /** @return BelongsTo<ContractorProfile, $this> */
    public function contractorProfile(): BelongsTo
    {
        return $this->belongsTo(ContractorProfile::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    /**
     * Recompute money columns from the invoice's lines. Uses cents to
     * avoid float drift; tax rounds half-up per the invoice tax rate.
     */
    public function recalculateTotals(): void
    {
        $this->loadMissing('lines');

        $subtotalCents = $this->lines->sum(
            fn (InvoiceLine $line) => (int) round(((float) $line->amount) * 100)
        );

        $this->subtotal = number_format($subtotalCents / 100, 2, '.', '');
        $this->tax_amount = number_format(round($subtotalCents * (float) $this->tax_rate) / 100, 2, '.', '');
        $this->total = number_format(($subtotalCents + (int) round($subtotalCents * (float) $this->tax_rate)) / 100, 2, '.', '');
    }
}
