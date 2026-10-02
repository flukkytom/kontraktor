<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected static ?string $title = 'Invoices';

    public function getTabs(): array
    {
        $base = fn () => InvoiceResource::getEloquentQuery();

        return [
            'all' => Tab::make('All')
                ->badge(fn () => $base()->count()),

            'outstanding' => Tab::make('Outstanding')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceStatus::Sent))
                ->badge(fn () => $base()->where('status', InvoiceStatus::Sent)->count())
                ->badgeColor('warning'),

            'drafts' => Tab::make('Drafts')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceStatus::Draft))
                ->badge(fn () => $base()->where('status', InvoiceStatus::Draft)->count() ?: null),

            'this_month' => Tab::make('This month')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereYear('invoice_date', now()->year)
                    ->whereMonth('invoice_date', now()->month)),

            'last_month' => Tab::make('Last month')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereYear('invoice_date', now()->subMonth()->year)
                    ->whereMonth('invoice_date', now()->subMonth()->month)),

            'this_year' => Tab::make('This year')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereYear('invoice_date', now()->year)),

            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Imported])),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->action(fn () => $this->exportCsv()),
        ];
    }

    private function exportCsv()
    {
        /** @var Collection<int, Invoice> $invoices */
        $invoices = $this->getFilteredTableQuery()->get();

        return response()->streamDownload(function () use ($invoices) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Invoice #', 'Contractor', 'Client', 'Period Start', 'Period End', 'Invoice Date', 'Hours', 'Subtotal', 'Tax', 'Total', 'Currency', 'Status']);

            foreach ($invoices as $invoice) {
                fputcsv($out, [
                    $invoice->invoice_number,
                    $invoice->contractorProfile->billing_name,
                    $invoice->client->name,
                    $invoice->period_start->toDateString(),
                    $invoice->period_end->toDateString(),
                    $invoice->invoice_date->toDateString(),
                    $invoice->hours ?? $invoice->lines->sum('hours'),
                    $invoice->subtotal,
                    $invoice->tax_amount,
                    $invoice->total,
                    $invoice->currency,
                    $invoice->status->value,
                ]);
            }

            fclose($out);
        }, 'invoices-'.now()->format('Y-m-d').'.csv');
    }
}
