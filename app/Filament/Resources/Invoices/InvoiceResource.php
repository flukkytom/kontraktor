<?php

namespace App\Filament\Resources\Invoices;

use App\Enums\InvoiceStatus;
use App\Exports\InvoiceSheetExport;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoicePdf;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;
use UnitEnum;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['contractorProfile.user', 'client'])
            ->withSum('lines as hours', 'hours');

        $user = auth()->user();

        if (! $user?->isAdmin()) {
            $query->whereHas(
                'contractorProfile',
                fn (Builder $q) => $q->where('user_id', $user->id)
            );
        }

        return $query->latest('invoice_date');
    }

    /**
     * Contractors may delete their own drafts; anything that has been
     * sent is part of the record and only an admin can remove it.
     */
    public static function canDeleteInvoice(Invoice $invoice): bool
    {
        $user = auth()->user();

        if ($user?->isAdmin()) {
            return true;
        }

        return $invoice->status === InvoiceStatus::Draft
            && $invoice->contractorProfile?->user_id === $user?->id;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canDeleteInvoice($record);
    }

    /**
     * Page-level gate for the edit URL itself — hiding the Edit table
     * action alone doesn't stop a direct visit to /invoices/{id}/edit.
     * Once sent, an invoice is edited only through status actions.
     */
    public static function canEdit(Model $record): bool
    {
        return $record->status === InvoiceStatus::Draft;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')->schema([
                Grid::make(3)->schema([
                    Select::make('client_id')
                        ->relationship('client', 'name', fn (Builder $query) => Client::visibleTo($query, auth()->user()))
                        ->required(),
                    TextInput::make('invoice_number')->required(),
                    Select::make('status')
                        ->options(InvoiceStatus::class)
                        // Admin-only: contractors transition via the
                        // Mark sent button, so they can't self-mark paid.
                        ->visible(fn () => auth()->user()?->isAdmin())
                        ->required(),
                ]),
                Grid::make(3)->schema([
                    DatePicker::make('period_start')->required(),
                    DatePicker::make('period_end')->required(),
                    DatePicker::make('invoice_date')->required(),
                ]),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                Repeater::make('lines')
                    ->relationship()
                    ->schema([
                        TextInput::make('description')->required()->columnSpan(2),
                        // type('text') + inputmode(decimal) instead of type=number: browsers
                        // can wedge a number input into badInput (a mid-edit keystroke or a
                        // locale comma) and then block submit with "Please enter a number"
                        // even though the field looks fine. Validation stays server-side.
                        TextInput::make('hours')
                            ->numeric()
                            ->type('text')
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateLineAmount($set, $get)),
                        TextInput::make('rate')
                            ->numeric()
                            ->type('text')
                            ->minValue(0)
                            ->prefix('$')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateLineAmount($set, $get)),
                        TextInput::make('amount')
                            ->numeric()
                            ->type('text')
                            ->prefix('$')
                            ->required()
                            ->helperText('Auto-calculated from hours × rate; override if needed.')
                            ->afterStateHydrated(function (Set $set, Get $get) {
                                if (blank($get('amount'))) {
                                    static::recalculateLineAmount($set, $get);
                                }
                            }),
                    ])
                    ->columns(5)
                    ->orderColumn('sort_order')
                    ->addActionLabel('Add line'),
            ]),
        ]);
    }

    /**
     * amount = hours × rate whenever both are present. Called on hydrate
     * (for lines with no amount) and whenever hours or rate change.
     */
    protected static function recalculateLineAmount(Set $set, Get $get): void
    {
        if (filled($get('hours')) && filled($get('rate'))) {
            $set('amount', round((float) $get('hours') * (float) $get('rate'), 2));
        }
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Grid::make(4)->schema([
                    TextEntry::make('invoice_number')->label('Invoice #'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('invoice_date')->date(),
                    TextEntry::make('source')->badge(),
                ]),
                Grid::make(3)->schema([
                    TextEntry::make('contractorProfile.billing_name')->label('Contractor'),
                    TextEntry::make('client.name'),
                    TextEntry::make('period')
                        ->state(fn (Invoice $r) => $r->period_start->format('M j').' – '.$r->period_end->format('M j, Y')),
                ]),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')
                    ->schema([
                        TextEntry::make('description')->columnSpan(2),
                        TextEntry::make('hours')->placeholder('—'),
                        TextEntry::make('rate')->money('CAD')->placeholder('—'),
                        TextEntry::make('amount')->money('CAD'),
                    ])
                    ->columns(5)
                    ->contained(false),
                Grid::make(3)->schema([
                    TextEntry::make('subtotal')->money('CAD'),
                    TextEntry::make('tax_amount')
                        ->label(fn (Invoice $r) => 'Tax ('.((float) $r->tax_rate * 100).'%)')
                        ->money('CAD'),
                    TextEntry::make('total')->money('CAD')->weight('bold'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contractorProfile.billing_name')
                    ->label('Contractor')
                    ->searchable()
                    ->sortable()
                    ->visible(fn () => auth()->user()?->isAdmin()),
                TextColumn::make('client.name')->sortable(),
                TextColumn::make('period')
                    ->state(fn (Invoice $r) => $r->period_start->format('M j').' – '.$r->period_end->format('M j, Y')),
                TextColumn::make('invoice_date')->date()->sortable(),
                TextColumn::make('hours')
                    ->numeric(decimalPlaces: 1)
                    ->sortable()
                    ->alignEnd()
                    ->summarize(Sum::make()->label('')->numeric(decimalPlaces: 1)),
                TextColumn::make('total')
                    ->money('CAD')
                    ->sortable()
                    ->alignEnd()
                    ->summarize(Sum::make()->label('')->money('CAD')),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('source')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(InvoiceStatus::class)->multiple(),
                SelectFilter::make('contractor_profile_id')
                    ->label('Contractor')
                    ->relationship('contractorProfile', 'billing_name')
                    ->visible(fn () => auth()->user()?->isAdmin()),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('Period from'),
                        DatePicker::make('until')->label('Period until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('period_start', '>=', $v))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('period_end', '<=', $v))),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->label('PDF')
                    ->action(fn (Invoice $record) => app(InvoicePdf::class)->download($record)),
                Action::make('download_xlsx')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->label('Excel')
                    ->color('gray')
                    ->action(fn (Invoice $record) => Excel::download(
                        new InvoiceSheetExport($record),
                        InvoicePdf::filename($record, 'xlsx'),
                    )),
                Action::make('mark_sent')
                    ->label('Mark sent')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('warning')
                    ->visible(fn (Invoice $r) => $r->status === InvoiceStatus::Draft)
                    ->action(fn (Invoice $r) => $r->update(['status' => InvoiceStatus::Sent])),
                Action::make('mark_paid')
                    ->label('Mark paid')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Invoice $r) => $r->status === InvoiceStatus::Sent)
                    ->action(fn (Invoice $r) => $r->update(['status' => InvoiceStatus::Paid])),
                ActionGroup::make([
                    ViewAction::make(),
                    Action::make('mark_draft')
                        ->label('Back to draft')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->visible(fn (Invoice $r) => $r->status === InvoiceStatus::Sent)
                        ->action(fn (Invoice $r) => $r->update(['status' => InvoiceStatus::Draft])),
                    Action::make('mark_unpaid')
                        ->label('Back to sent')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->visible(fn (Invoice $r) => $r->status === InvoiceStatus::Paid)
                        ->action(fn (Invoice $r) => $r->update(['status' => InvoiceStatus::Sent])),
                    EditAction::make()
                        ->visible(fn (Invoice $r) => $r->status === InvoiceStatus::Draft),
                    DeleteAction::make()
                        ->visible(fn (Invoice $r) => static::canDeleteInvoice($r))
                        ->modalHeading(fn (Invoice $r) => "Delete invoice {$r->invoice_number}?")
                        ->modalDescription(fn (Invoice $r) => $r->status === InvoiceStatus::Draft
                            ? 'This draft has not been sent. Its number will be reused by the next invoice this month.'
                            : 'This invoice has already been sent or paid. Deleting it removes it from history and reports.'),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isAdmin()),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }
}
