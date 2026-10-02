<?php

namespace Tests\Unit;

use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Services\InvoiceNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_invoice_of_the_month_is_seq_one(): void
    {
        $profile = ContractorProfile::factory()->create();
        $number = app(InvoiceNumberGenerator::class)->generate($profile, CarbonImmutable::parse('2026-09-30'));

        $this->assertSame('09-1', $number);
    }

    public function test_sequence_increments_within_the_month(): void
    {
        $profile = ContractorProfile::factory()->create();
        Invoice::factory()->create([
            'contractor_profile_id' => $profile->id,
            'invoice_number' => '09-1',
            'invoice_date' => '2026-09-15',
        ]);

        $number = app(InvoiceNumberGenerator::class)->generate($profile, CarbonImmutable::parse('2026-09-30'));

        $this->assertSame('09-2', $number);
    }

    public function test_sequence_resets_each_month(): void
    {
        $profile = ContractorProfile::factory()->create();
        Invoice::factory()->create([
            'contractor_profile_id' => $profile->id,
            'invoice_number' => '09-1',
            'invoice_date' => '2026-09-15',
        ]);

        $number = app(InvoiceNumberGenerator::class)->generate($profile, CarbonImmutable::parse('2026-10-15'));

        $this->assertSame('10-1', $number);
    }

    public function test_sequences_are_scoped_per_contractor(): void
    {
        $a = ContractorProfile::factory()->create();
        $b = ContractorProfile::factory()->create();
        Invoice::factory()->create([
            'contractor_profile_id' => $a->id,
            'invoice_number' => '09-1',
            'invoice_date' => '2026-09-15',
        ]);

        $number = app(InvoiceNumberGenerator::class)->generate($b, CarbonImmutable::parse('2026-09-30'));

        $this->assertSame('09-1', $number);
    }

    public function test_custom_pattern_tokens(): void
    {
        $profile = ContractorProfile::factory()->create(['invoice_number_pattern' => 'INV-{YYYY}-{MM}-{seq}']);

        $number = app(InvoiceNumberGenerator::class)->generate($profile, CarbonImmutable::parse('2026-09-30'));

        $this->assertSame('INV-2026-09-1', $number);
    }
}
