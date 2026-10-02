<?php

namespace App\Models;

use Database\Factories\RateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['contractor_profile_id', 'hourly_rate', 'effective_from'])]
class Rate extends Model
{
    /** @use HasFactory<RateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<ContractorProfile, $this> */
    public function contractorProfile(): BelongsTo
    {
        return $this->belongsTo(ContractorProfile::class);
    }
}
