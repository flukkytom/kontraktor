<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function afterSave(): void
    {
        $this->record->recalculateTotals();
        $this->record->save();
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->record]);
    }
}
