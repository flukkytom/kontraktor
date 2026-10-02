<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Billing entities. Admin-created clients are shared with everyone;
 * contractor-created ones are private to that contractor.
 */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Clients';

    public static function getEloquentQuery(): Builder
    {
        return Client::visibleTo(parent::getEloquentQuery(), auth()->user())
            ->withCount(['contractorProfiles', 'invoices']);
    }

    public static function canEdit(Model $record): bool
    {
        return $record->isEditableBy(auth()->user());
    }

    public static function canDelete(Model $record): bool
    {
        return $record->isEditableBy(auth()->user())
            && $record->invoices_count === 0;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('contact_email')->email()->maxLength(255),
                TextInput::make('street')->maxLength(255),
                TextInput::make('city')->maxLength(100),
                TextInput::make('region')->label('Province / State')->maxLength(100),
                TextInput::make('postal_code')->maxLength(20),
                TextInput::make('country')->default('Canada')->maxLength(100),
                Toggle::make('is_shared')
                    ->label('Shared with all contractors')
                    ->helperText('Off = only you can bill this client.')
                    ->default(true)
                    ->visible(fn () => auth()->user()?->isAdmin())
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (Toggle $component, ?Client $record) => $component->state($record?->isShared() ?? true)),
            ])->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('name'),
                TextEntry::make('contact_email')->placeholder('—'),
                TextEntry::make('address')
                    ->label('Address')
                    ->state(fn (Client $r) => implode("\n", $r->addressLines()))
                    ->placeholder('—'),
                TextEntry::make('shared')
                    ->label('Visibility')
                    ->badge()
                    ->state(fn (Client $r) => $r->isShared() ? 'Shared with all contractors' : 'Private')
                    ->color(fn (Client $r) => $r->isShared() ? 'gray' : 'warning'),
                TextEntry::make('owner.name')
                    ->label('Added by')
                    ->placeholder('Example Corp (shared)')
                    ->visible(fn () => auth()->user()?->isAdmin()),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('shared')
                    ->label('')
                    ->state(fn (Client $r) => $r->isShared())
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedGlobeAlt)
                    ->falseIcon(Heroicon::OutlinedLockClosed)
                    ->trueColor('gray')
                    ->falseColor('gray')
                    ->tooltip(fn (Client $r) => $r->isShared() ? 'Shared' : 'Private to you'),
                TextColumn::make('contact_email')->placeholder('—'),
                TextColumn::make('city')->placeholder('—'),
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->placeholder('Shared')
                    ->visible(fn () => auth()->user()?->isAdmin()),
                TextColumn::make('contractor_profiles_count')
                    ->label('Contractors')
                    ->visible(fn () => auth()->user()?->isAdmin()),
                TextColumn::make('invoices_count')->label('Invoices'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    // Table actions don't auto-authorize — gate visibility
                    // explicitly or shared clients show an edit link that 403s.
                    ->visible(fn (Client $r) => $r->isEditableBy(auth()->user())),
                DeleteAction::make()
                    ->visible(fn (Client $r) => static::canDelete($r))
                    ->modalDescription('Only clients with no invoices can be deleted.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isAdmin()),
                ]),
            ])
            ->emptyStateHeading('No clients yet')
            ->emptyStateDescription('Add the company you invoice.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
