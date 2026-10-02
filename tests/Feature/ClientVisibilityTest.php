<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_contractor_sees_shared_and_own_clients_only(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $shared = Client::factory()->create(['name' => 'Example Client Inc.']);
        $mine = Client::factory()->create(['owner_user_id' => $me->id, 'name' => 'My Side Client']);
        Client::factory()->create(['owner_user_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($me);
        $visible = ClientResource::getEloquentQuery()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Example Client Inc.', 'My Side Client'], $visible);
    }

    public function test_admin_sees_every_client(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Client::factory()->create();
        Client::factory()->create(['owner_user_id' => User::factory()->create()->id]);

        $this->actingAs($admin);

        $this->assertSame(2, ClientResource::getEloquentQuery()->count());
    }

    public function test_contractor_can_create_a_private_client(): void
    {
        $me = User::factory()->create();

        Livewire::actingAs($me)
            ->test(CreateClient::class)
            ->fillForm(['name' => 'Side Gig Ltd', 'country' => 'Canada'])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::where('name', 'Side Gig Ltd')->sole();
        $this->assertSame($me->id, $client->owner_user_id);
    }

    public function test_admin_created_client_is_shared_by_default(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test(CreateClient::class)
            ->fillForm(['name' => 'Example Subsidiary Inc.', 'country' => 'Canada'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Client::where('name', 'Example Subsidiary Inc.')->sole()->owner_user_id);
    }

    public function test_contractor_cannot_edit_a_shared_client(): void
    {
        $me = User::factory()->create();
        $shared = Client::factory()->create();

        $this->actingAs($me)->get("/admin/clients/{$shared->id}/edit")->assertForbidden();
    }

    public function test_shared_client_row_offers_view_not_edit_or_delete(): void
    {
        $me = User::factory()->create();
        $shared = Client::factory()->create();
        $mine = Client::factory()->create(['owner_user_id' => $me->id]);

        Livewire::actingAs($me)
            ->test(ListClients::class)
            ->assertTableActionVisible('view', $shared)
            ->assertTableActionHidden('edit', $shared)
            ->assertTableActionHidden('delete', $shared)
            ->assertTableActionVisible('edit', $mine)
            ->assertTableActionVisible('delete', $mine);
    }

    public function test_contractor_can_view_a_shared_client(): void
    {
        $me = User::factory()->create();
        $shared = Client::factory()->create();

        $this->actingAs($me)->get("/admin/clients/{$shared->id}")->assertOk();
    }

    public function test_contractor_cannot_view_another_contractors_client(): void
    {
        $me = User::factory()->create();
        $theirs = Client::factory()->create(['owner_user_id' => User::factory()->create()->id]);

        $this->actingAs($me)->get("/admin/clients/{$theirs->id}")->assertNotFound();
    }

    public function test_contractor_cannot_open_another_contractors_client(): void
    {
        $me = User::factory()->create();
        $theirs = Client::factory()->create(['owner_user_id' => User::factory()->create()->id]);

        $this->actingAs($me)->get("/admin/clients/{$theirs->id}/edit")->assertNotFound();
    }

    public function test_client_with_invoices_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = Client::factory()->create();
        Invoice::factory()->create(['client_id' => $client->id]);

        $this->actingAs($admin);
        $record = ClientResource::getEloquentQuery()->find($client->id);

        $this->assertFalse(ClientResource::canDelete($record));
    }

    public function test_profile_bills_to_only_offers_visible_clients(): void
    {
        $me = User::factory()->create();
        ContractorProfile::factory()->create(['user_id' => $me->id]);
        Client::factory()->create(['name' => 'Shared Co']);
        Client::factory()->create(['owner_user_id' => User::factory()->create()->id, 'name' => 'Hidden Co']);

        $this->actingAs($me)
            ->get('/admin/contractor-profiles/'.$me->contractorProfile->id.'/edit')
            ->assertOk()
            ->assertSee('Shared Co')
            ->assertDontSee('Hidden Co');
    }
}
