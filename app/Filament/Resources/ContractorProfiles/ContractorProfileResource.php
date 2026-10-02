<?php

namespace App\Filament\Resources\ContractorProfiles;

use App\Enums\UserRole;
use App\Filament\Resources\ContractorProfiles\Pages\CreateContractorProfile;
use App\Filament\Resources\ContractorProfiles\Pages\EditContractorProfile;
use App\Filament\Resources\ContractorProfiles\Pages\ListContractorProfiles;
use App\Models\Client;
use App\Models\ContractorProfile;
use App\Models\User;
use App\Services\HarvestClient;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use UnitEnum;

class ContractorProfileResource extends Resource
{
    protected static ?string $model = ContractorProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'My Profile';

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->isAdmin() ? 'Contractors' : 'My Profile';
    }

    /**
     * Contractors have exactly one profile, so their nav link goes
     * straight to it (or to create, the first time) — never to a list.
     */
    public static function getNavigationUrl(): string
    {
        $user = auth()->user();

        if ($user?->isAdmin()) {
            return static::getUrl('index');
        }

        return $user?->contractorProfile
            ? static::getUrl('edit', ['record' => $user->contractorProfile])
            : static::getUrl('create');
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        // Admins always; contractors only until they have a profile.
        return $user?->isAdmin() || $user?->contractorProfile === null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'client', 'rates']);

        if (! auth()->user()?->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Billing identity')->schema([
                Select::make('user_id')
                    ->relationship('user', 'name', fn (Builder $query, ?ContractorProfile $record) => $query
                        ->where('role', UserRole::Contractor)
                        ->where(fn (Builder $q) => $q
                            ->whereDoesntHave('contractorProfile')
                            ->when($record?->user_id, fn ($q, $id) => $q->orWhere('id', $id))))
                    ->label('Contractor account')
                    ->helperText('Contractor logins that don’t have a profile yet — or create one here.')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->visible(fn () => auth()->user()?->isAdmin())
                    ->disabledOn('edit')
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('email')->email()->required()->unique('users', 'email'),
                        TextInput::make('password')->password()->revealable()->required()->minLength(8),
                    ])
                    ->createOptionUsing(fn (array $data) => User::create([
                        ...$data,
                        'role' => UserRole::Contractor,
                    ])->getKey())
                    ->createOptionModalHeading('New contractor login'),
                Select::make('client_id')
                    ->relationship('client', 'name', fn (Builder $query) => Client::visibleTo($query, auth()->user()))
                    ->label('Bills to')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('contact_email')->email()->maxLength(255),
                        TextInput::make('street')->maxLength(255),
                        TextInput::make('city')->maxLength(100),
                        TextInput::make('region')->label('Province / State')->maxLength(100),
                        TextInput::make('postal_code')->maxLength(20),
                        TextInput::make('country')->default('Canada')->maxLength(100),
                    ])
                    ->createOptionUsing(function (array $data) {
                        // Inline-created clients are private to the creator unless they're admin.
                        $data['owner_user_id'] = auth()->user()->isAdmin() ? null : auth()->id();

                        return Client::create($data)->getKey();
                    })
                    ->createOptionModalHeading('New client')
                    ->helperText('The company you invoice. Add a new one if it is not listed.'),
                TextInput::make('billing_name')->required()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(30),
                TextInput::make('street')->maxLength(255),
                TextInput::make('city')->maxLength(100),
                TextInput::make('region')->label('Province / State')->maxLength(100),
                TextInput::make('postal_code')->maxLength(20),
                TextInput::make('country')->default('Canada')->maxLength(100),
            ])->columns(2),

            Section::make('Invoicing')->schema([
                TextInput::make('tax_number')->label('GST/HST number')->maxLength(50),
                TextInput::make('tax_rate')
                    ->label('Tax rate')
                    ->numeric()
                    ->type('text')
                    ->minValue(0)
                    ->maxValue(1)
                    ->step(0.0001)
                    ->default(0.05)
                    ->helperText('Enter as a decimal — 0.05 is 5% GST.')
                    ->required(),
                TextInput::make('payment_terms')->default('Net 15 days')->required(),
                TextInput::make('invoice_number_pattern')
                    ->default('{MM}-{seq}')
                    ->helperText('Tokens: {MM} {M} {YYYY} {YY} {seq} — seq counts within the month.')
                    ->required(),
            ])->columns(2),

            Section::make('Rates')->schema([
                Repeater::make('rates')
                    ->relationship()
                    ->schema([
                        TextInput::make('hourly_rate')->numeric()->type('text')->minValue(0)->prefix('$')->required(),
                        DatePicker::make('effective_from')->required()->default(now()),
                    ])
                    ->columns(2)
                    ->defaultItems(1)
                    ->addActionLabel('Add rate'),
            ]),

            Section::make('Harvest')
                // Personal access tokens belong to the contractor — admins never see this section.
                ->visible(fn () => ! auth()->user()?->isAdmin())
                ->description('Both fields come from Harvest → Settings → Developer Tools → Personal Access Tokens. The connection is verified when you save.')
                ->schema([
                    TextInput::make('harvest_access_token')
                        ->label('Personal access token')
                        ->password()
                        ->revealable()
                        ->requiredWith('harvest_account_id')
                        ->rules([
                            fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                $accountId = $get('harvest_account_id');

                                if (blank($value) || blank($accountId)) {
                                    return;
                                }

                                try {
                                    (new HarvestClient($value, $accountId))->me();
                                } catch (\Throwable $e) {
                                    $fail('Harvest rejected this token / account ID. '.static::harvestErrorHint($e));
                                }
                            },
                        ])
                        ->validationAttribute('Harvest token'),
                    TextInput::make('harvest_account_id')
                        ->label('Account ID')
                        ->numeric()
                        ->type('text')
                        ->maxLength(20)
                        ->requiredWith('harvest_access_token')
                        ->helperText('Shown beside the token when you create it.'),
                ])->columns(2)->collapsible(),
        ]);
    }

    /**
     * Turn a Harvest HTTP failure into something a person can act on.
     */
    protected static function harvestErrorHint(\Throwable $e): string
    {
        $status = $e instanceof RequestException ? $e->response->status() : null;

        return match ($status) {
            401 => 'The token is wrong or has been revoked.',
            403 => 'The token is valid but not for this account ID.',
            404 => 'Check the account ID.',
            default => 'Check both values and that Harvest is reachable.',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('billing_name')->searchable()->sortable(),
                TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('client.name')->sortable()->placeholder('—'),
                TextColumn::make('current_rate')
                    ->label('Rate')
                    ->state(fn (ContractorProfile $r) => $r->rateFor(now())?->hourly_rate)
                    ->money('CAD'),
                TextColumn::make('invoices_count')
                    ->counts('invoices')
                    ->label('Invoices'),
            ])
            ->recordActions([
                EditAction::make(),
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
            'index' => ListContractorProfiles::route('/'),
            'create' => CreateContractorProfile::route('/create'),
            'edit' => EditContractorProfile::route('/{record}/edit'),
        ];
    }
}
