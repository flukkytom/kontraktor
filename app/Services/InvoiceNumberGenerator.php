<?php

namespace App\Services;

use App\Models\ContractorProfile;
use App\Models\Invoice;
use Carbon\CarbonInterface;

/**
 * Renders per-contractor invoice numbers from a pattern.
 *
 * Supported tokens: {MM} {M} {YYYY} {YY} {seq}
 * {seq} is a per-contractor sequence scoped to the invoice's calendar
 * month — so "09-1", "09-2" are the 1st/2nd invoices dated in September.
 */
class InvoiceNumberGenerator
{
    public function generate(ContractorProfile $profile, CarbonInterface $invoiceDate): string
    {
        $sequence = Invoice::query()
            ->where('contractor_profile_id', $profile->id)
            ->whereYear('invoice_date', $invoiceDate->year)
            ->whereMonth('invoice_date', $invoiceDate->month)
            ->count() + 1;

        return strtr($profile->invoice_number_pattern, [
            '{MM}' => $invoiceDate->format('m'),
            '{M}' => $invoiceDate->format('n'),
            '{YYYY}' => $invoiceDate->format('Y'),
            '{YY}' => $invoiceDate->format('y'),
            '{seq}' => (string) $sequence,
        ]);
    }
}
