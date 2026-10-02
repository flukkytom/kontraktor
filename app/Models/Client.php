<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['owner_user_id', 'name', 'street', 'city', 'region', 'postal_code', 'country', 'contact_email'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<ContractorProfile, $this> */
    public function contractorProfiles(): HasMany
    {
        return $this->hasMany(ContractorProfile::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Constrain a query to clients a user may bill: shared ones plus their
     * own. Admins see all. Works on any builder (incl. relation queries).
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function visibleTo(Builder $query, ?User $user): Builder
    {
        $query->orderBy('clients.name');

        if ($user?->isAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull('clients.owner_user_id')
            ->when($user, fn ($q) => $q->orWhere('clients.owner_user_id', $user->id)));
    }

    public function isShared(): bool
    {
        return $this->owner_user_id === null;
    }

    public function isEditableBy(?User $user): bool
    {
        return (bool) ($user?->isAdmin() || ($user && $this->owner_user_id === $user->id));
    }

    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->street,
            collect([$this->city, $this->region, $this->postal_code])->filter()->implode(' '),
            $this->country,
        ]));
    }
}
