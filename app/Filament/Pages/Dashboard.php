<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ContractorEarningsChart;
use App\Filament\Widgets\EarningsChart;
use App\Filament\Widgets\InvoiceStats;
use App\Filament\Widgets\RecentInvoices;
use App\Filament\Widgets\StatusBreakdownChart;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Overview';

    public function getWidgets(): array
    {
        return [
            InvoiceStats::class,
            EarningsChart::class,
            StatusBreakdownChart::class,
            ContractorEarningsChart::class,
            RecentInvoices::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['md' => 3];
    }
}
