<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Widgets\ChartWidget;

/**
 * Admin-only: who has invoiced how much this year.
 */
class ContractorEarningsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'By contractor';

    protected ?string $maxHeight = '300px';

    public ?string $filter = 'ytd';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    protected function getFilters(): ?array
    {
        return [
            'ytd' => 'This year',
            'all' => 'All time',
        ];
    }

    protected function getData(): array
    {
        $rows = Invoice::query()
            ->join('contractor_profiles', 'contractor_profiles.id', '=', 'invoices.contractor_profile_id')
            ->where('invoices.status', '!=', InvoiceStatus::Draft)
            ->when($this->filter === 'ytd', fn ($q) => $q->whereYear('invoices.invoice_date', now()->year))
            ->selectRaw('contractor_profiles.billing_name as name,
                SUM(CASE WHEN invoices.status IN (?, ?) THEN invoices.total ELSE 0 END) as paid,
                SUM(CASE WHEN invoices.status = ? THEN invoices.total ELSE 0 END) as outstanding',
                [InvoiceStatus::Paid->value, InvoiceStatus::Imported->value, InvoiceStatus::Sent->value])
            ->groupBy('contractor_profiles.id', 'contractor_profiles.billing_name')
            ->orderByRaw('paid + outstanding DESC')
            ->limit(12)
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Paid',
                    'data' => $rows->map(fn ($r) => round((float) $r->paid, 2))->all(),
                    'backgroundColor' => '#22c55e',
                    'borderRadius' => 4,
                ],
                [
                    'label' => 'Outstanding',
                    'data' => $rows->map(fn ($r) => round((float) $r->outstanding, 2))->all(),
                    'backgroundColor' => '#f97316',
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $rows->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 10, 'usePointStyle' => true]]],
            'scales' => [
                'x' => ['stacked' => true, 'beginAtZero' => true, 'grid' => ['color' => 'rgba(15, 23, 42, 0.05)']],
                'y' => ['stacked' => true, 'grid' => ['display' => false]],
            ],
        ];
    }
}
