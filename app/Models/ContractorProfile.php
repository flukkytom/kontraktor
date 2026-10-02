<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ContractorProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'client_id', 'billing_name', 'street', 'city', 'region',
    'postal_code', 'country', 'phone', 'tax_number', 'tax_rate',
    'payment_terms', 'invoice_number_pattern',
    'harvest_access_token', 'harvest_account_id',
])]
class ContractorProfile extends Model
{
    /** @use HasFactory<ContractorProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:4',
            'harvest_access_token' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<Rate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(Rate::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The hourly rate in effect on the given date, falling back to the
     * earliest rate when the date predates all configured rates.
     */
    public function rateFor(CarbonInterface $date): ?Rate
    {
        return $this->rates()
            ->where('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->first()
            ?? $this->rates()->orderBy('effective_from')->first();
    }

    public function hasHarvestCredentials(): bool
    {
        return filled($this->harvest_access_token) && filled($this->harvest_account_id);
    }

    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->street,
            collect([$this->city, $this->region, $this->postal_code])->filter()->implode(' '),
            $this->country,
            $this->phone,
        ]));
    }
}
