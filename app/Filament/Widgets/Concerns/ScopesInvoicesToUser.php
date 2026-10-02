<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admins see every invoice; contractors see only their own.
 */
trait ScopesInvoicesToUser
{
    /** @return Builder<Invoice> */
    protected function scopedInvoices(): Builder
    {
        $query = Invoice::query();
        $user = auth()->user();

        if (! $user?->isAdmin()) {
            $query->whereHas(
                'contractorProfile',
                fn (Builder $q) => $q->where('user_id', $user->id)
            );
        }

        return $query;
    }

    protected function isAdmin(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }
}
