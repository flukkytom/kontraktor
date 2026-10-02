<?php

namespace App\Filament\Pages;

use App\Imports\InvoiceBackfillImport;
use App\Services\InvoiceBackfiller;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use UnitEnum;

class ImportData extends Page
{
    protected string $view = 'filament.pages.import-data';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing';

    protected static ?string $navigationLabel = 'Backfill Import';

    protected static ?string $title = 'Backfill Historical Invoices';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Import past invoices')->schema([
                    FileUpload::make('file')
                        ->label('Backfill file')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                        ])
                        ->required()
                        ->helperText('Columns: contractor_email, invoice_number, period_start, period_end, invoice_date, hours, rate, total (optional), status (optional).'),
                ]),
            ]);
    }

    public function submit(InvoiceBackfiller $backfiller): void
    {
        $path = $this->form->getState()['file'];

        $parsed = Excel::toCollection(
            new InvoiceBackfillImport,
            Storage::path($path)
        )->first();

        ['created' => $created, 'errors' => $errors] = $backfiller->import($parsed);

        if ($created > 0) {
            Notification::make()->success()
                ->title("{$created} invoice(s) imported")
                ->send();
        }

        if ($errors !== []) {
            Notification::make()->warning()
                ->title(count($errors).' row(s) skipped')
                ->body(implode("\n", array_slice($errors, 0, 8)))
                ->persistent()
                ->send();
        }

        if ($created === 0 && $errors === []) {
            Notification::make()->warning()->title('Nothing to import.')->send();
        }
    }
}
