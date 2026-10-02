<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\HarvestTime;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class HarvestTimePageTest extends TestCase
{
    use RefreshDatabase;

    private function connectedContractor(): User
    {
        $user = User::factory()->create();
        $profile = ContractorProfile::factory()->create([
            'user_id' => $user->id,
            'harvest_access_token' => 'tok',
            'harvest_account_id' => '42',
        ]);
        Rate::factory()->create(['contractor_profile_id' => $profile->id, 'hourly_rate' => 80]);

        return $user;
    }

    private function fakeHarvest(array $entries): void
    {
        Http::fake([
            'api.harvestapp.com/v2/users/me' => Http::response(['id' => 1, 'first_name' => 'Demo', 'last_name' => 'User', 'email' => 'd@x.test']),
            'api.harvestapp.com/v2/time_entries*' => Http::response(['time_entries' => $entries, 'links' => ['next' => null]]),
        ]);
    }

    public function test_page_is_hidden_from_admins(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin/harvest-time')->assertForbidden();
    }

    public function test_unconnected_contractor_sees_prompt_to_connect(): void
    {
        $user = User::factory()->create();
        ContractorProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get('/admin/harvest-time')
            ->assertOk()
            ->assertSee('Harvest is not connected');
    }

    public function test_connected_contractor_sees_harvest_identity(): void
    {
        $this->fakeHarvest([]);
        $user = $this->connectedContractor();

        $this->actingAs($user)->get('/admin/harvest-time')
            ->assertOk()
            ->assertSee('Connected to Harvest')
            ->assertSee('Demo User');
    }

    public function test_fetch_groups_entries_and_totals_hours(): void
    {
        $this->fakeHarvest([
            ['spent_date' => '2026-09-01', 'hours' => 7.5, 'notes' => 'Build', 'project' => ['name' => 'Portal'], 'task' => ['name' => 'Dev']],
            ['spent_date' => '2026-09-01', 'hours' => 0.5, 'notes' => 'Standup', 'project' => ['name' => 'Portal'], 'task' => ['name' => 'Meeting']],
            ['spent_date' => '2026-09-02', 'hours' => 8, 'notes' => '', 'project' => ['name' => 'API'], 'task' => ['name' => 'Dev']],
        ]);
        $user = $this->connectedContractor();

        Livewire::actingAs($user)
            ->test(HarvestTime::class)
            ->fillForm(['preset' => 'first_half', 'month' => '2026-09'])
            ->call('fetch')
            ->assertSet('fetched', true)
            ->assertSet('periodStart', '2026-09-01')
            ->assertSet('periodEnd', '2026-09-15')
            ->assertSee('Portal')
            ->assertSee('API')
            ->assertSee('Standup')
            ->assertSeeHtml('$1,280.00'); // 16h × $80
    }

    public function test_create_invoice_from_fetched_hours(): void
    {
        $this->fakeHarvest([
            ['spent_date' => '2026-09-03', 'hours' => 10, 'notes' => '', 'project' => ['name' => 'P'], 'task' => ['name' => 'T']],
        ]);
        $user = $this->connectedContractor();

        Livewire::actingAs($user)
            ->test(HarvestTime::class)
            ->fillForm(['preset' => 'first_half', 'month' => '2026-09'])
            ->call('fetch')
            ->call('createInvoice')
            ->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame('800.00', $invoice->subtotal);
        $this->assertSame('2026-09-01', $invoice->period_start->toDateString());
    }
}
