<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceEditAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_contractor_cannot_edit_a_sent_invoice_via_url(): void
    {
        $user = User::factory()->create();
        $profile = ContractorProfile::factory()->create(['user_id' => $user->id]);
        $invoice = Invoice::factory()->create([
            'contractor_profile_id' => $profile->id,
            'status' => InvoiceStatus::Sent,
        ]);

        $this->actingAs($user)
            ->get("/admin/invoices/{$invoice->id}/edit")
            ->assertForbidden();
    }

    public function test_contractor_can_edit_own_draft_via_url(): void
    {
        $user = User::factory()->create();
        $profile = ContractorProfile::factory()->create(['user_id' => $user->id]);
        $invoice = Invoice::factory()->create([
            'contractor_profile_id' => $profile->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $this->actingAs($user)
            ->get("/admin/invoices/{$invoice->id}/edit")
            ->assertOk();
    }

    public function test_status_field_is_admin_only_on_edit(): void
    {
        $contractor = User::factory()->create();
        $profile = ContractorProfile::factory()->create(['user_id' => $contractor->id]);
        $invoice = Invoice::factory()->create([
            'contractor_profile_id' => $profile->id,
            'status' => InvoiceStatus::Draft,
        ]);

        Livewire::actingAs($contractor)
            ->test(EditInvoice::class, ['record' => $invoice->id])
            ->assertFormFieldHidden('status');

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test(EditInvoice::class, ['record' => $invoice->id])
            ->assertFormFieldVisible('status');
    }
}
