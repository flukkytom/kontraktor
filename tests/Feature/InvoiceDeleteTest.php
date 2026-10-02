<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\User;
use App\Services\InvoiceNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function contractorWithInvoice(InvoiceStatus $status): array
    {
        $user = User::factory()->create();
        $profile = ContractorProfile::factory()->create(['user_id' => $user->id]);
        Rate::factory()->create(['contractor_profile_id' => $profile->id]);
        $invoice = Invoice::factory()->create(['contractor_profile_id' => $profile->id, 'status' => $status]);

        return [$user, $invoice];
    }

    public function test_contractor_can_delete_own_draft(): void
    {
        [$user, $invoice] = $this->contractorWithInvoice(InvoiceStatus::Draft);

        Livewire::actingAs($user)
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('delete')
            ->callAction('delete');

        $this->assertModelMissing($invoice);
    }

    public function test_contractor_cannot_delete_a_sent_invoice(): void
    {
        [$user, $invoice] = $this->contractorWithInvoice(InvoiceStatus::Sent);

        Livewire::actingAs($user)
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionHidden('delete');

        $this->assertModelExists($invoice);
    }

    public function test_admin_can_delete_any_invoice(): void
    {
        [, $invoice] = $this->contractorWithInvoice(InvoiceStatus::Paid);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('delete')
            ->callAction('delete');

        $this->assertModelMissing($invoice);
    }

    public function test_deleting_a_draft_frees_its_number(): void
    {
        [$user, $invoice] = $this->contractorWithInvoice(InvoiceStatus::Draft);
        $profile = $invoice->contractorProfile;
        $date = CarbonImmutable::parse('2026-09-20');
        $invoice->update(['invoice_number' => '09-1', 'invoice_date' => $date]);

        $generator = app(InvoiceNumberGenerator::class);
        $this->assertSame('09-2', $generator->generate($profile, $date));

        $invoice->delete();

        $this->assertSame('09-1', $generator->generate($profile, $date));
    }
}
