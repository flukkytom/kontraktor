<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_for_admin_with_data(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $invoice = Invoice::factory()->create(['total' => 100, 'subtotal' => 95]);
        InvoiceLine::factory()->create(['invoice_id' => $invoice->id]);

        // Chart/table widgets lazy-load over Livewire; stats render inline.
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Outstanding');
    }

    public function test_dashboard_renders_for_contractor(): void
    {
        $user = User::factory()->create();
        ContractorProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee('By contractor');
    }

    public function test_invoice_list_tabs_render(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Invoice::factory()->create();

        $this->actingAs($admin)->get('/admin/invoices')->assertOk()->assertSee('Outstanding');
    }
}
