<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Filament\Widgets\Concerns\ScopesInvoicesToUser;
use Filament\Widgets\ChartWidget;

class StatusBreakdownChart extends ChartWidget
{
    use ScopesInvoicesToUser;

    protected static ?int $sort = 2;

    protected ?string $heading = 'By status';

    protected ?string $description = 'Total value, all time';

    protected ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $totals = $this->scopedInvoices()
            ->selectRaw('status, SUM(total) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = [InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Paid, InvoiceStatus::Imported];
        $colors = ['#cbd5e1', '#f97316', '#22c55e', '#38bdf8'];

        return [
            'datasets' => [[
                'data' => array_map(fn ($s) => round((float) ($totals[$s->value] ?? 0), 2), $statuses),
                'backgroundColor' => $colors,
                'borderWidth' => 0,
                'hoverOffset' => 6,
            ]],
            'labels' => array_map(fn ($s) => $s->getLabel(), $statuses),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '68%',
            'plugins' => [
                'legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 10, 'usePointStyle' => true]],
            ],
        ];
    }
}
