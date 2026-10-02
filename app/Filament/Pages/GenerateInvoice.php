<?php

namespace App\Filament\Pages;

use App\Enums\InvoiceSource;
use App\Enums\PeriodPreset;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Imports\HoursImport;
use App\Models\ContractorProfile;
use App\Services\InvoiceBuilder;
use App\Services\InvoiceDraft;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use UnitEnum;

class GenerateInvoice extends Page
{
    protected string $view = 'filament.pages.generate-invoice';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing';

    protected static ?string $navigationLabel = 'New Invoice';

    protected static ?string $title = 'New Invoice';

    protected static ?int $navigationSort = 0;

    public ?array $data = [];

    /** Serialized InvoiceDraft while the user is on the preview step. */
    public ?array $draft = null;

    /** Editable on the preview; pre-filled with the pattern's suggestion. */
    public string $invoiceNumber = '';

    public function mount(): void
    {
        $preset = now()->day <= 15 ? PeriodPreset::FirstHalf : PeriodPreset::SecondHalf;

        $this->form->fill([
            'preset' => $preset->value,
            'month' => now()->format('Y-m'),
            'source' => auth()->user()?->contractorProfile?->hasHarvestCredentials()
                ? 'harvest' : 'excel',
        ]);

        // Local-only shortcut for design work: ?demo=1 lands on the preview step.
        if (app()->isLocal() && request()->boolean('demo') && $this->profile?->client) {
            [$start, $end] = $preset->resolve(CarbonImmutable::now());
            $draft = app(InvoiceBuilder::class)->prepareFromLines(
                $this->profile, $start, $end,
                [['description' => 'Demo hours', 'hours' => 78]],
                InvoiceSource::Manual,
            );
            $this->draft = $draft->toArray();
            $this->invoiceNumber = $draft->invoiceNumber;
        }
    }

