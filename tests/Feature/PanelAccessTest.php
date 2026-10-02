<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Client;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Rate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function contractor(): User
    {
        $user = User::factory()->create();
        $profile = ContractorProfile::factory()->create([
            'user_id' => $user->id,
            'client_id' => Client::factory(),
        ]);
        Rate::factory()->create(['contractor_profile_id' => $profile->id]);

        return $user;
    }

    public function test_contractor_sees_only_own_invoices(): void
    {
        $contractor = $this->contractor();
        $other = ContractorProfile::factory()->create();

        $own = Invoice::factory()->create([
            'contractor_profile_id' => $contractor->contractorProfile->id,
            'invoice_number' => '09-1',
        ]);
        Invoice::factory()->create([
            'contractor_profile_id' => $other->id,
            'invoice_number' => '09-1',
        ]);

        $this->actingAs($contractor);
        $results = InvoiceResource::getEloquentQuery()->pluck('id');

        $this->assertSame([$own->id], $results->all());
    }

    public function test_generate_invoice_page_renders_for_contractor(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($contractor)
            ->get('/admin/generate-invoice')
            ->assertOk();
    }

    public function test_invoice_view_page_renders(): void
    {
        $contractor = $this->contractor();
        $invoice = Invoice::factory()->create([
            'contractor_profile_id' => $contractor->contractorProfile->id,
        ]);
        InvoiceLine::factory()->create(['invoice_id' => $invoice->id]);

        $this->actingAs($contractor)
            ->get("/admin/invoices/{$invoice->id}")
            ->assertOk();
    }

    public function test_contractor_cannot_open_another_contractors_invoice(): void
    {
        $contractor = $this->contractor();
        $other = Invoice::factory()->create();

        // Scoped resource query hides the record entirely — 404, not 403.
        $this->actingAs($contractor)
            ->get("/admin/invoices/{$other->id}")
            ->assertNotFound();
    }

    public function test_import_page_is_admin_only(): void
    {
        $contractor = $this->contractor();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($contractor)->get('/admin/import-data')->assertForbidden();
        $this->actingAs($admin)->get('/admin/import-data')->assertOk();
    }

    public function test_contractor_profile_list_redirects_contractor_to_own_profile(): void
    {
        $contractor = $this->contractor();
        $profile = $contractor->contractorProfile;

        $this->actingAs($contractor)
            ->get('/admin/contractor-profiles')
            ->assertRedirect("/admin/contractor-profiles/{$profile->id}/edit");
    }

    public function test_contractor_without_profile_is_sent_to_create(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/contractor-profiles')
            ->assertRedirect('/admin/contractor-profiles/create');

        $this->actingAs($user)->get('/admin/contractor-profiles/create')->assertOk();
    }

    public function test_contractor_with_profile_cannot_create_another(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($contractor)
            ->get('/admin/contractor-profiles/create')
            ->assertForbidden();
    }

    public function test_admin_sees_contractor_profile_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin/contractor-profiles')->assertOk();
    }

    public function test_clients_resource_is_available_to_both_roles(): void
    {
        $contractor = $this->contractor();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($contractor)->get('/admin/clients')->assertOk();
        $this->actingAs($admin)->get('/admin/clients')->assertOk();
    }
}
