<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Rate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditInvoiceLineTest extends TestCase
{
    use RefreshDatabase;

    private function draftWithLine(): array
    {
        $user = User::factory()->create();
        $profile = ContractorProfile::factory()->create(['user_id' => $user->id, 'tax_rate' => 0.05]);
        Rate::factory()->create(['contractor_profile_id' => $profile->id]);
        $invoice = Invoice::factory()->create(['contractor_profile_id' => $profile->id, 'status' => InvoiceStatus::Draft, 'tax_rate' => 0.05]);
        InvoiceLine::factory()->create(['invoice_id' => $invoice->id, 'hours' => 10, 'rate' => 80, 'amount' => 800]);

        return [$user, $invoice];
    }

    public function test_changing_hours_recalculates_amount(): void
    {
        [$user, $invoice] = $this->draftWithLine();

        $component = Livewire::actingAs($user)->test(EditInvoice::class, ['record' => $invoice->id]);
        $key = array_key_first($component->get('data.lines'));

        $component
            ->set("data.lines.{$key}.hours", 20)
            ->assertSet("data.lines.{$key}.amount", 1600.0);
    }

    public function test_changing_rate_recalculates_amount(): void
    {
        [$user, $invoice] = $this->draftWithLine();

        $component = Livewire::actingAs($user)->test(EditInvoice::class, ['record' => $invoice->id]);
        $key = array_key_first($component->get('data.lines'));

        $component
            ->set("data.lines.{$key}.rate", 95)
            ->assertSet("data.lines.{$key}.amount", 950.0);
    }

    public function test_saving_redirects_to_the_invoice_view(): void
    {
        [$user, $invoice] = $this->draftWithLine();

        Livewire::actingAs($user)
            ->test(EditInvoice::class, ['record' => $invoice->id])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect("/admin/invoices/{$invoice->id}");
    }

    public function test_saving_recomputes_invoice_totals(): void
    {
        [$user, $invoice] = $this->draftWithLine();

        $component = Livewire::actingAs($user)->test(EditInvoice::class, ['record' => $invoice->id]);
        $key = array_key_first($component->get('data.lines'));

        $component
            ->set("data.lines.{$key}.hours", 20)
            ->call('save')
            ->assertHasNoFormErrors();

        $invoice->refresh();
        $this->assertSame('1600.00', $invoice->subtotal);
        $this->assertSame('80.00', $invoice->tax_amount);
        $this->assertSame('1680.00', $invoice->total);
    }
}
