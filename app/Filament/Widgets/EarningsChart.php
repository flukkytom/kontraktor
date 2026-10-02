<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Filament\Widgets\Concerns\ScopesInvoicesToUser;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

class EarningsChart extends ChartWidget
{
    use ScopesInvoicesToUser;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 2;

    protected ?string $heading = 'Earnings by month';

    protected ?string $description = 'Invoiced subtotal, before tax';

    protected ?string $maxHeight = '280px';

    public ?string $filter = '12';

    protected function getFilters(): ?array
    {
        return [
            '6' => 'Last 6 months',
            '12' => 'Last 12 months',
            '24' => 'Last 24 months',
        ];
    }

    protected function getData(): array
    {
        $months = (int) $this->filter;
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);

        // Group in PHP rather than SQL so this works on SQLite and MySQL alike.
        $rows = $this->scopedInvoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->where('invoice_date', '>=', $start)
            ->get(['invoice_date', 'subtotal'])
            ->groupBy(fn ($inv) => $inv->invoice_date->format('Y-m'))
            ->map(fn ($group) => $group->sum(fn ($inv) => (float) $inv->subtotal));

        $labels = [];
        $values = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $labels[] = $month->format($months > 12 ? 'M \'y' : 'M');
            $values[] = round((float) ($rows[$month->format('Y-m')] ?? 0), 2);
        }

        return [
            'datasets' => [[
                'label' => 'Invoiced',
                'data' => $values,
                'backgroundColor' => 'rgba(249, 115, 22, 0.85)',
                'borderColor' => '#f97316',
                'borderRadius' => 6,
                'maxBarThickness' => 40,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'grid' => ['color' => 'rgba(15, 23, 42, 0.05)'],
                    'ticks' => ['callback' => null],
                ],
                'x' => ['grid' => ['display' => false]],
            ],
        ];
    }
}
