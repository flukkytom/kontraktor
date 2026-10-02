<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceStatusWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(User $user, InvoiceStatus $status): Invoice
    {
        $profile = ContractorProfile::where('user_id', $user->id)->first()
            ?? ContractorProfile::factory()->create(['user_id' => $user->id]);

        return Invoice::factory()->create([
            'contractor_profile_id' => $profile->id,
            'status' => $status,
        ]);
    }

    public function test_contractor_can_mark_own_invoice_sent_then_paid(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, InvoiceStatus::Draft);

        Livewire::actingAs($user)
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('mark_sent')
            ->assertActionHidden('mark_paid')
            ->callAction('mark_sent');

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);

        Livewire::actingAs($user)
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('mark_paid')
            ->callAction('mark_paid');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_contractor_can_revert_paid_to_sent(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, InvoiceStatus::Paid);

        Livewire::actingAs($user)
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('mark_unpaid')
            ->callAction('mark_unpaid');

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_contractor_can_pull_a_sent_invoice_back_to_draft(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, InvoiceStatus::Sent);

        Livewire::actingAs($user)
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('mark_draft')
            ->callAction('mark_draft');

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);

        // A draft is editable again — the round trip is the supported fix path.
        $this->actingAs($user)
            ->get("/admin/invoices/{$invoice->id}/edit")
            ->assertOk();
    }

    public function test_draft_offers_no_paid_shortcut(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, InvoiceStatus::Draft);

        Livewire::actingAs($user)
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionHidden('mark_paid')
            ->assertActionHidden('mark_unpaid');
    }

    public function test_outstanding_tab_shows_only_sent_invoices(): void
    {
        $user = User::factory()->create();
        $sent = $this->invoiceFor($user, InvoiceStatus::Sent);
        $draft = $this->invoiceFor($user, InvoiceStatus::Draft);
        $paid = $this->invoiceFor($user, InvoiceStatus::Paid);

        $this->actingAs($user)
            ->get('/admin/invoices?tab=outstanding')
            ->assertOk()
            ->assertSee($sent->invoice_number)
            ->assertDontSee($draft->invoice_number)
            ->assertDontSee($paid->invoice_number);

        // Every other status-filtering tab hits the same injection path.
        foreach (['drafts', 'this_month', 'last_month', 'this_year', 'paid'] as $tab) {
            $this->actingAs($user)->get("/admin/invoices?tab={$tab}")->assertOk();
        }
    }
}
