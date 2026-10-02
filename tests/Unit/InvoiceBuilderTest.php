<?php

namespace Tests\Unit;

use App\Enums\InvoiceSource;
use App\Enums\InvoiceStatus;
use App\Models\ContractorProfile;
use App\Models\Rate;
use App\Services\InvoiceBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class InvoiceBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_from_lines_computes_totals_with_tax(): void
    {
        $profile = ContractorProfile::factory()->create(['tax_rate' => 0.05]);
        Rate::factory()->create(['contractor_profile_id' => $profile->id, 'hourly_rate' => 80.00]);

        $invoice = app(InvoiceBuilder::class)->buildFromLines(
            $profile,
            $profile->client,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-15'),
            [
                ['description' => 'Work', 'hours' => 78, 'rate' => 80],
            ],
        );

        $this->assertSame('6240.00', $invoice->subtotal);
        $this->assertSame('312.00', $invoice->tax_amount);
        $this->assertSame('6552.00', $invoice->total);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNotNull($invoice->invoice_number);
    }

    public function test_line_without_rate_uses_profile_rate_for_period(): void
    {
        $profile = ContractorProfile::factory()->create();
        Rate::factory()->create([
            'contractor_profile_id' => $profile->id,
            'hourly_rate' => 75.00,
            'effective_from' => '2026-01-01',
        ]);
        Rate::factory()->create([
            'contractor_profile_id' => $profile->id,
            'hourly_rate' => 90.00,
            'effective_from' => '2026-09-01',
        ]);

        $invoice = app(InvoiceBuilder::class)->buildFromLines(
            $profile,
            $profile->client,
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-06-15'),
            [['description' => 'June work', 'hours' => 10]],
        );

        $this->assertSame('750.00', $invoice->subtotal);
    }

    public function test_empty_lines_rejected(): void
    {
        $profile = ContractorProfile::factory()->create();

        $this->expectException(RuntimeException::class);

        app(InvoiceBuilder::class)->buildFromLines(
            $profile,
            $profile->client,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-15'),
            [],
        );
    }

    public function test_harvest_build_sums_entries_and_freezes_payload(): void
    {
        Http::fake([
            'api.harvestapp.com/v2/time_entries*' => Http::response([
                'time_entries' => [
                    ['spent_date' => '2026-09-01', 'hours' => 7.5, 'notes' => '', 'project' => ['name' => 'P'], 'task' => ['name' => 'Dev']],
                    ['spent_date' => '2026-09-02', 'hours' => 8, 'notes' => '', 'project' => ['name' => 'P'], 'task' => ['name' => 'Dev']],
                ],
                'links' => ['next' => null],
            ]),
        ]);

        $profile = ContractorProfile::factory()->create([
            'harvest_access_token' => 'token',
            'harvest_account_id' => '123',
        ]);
        Rate::factory()->create(['contractor_profile_id' => $profile->id, 'hourly_rate' => 80.00]);

        $invoice = app(InvoiceBuilder::class)->buildFromHarvest(
            $profile,
            $profile->client,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-15'),
        );

        $this->assertSame(InvoiceSource::Harvest, $invoice->source);
        $this->assertSame('1240.00', $invoice->subtotal); // 15.5h × $80
        $this->assertSame('Hours for September 1st - 15th, 2026', $invoice->lines->first()->description);
        $this->assertCount(2, $invoice->source_payload['entries']);
    }

    public function test_harvest_build_rejects_empty_period(): void
    {
        Http::fake([
            'api.harvestapp.com/v2/time_entries*' => Http::response([
                'time_entries' => [],
                'links' => ['next' => null],
            ]),
        ]);

        $profile = ContractorProfile::factory()->create([
            'harvest_access_token' => 'token',
            'harvest_account_id' => '123',
        ]);
        Rate::factory()->create(['contractor_profile_id' => $profile->id]);

        $this->expectException(RuntimeException::class);

        app(InvoiceBuilder::class)->buildFromHarvest(
            $profile,
            $profile->client,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-15'),
        );
    }

    public function test_harvest_build_requires_credentials(): void
    {
        $profile = ContractorProfile::factory()->create();

        $this->expectException(RuntimeException::class);

        app(InvoiceBuilder::class)->buildFromHarvest(
            $profile,
            $profile->client,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-15'),
        );
    }

    public function test_invoice_numbers_are_unique_per_contractor_month(): void
    {
        $profile = ContractorProfile::factory()->create();
        Rate::factory()->create(['contractor_profile_id' => $profile->id]);

        $builder = app(InvoiceBuilder::class);
        $line = [['description' => 'Work', 'hours' => 1, 'rate' => 80]];

        $first = $builder->buildFromLines($profile, $profile->client, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-15'), $line);
        $second = $builder->buildFromLines($profile, $profile->client, CarbonImmutable::parse('2026-09-16'), CarbonImmutable::parse('2026-09-30'), $line);

        $this->assertNotSame($first->invoice_number, $second->invoice_number);
    }
}
