<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Widgets\Concerns\ScopesInvoicesToUser;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentInvoices extends TableWidget
{
    use ScopesInvoicesToUser;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent invoices')
            ->description('Last 8 invoices · full history under Invoices')
            ->query(fn () => $this->scopedInvoices()
                ->with(['contractorProfile', 'client'])
                ->latest('invoice_date')
                ->latest('id')
                ->limit(8))
            ->paginated(false)
            ->columns([
                TextColumn::make('invoice_number')->label('Invoice #')->weight('semibold'),
                TextColumn::make('contractorProfile.billing_name')
                    ->label('Contractor')
                    ->visible(fn () => $this->isAdmin()),
                TextColumn::make('period')
                    ->state(fn (Invoice $r) => $r->period_start->format('M j').' – '.$r->period_end->format('M j, Y')),
                TextColumn::make('invoice_date')->date('M j, Y'),
                TextColumn::make('total')->money('CAD')->alignEnd(),
                TextColumn::make('status')->badge(),
            ])
            ->recordUrl(fn (Invoice $r) => InvoiceResource::getUrl('view', ['record' => $r]))
            ->headerActions([
                Action::make('all')
                    ->label('View all')
                    ->link()
                    ->url(InvoiceResource::getUrl('index')),
            ])
            ->emptyStateHeading('No invoices yet')
            ->emptyStateDescription('Generate your first one from New Invoice.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