    public function form(Schema $schema): Schema
    {
        $hasHarvest = auth()->user()?->contractorProfile?->hasHarvestCredentials() ?? false;

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Period')->schema([
                    Radio::make('preset')
                        ->options(PeriodPreset::class)
                        ->required()
                        ->live()
                        ->inline(),
                    Select::make('month')
                        ->label('Month')
                        ->options($this->monthOptions())
                        ->required()
                        ->visible(fn (Get $get) => $get('preset') !== PeriodPreset::Custom->value),
                    DatePicker::make('period_start')
                        ->required()
                        ->visible(fn (Get $get) => $get('preset') === PeriodPreset::Custom->value),
                    DatePicker::make('period_end')
                        ->required()
                        ->visible(fn (Get $get) => $get('preset') === PeriodPreset::Custom->value),
                ])->columns(2),

                Section::make('Hours source')->schema([
                    Radio::make('source')
                        ->options([
                            'harvest' => 'Pull from Harvest',
                            'excel' => 'Upload timesheet (Excel/CSV)',
                            'manual' => 'Enter lines manually',
                        ])
                        ->required()
                        ->live()
                        ->inline()
                        ->disableOptionWhen(fn (string $value) => $value === 'harvest' && ! $hasHarvest)
                        ->helperText($hasHarvest
                            ? null
                            : 'Harvest needs a token on your profile — set it under Settings → My Profile.'),

                    FileUpload::make('timesheet')
                        ->label('Timesheet file')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                        ])
                        ->required()
                        ->visible(fn (Get $get) => $get('source') === 'excel')
                        ->helperText('Columns: date, hours, description (optional). Download the template below.')
                        ->columnSpanFull(),

                    Repeater::make('manual_lines')
                        ->label('Invoice lines')
                        ->schema([
                            TextInput::make('description')->required()->columnSpan(2),
                            TextInput::make('hours')->numeric()->type('text')->minValue(0)->required(),
                            TextInput::make('rate')->numeric()->type('text')->minValue(0)->prefix('$')->required(),
                        ])
                        ->columns(4)
                        ->defaultItems(1)
                        ->visible(fn (Get $get) => $get('source') === 'manual')
                        ->columnSpanFull(),
                ]),
            ]);
    }

    /**
     * Step 1: compute the draft and show it. Nothing is saved yet.
     */
    public function preview(InvoiceBuilder $builder): void
    {
        $profile = $this->readyProfile();

        if (! $profile) {
            return;
        }

        $state = $this->form->getState();
        [$start, $end] = $this->resolvePeriod($state);

        try {
            $draft = match ($state['source']) {
                'harvest' => $builder->prepareFromHarvest($profile, $start, $end),
                'excel' => $builder->prepareFromLines(
                    $profile, $start, $end,
                    $this->parseTimesheet($state['timesheet']),
                    InvoiceSource::Excel,
                ),
                'manual' => $builder->prepareFromLines(
                    $profile, $start, $end,
                    $state['manual_lines'] ?? [],
                    InvoiceSource::Manual,
                ),
                default => throw new RuntimeException('Unknown source.'),
            };
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->draft = $draft->toArray();
        $this->invoiceNumber = $draft->invoiceNumber;
    }

    /**
     * Step 2: persist exactly what was previewed.
     */
    public function confirm(InvoiceBuilder $builder): void
    {
        $profile = $this->readyProfile();

        if (! $profile || $this->draft === null) {
            return;
        }

        $this->validate(
            ['invoiceNumber' => ['required', 'string', 'max:40']],
            [],
            ['invoiceNumber' => 'invoice number'],
        );

        try {
            $invoice = $builder->create(
                $profile,
                $profile->client,
                InvoiceDraft::fromArray($this->draft)->withNumber($this->invoiceNumber),
            );
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()
            ->title("Invoice {$invoice->invoice_number} drafted")
            ->body('Download the PDF, then mark it sent.')
            ->send();

        $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
    }

    public function back(): void
    {
        $this->draft = null;
        $this->invoiceNumber = '';
    }

    public function getDraftObjectProperty(): ?InvoiceDraft
    {
        return $this->draft ? InvoiceDraft::fromArray($this->draft) : null;
    }

    public function getProfileProperty(): ?ContractorProfile
    {
        return auth()->user()?->contractorProfile;
    }

    private function readyProfile(): ?ContractorProfile
    {
        $profile = $this->profile;

        if (! $profile) {
            Notification::make()->danger()
                ->title('Set up your contractor profile first')
                ->body('Settings → My Profile.')
                ->send();

            return null;
        }

        if (! $profile->client) {
            Notification::make()->danger()->title('Your profile has no client assigned.')->send();

            return null;
        }

        return $profile;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePeriod(array $state): array
    {
        $preset = $state['preset'] instanceof PeriodPreset ? $state['preset'] : PeriodPreset::from($state['preset']);

        if ($preset === PeriodPreset::Custom) {
            return [
                CarbonImmutable::parse($state['period_start']),
                CarbonImmutable::parse($state['period_end']),
            ];
        }

        return $preset->resolve(CarbonImmutable::parse($state['month'].'-01'));
    }

    /** @return array<int, array{description: string, hours: float}> */
    private function parseTimesheet(string $path): array
    {
        $rows = Excel::toCollection(new HoursImport, Storage::path($path))->first();

        $errors = $rows->filter(fn ($r) => $r['error'] !== null);
        if ($errors->isNotEmpty()) {
            throw new RuntimeException(
                'Timesheet has invalid rows: '.$errors->pluck('error')->unique()->implode(', ')
            );
        }

        return $rows->map(fn ($r) => [
            'description' => $r['description'],
            'hours' => $r['hours'],
        ])->all();
    }

    /** @return array<string, string> */
    private function monthOptions(): array
    {
        return collect(range(0, 11))
            ->mapWithKeys(fn ($i) => [$m = now()->subMonths($i), $m->format('Y-m') => $m->format('F Y')])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_template')
                ->label('Timesheet template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => response()->download(resource_path('templates/hours-template.csv'))),
        ];
    }
}
