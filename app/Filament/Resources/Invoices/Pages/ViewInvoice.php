<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Exports\InvoiceSheetExport;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\InvoicePdf;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => app(InvoicePdf::class)->download($this->record)),
            Action::make('download_xlsx')
                ->label('Download Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->action(fn () => Excel::download(
                    new InvoiceSheetExport($this->record),
                    InvoicePdf::filename($this->record, 'xlsx'),
                )),
            Action::make('mark_sent')
                ->label('Mark sent')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('warning')
                ->visible(fn () => $this->record->status === InvoiceStatus::Draft)
                ->action(fn () => $this->record->update(['status' => InvoiceStatus::Sent])),
            Action::make('mark_paid')
                ->label('Mark paid')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn () => $this->record->status === InvoiceStatus::Sent)
                ->action(fn () => $this->record->update(['status' => InvoiceStatus::Paid])),
            Action::make('mark_draft')
                ->label('Back to draft')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn () => $this->record->status === InvoiceStatus::Sent)
                ->action(fn () => $this->record->update(['status' => InvoiceStatus::Draft])),
            Action::make('mark_unpaid')
                ->label('Back to sent')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn () => $this->record->status === InvoiceStatus::Paid)
                ->action(fn () => $this->record->update(['status' => InvoiceStatus::Sent])),
            EditAction::make()
                ->visible(fn () => $this->record->status === InvoiceStatus::Draft),
            DeleteAction::make()
                ->visible(fn () => InvoiceResource::canDeleteInvoice($this->record))
                ->modalHeading("Delete invoice {$this->record->invoice_number}?")
                ->modalDescription($this->record->status === InvoiceStatus::Draft
                    ? 'This draft has not been sent. Its number will be reused by the next invoice this month.'
                    : 'This invoice has already been sent or paid. Deleting it removes it from history and reports.')
                ->successRedirectUrl(InvoiceResource::getUrl('index')),
        ];
    }
}
