<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Filament\Widgets\Concerns\ScopesInvoicesToUser;
use App\Models\InvoiceLine;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InvoiceStats extends StatsOverviewWidget
{
    use ScopesInvoicesToUser;

    protected static ?int $sort = 0;

    // Headline numbers should be in the first paint, not a second request.
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $year = now()->year;
        $base = $this->scopedInvoices();

        $outstanding = (float) (clone $base)->where('status', InvoiceStatus::Sent)->sum('total');
        $outstandingCount = (clone $base)->where('status', InvoiceStatus::Sent)->count();

        $paidYtd = (float) (clone $base)
            ->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Imported])
            ->whereYear('invoice_date', $year)
            ->sum('total');

        $invoicedYtd = (float) (clone $base)
            ->where('status', '!=', InvoiceStatus::Draft)
            ->whereYear('invoice_date', $year)
            ->sum('subtotal');

        $hoursYtd = (float) InvoiceLine::query()
            ->whereIn('invoice_id', (clone $base)
                ->where('status', '!=', InvoiceStatus::Draft)
                ->whereYear('invoice_date', $year)
                ->select('id'))
            ->sum('hours');

        $countYtd = (clone $base)
            ->where('status', '!=', InvoiceStatus::Draft)
            ->whereYear('invoice_date', $year)
            ->count();

        return [
            Stat::make('Outstanding', $this->money($outstanding))
                ->description($outstandingCount.' invoice'.($outstandingCount === 1 ? '' : 's').' awaiting payment')
                ->descriptionIcon('heroicon-m-clock')
                ->color($outstanding > 0 ? 'warning' : 'gray'),

            Stat::make("Paid in {$year}", $this->money($paidYtd))
                ->description('Received this year')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make("Invoiced in {$year}", $this->money($invoicedYtd))
                ->description("{$countYtd} invoices · before tax")
                ->descriptionIcon('heroicon-m-document-text'),

            Stat::make("Hours in {$year}", number_format($hoursYtd, 1))
                ->description($countYtd > 0
                    ? 'Avg '.number_format($hoursYtd / $countYtd, 1).' h per invoice'
                    : 'No invoices yet')
                ->descriptionIcon('heroicon-m-clock'),
        ];
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }
}
