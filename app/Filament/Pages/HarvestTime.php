<?php

namespace App\Filament\Pages;

use App\Enums\PeriodPreset;
use App\Filament\Resources\ContractorProfiles\ContractorProfileResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\ContractorProfile;
use App\Services\HarvestClient;
use App\Services\InvoiceBuilder;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

/**
 * Contractor view of their Harvest time: verify the connection, pull a
 * period, review entries by day, then draft an invoice from them.
 */
class HarvestTime extends Page
{
    protected string $view = 'filament.pages.harvest-time';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing';

    protected static ?string $navigationLabel = 'Harvest';

    protected static ?string $title = 'Harvest Time';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    /** @var array<int, array{date: string, hours: float, notes: string, project: string, task: string}> */
    public array $entries = [];

    public bool $fetched = false;

    public ?string $periodStart = null;

    public ?string $periodEnd = null;

    /** @var array{ok: bool, name?: string, email?: string, error?: string}|null */
    public ?array $connection = null;

    public static function canAccess(): bool
    {
        return ! auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $preset = now()->day <= 15 ? PeriodPreset::FirstHalf : PeriodPreset::SecondHalf;

        $this->form->fill([
            'preset' => $preset->value,
            'month' => now()->format('Y-m'),
        ]);

        $this->checkConnection();
    }

    public function form(Schema $schema): Schema
    {
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
            ]);
    }

    public function fetch(): void
    {
        $profile = $this->profile();

        if (! $profile?->hasHarvestCredentials()) {
            Notification::make()->warning()
                ->title('Harvest not connected')
                ->body('Add your token and account ID under My Profile.')
                ->send();

            return;
        }

        [$start, $end] = $this->resolvePeriod($this->form->getState());

        try {
            $this->entries = $this->client($profile)
                ->timeEntries($start->toDateString(), $end->toDateString())
                ->sortBy('date')
                ->values()
                ->all();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Harvest request failed')->body($e->getMessage())->send();

            return;
        }

        $this->fetched = true;
        $this->periodStart = $start->toDateString();
        $this->periodEnd = $end->toDateString();
    }

    public function createInvoice(InvoiceBuilder $builder): void
    {
        $profile = $this->profile();

        if (! $profile?->client) {
            Notification::make()->danger()->title('Your profile has no client assigned.')->send();

            return;
        }

        try {
            $invoice = $builder->buildFromHarvest(
                $profile,
                $profile->client,
                CarbonImmutable::parse($this->periodStart),
                CarbonImmutable::parse($this->periodEnd),
            );
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()
            ->title("Invoice {$invoice->invoice_number} drafted")
            ->send();

        $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
    }

    public function checkConnection(): void
    {
        $profile = $this->profile();

        if (! $profile?->hasHarvestCredentials()) {
            $this->connection = null;

            return;
        }

        try {
            $me = $this->client($profile)->me();
            $this->connection = [
                'ok' => true,
                'name' => trim(($me['first_name'] ?? '').' '.($me['last_name'] ?? '')),
                'email' => $me['email'] ?? '',
            ];
        } catch (\Throwable $e) {
            $this->connection = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return Collection<string, Collection<int, array>> entries grouped by date */
    public function getEntriesByDayProperty(): Collection
    {
        return collect($this->entries)->groupBy('date');
    }

    public function getTotalHoursProperty(): float
    {
        return round(collect($this->entries)->sum('hours'), 2);
    }

    /** @return Collection<string, float> hours by project */
    public function getHoursByProjectProperty(): Collection
    {
        return collect($this->entries)
            ->groupBy('project')
            ->map(fn (Collection $g) => round($g->sum('hours'), 2))
            ->sortDesc();
    }

    public function getCurrentRateProperty(): ?float
    {
        $rate = $this->profile()?->rateFor(
            $this->periodEnd ? CarbonImmutable::parse($this->periodEnd) : now()
        );

        return $rate ? (float) $rate->hourly_rate : null;
    }

    public function getProfileUrlProperty(): string
    {
        return ContractorProfileResource::getNavigationUrl();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recheck')
                ->label('Re-check connection')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->checkConnection()),
        ];
    }

    private function profile(): ?ContractorProfile
    {
        return auth()->user()?->contractorProfile;
    }

    private function client(ContractorProfile $profile): HarvestClient
    {
        return new HarvestClient($profile->harvest_access_token, $profile->harvest_account_id);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function resolvePeriod(array $state): array
    {
        $preset = $state['preset'] instanceof PeriodPreset ? $state['preset'] : PeriodPreset::from($state['preset']);

        if ($preset === PeriodPreset::Custom) {
            return [CarbonImmutable::parse($state['period_start']), CarbonImmutable::parse($state['period_end'])];
        }

        return $preset->resolve(CarbonImmutable::parse($state['month'].'-01'));
    }

    /** @return array<string, string> */
    private function monthOptions(): array
    {
        return collect(range(0, 11))
            ->mapWithKeys(fn ($i) => [$m = now()->subMonths($i), $m->format('Y-m') => $m->format('F Y')])
            ->all();
    }
}
