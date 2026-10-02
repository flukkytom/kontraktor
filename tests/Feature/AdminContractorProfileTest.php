<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ContractorProfiles\Pages\CreateContractorProfile;
use App\Models\Client;
use App\Models\ContractorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminContractorProfileTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_user_picker_only_offers_profileless_contractors(): void
    {
        User::factory()->create(['name' => 'Has Profile', 'role' => UserRole::Contractor])
            ->contractorProfile()->create(ContractorProfile::factory()->make()->toArray());
        User::factory()->create(['name' => 'Free Agent', 'role' => UserRole::Contractor]);
        User::factory()->create(['name' => 'Second Admin', 'role' => UserRole::Admin]);

        $this->actingAs($this->admin())
            ->get('/admin/contractor-profiles/create')
            ->assertOk()
            ->assertSee('Free Agent')
            ->assertDontSee('Has Profile')
            ->assertDontSee('Second Admin');
    }

    public function test_admin_can_create_a_login_inline(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateContractorProfile::class)
            ->callFormComponentAction('user_id', 'createOption', [
                'name' => 'New Contractor',
                'email' => 'new@example.com',
                'password' => 'password123',
            ]);

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertSame(UserRole::Contractor, $user->role);
    }

    public function test_admin_never_sees_harvest_fields(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/contractor-profiles/create')
            ->assertDontSee('Personal access token');

        $profile = ContractorProfile::factory()->create();

        $this->actingAs($this->admin())
            ->get("/admin/contractor-profiles/{$profile->id}/edit")
            ->assertDontSee('Personal access token');
    }

    public function test_contractor_sees_harvest_fields(): void
    {
        $contractor = User::factory()->create();

        $this->actingAs($contractor)
            ->get('/admin/contractor-profiles/create')
            ->assertSee('Personal access token');
    }

    public function test_admin_create_assigns_profile_to_selected_user(): void
    {
        $target = User::factory()->create(['role' => UserRole::Contractor]);
        $client = Client::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(CreateContractorProfile::class)
            ->fillForm([
                'user_id' => $target->id,
                'client_id' => $client->id,
                'billing_name' => 'New Contractor',
                'tax_rate' => 0.05,
                'payment_terms' => 'Net 15 days',
                'invoice_number_pattern' => '{MM}-{seq}',
                'rates' => [['hourly_rate' => 80, 'effective_from' => '2026-01-01']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($target->id, ContractorProfile::sole()->user_id);
    }

    public function test_edit_keeps_the_assigned_user_selectable(): void
    {
        $owner = User::factory()->create(['name' => 'Owner User', 'role' => UserRole::Contractor]);
        $profile = ContractorProfile::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($this->admin())
            ->get("/admin/contractor-profiles/{$profile->id}/edit")
            ->assertOk()
            ->assertSee('Owner User');
    }
}
