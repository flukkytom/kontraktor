<?php

namespace Tests\Feature;

use App\Filament\Pages\GenerateInvoice;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GenerateInvoicePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function contractor(array $profileAttrs = []): User
    {
        $user = User::factory()->create();
        $profile = ContractorProfile::factory()->create(['user_id' => $user->id, 'tax_rate' => 0.05] + $profileAttrs);
        Rate::factory()->create(['contractor_profile_id' => $profile->id, 'hourly_rate' => 80]);

        return $user;
    }

    public function test_preview_shows_invoice_without_saving(): void
    {
        $user = $this->contractor();

        Livewire::actingAs($user)
            ->test(GenerateInvoice::class)
            ->fillForm([
                'preset' => 'first_half',
                'month' => '2026-09',
                'source' => 'manual',
                'manual_lines' => [['description' => 'Dev work', 'hours' => 78, 'rate' => 80]],
            ])
            ->call('preview')
            ->assertSet('draft.invoice_number', fn ($n) => $n !== null)
            ->assertSee('Review')
            ->assertSee('Dev work')
            ->assertSee('6,240.00')   // subtotal
            ->assertSee('312.00')     // 5% tax
            ->assertSee('6,552.00');  // total

        $this->assertSame(0, Invoice::count());
    }

    public function test_back_returns_to_form_without_saving(): void
    {
        $user = $this->contractor();

        Livewire::actingAs($user)
            ->test(GenerateInvoice::class)
            ->fillForm([
                'preset' => 'first_half', 'month' => '2026-09', 'source' => 'manual',
                'manual_lines' => [['description' => 'x', 'hours' => 1, 'rate' => 80]],
            ])
            ->call('preview')
            ->assertSet('draft', fn ($d) => $d !== null)
            ->call('back')
            ->assertSet('draft', null);

        $this->assertSame(0, Invoice::count());
    }

    public function test_confirm_persists_exactly_the_previewed_draft(): void
    {
        $user = $this->contractor();

        $component = Livewire::actingAs($user)
            ->test(GenerateInvoice::class)
            ->fillForm([
                'preset' => 'second_half', 'month' => '2026-09', 'source' => 'manual',
                'manual_lines' => [
                    ['description' => 'Dev', 'hours' => 40, 'rate' => 80],
                    ['description' => 'Review', 'hours' => 2.5, 'rate' => 80],
                ],
            ])
            ->call('preview');

        $previewedNumber = $component->get('draft.invoice_number');

        $component->call('confirm')->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame($previewedNumber, $invoice->invoice_number);
        $this->assertSame('2026-09-16', $invoice->period_start->toDateString());
        $this->assertSame('2026-09-30', $invoice->period_end->toDateString());
        $this->assertCount(2, $invoice->lines);
        $this->assertSame('3400.00', $invoice->subtotal);
        $this->assertSame('3570.00', $invoice->total);
    }

    public function test_harvest_preview_shows_entry_count_and_summary_line(): void
    {
        Http::fake([
            'api.harvestapp.com/v2/time_entries*' => Http::response([
                'time_entries' => [
                    ['spent_date' => '2026-09-01', 'hours' => 8, 'notes' => '', 'project' => ['name' => 'P'], 'task' => ['name' => 'T']],
                    ['spent_date' => '2026-09-02', 'hours' => 7.5, 'notes' => '', 'project' => ['name' => 'P'], 'task' => ['name' => 'T']],
                ],
                'links' => ['next' => null],
            ]),
        ]);
        $user = $this->contractor(['harvest_access_token' => 'tok', 'harvest_account_id' => '1']);

        Livewire::actingAs($user)
            ->test(GenerateInvoice::class)
            ->fillForm(['preset' => 'first_half', 'month' => '2026-09', 'source' => 'harvest'])
            ->call('preview')
            ->assertSee('Hours for September 1st - 15th, 2026')
            ->assertSee('Harvest entries')
            ->assertSee('1,240.00'); // 15.5h × $80

        $this->assertSame(0, Invoice::count());
    }

    public function test_preview_failure_stays_on_form(): void
    {
        $user = $this->contractor();

        Livewire::actingAs($user)
            ->test(GenerateInvoice::class)
            ->fillForm(['preset' => 'first_half', 'month' => '2026-09', 'source' => 'manual', 'manual_lines' => []])
            ->call('preview')
            ->assertSet('draft', null)
            ->assertNotified();
    }

    public function test_invoice_number_is_prefilled_and_editable_on_preview(): void
    {
        $user = $this->contractor();

        $component = Livewire::actingAs($user)
            ->test(GenerateInvoice::class)
            ->fillForm([
                'preset' => 'first_half', 'month' => '2026-09', 'source' => 'manual',
                'manual_lines' => [['description' => 'x', 'hours' => 1, 'rate' => 80]],
            ])
            ->call('preview');

        $suggested = $component->get('draft.invoice_number');
        $component->assertSet('invoiceNumber', $suggested);

        $component->set('invoiceNumber', 'INV-2026-042')->call('confirm')->assertRedirect();

        $this->assertSame('INV-2026-042', Invoice::sole()->invoice_number);
    }

    public function test_duplicate_invoice_number_is_rejected_not_renumbered(): void
    {
        $user = $this->contractor();
        Invoice::factory()->create([
            'contractor_profile_id' => $user->contractorProfile->id,
            'invoice_number' => 'TAKEN',
        ]);

        Livewire::actingAs($user)
            ->test(GenerateInvoice::class)
            ->fillForm([
                'preset' => 'first_half', 'month' => '2026-09', 'source' => 'manual',
                'manual_lines' => [['description' => 'x', 'hours' => 1, 'rate' => 80]],
            ])
            ->call('preview')
            ->set('invoiceNumber', 'TAKEN')
            ->call('confirm')
            ->assertNoRedirect()
            ->assertNotified();

        $this->assertSame(1, Invoice::count());
    }

    public function test_blank_invoice_number_fails_validation(): void
    {
        $user = $this->contractor();

        Livewire::actingAs($user)
            ->test(GenerateInvoice::class)
            ->fillForm([
                'preset' => 'first_half', 'month' => '2026-09', 'source' => 'manual',
                'manual_lines' => [['description' => 'x', 'hours' => 1, 'rate' => 80]],
            ])
            ->call('preview')
            ->set('invoiceNumber', '')
            ->call('confirm')
            ->assertHasErrors(['invoiceNumber']);

        $this->assertSame(0, Invoice::count());
    }
}
