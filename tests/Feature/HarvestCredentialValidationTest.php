<?php

namespace Tests\Feature;

use App\Filament\Resources\ContractorProfiles\Pages\EditContractorProfile;
use App\Models\ContractorProfile;
use App\Models\Rate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class HarvestCredentialValidationTest extends TestCase
{
    use RefreshDatabase;

    private function profileFor(User $user): ContractorProfile
    {
        $profile = ContractorProfile::factory()->create(['user_id' => $user->id]);
        Rate::factory()->create(['contractor_profile_id' => $profile->id]);

        return $profile;
    }

    public function test_valid_credentials_are_verified_and_saved(): void
    {
        Http::fake(['api.harvestapp.com/v2/users/me' => Http::response(['id' => 1, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.test'])]);
        $user = User::factory()->create();
        $profile = $this->profileFor($user);

        Livewire::actingAs($user)
            ->test(EditContractorProfile::class, ['record' => $profile->id])
            ->fillForm(['harvest_access_token' => 'good-token', 'harvest_account_id' => '123456'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('good-token', $profile->fresh()->harvest_access_token);
        Http::assertSent(fn ($req) => $req->hasHeader('Harvest-Account-ID', '123456')
            && $req->hasHeader('Authorization', 'Bearer good-token'));
    }

    public function test_rejected_token_blocks_save_with_a_useful_message(): void
    {
        Http::fake(['api.harvestapp.com/v2/users/me' => Http::response(['error' => 'invalid_token'], 401)]);
        $user = User::factory()->create();
        $profile = $this->profileFor($user);

        Livewire::actingAs($user)
            ->test(EditContractorProfile::class, ['record' => $profile->id])
            ->fillForm(['harvest_access_token' => 'bad', 'harvest_account_id' => '123456'])
            ->call('save')
            ->assertHasFormErrors(['harvest_access_token'])
            ->assertSee('wrong or has been revoked');

        $this->assertNull($profile->fresh()->harvest_access_token);
    }

    public function test_token_without_account_id_is_rejected_before_calling_harvest(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $profile = $this->profileFor($user);

        Livewire::actingAs($user)
            ->test(EditContractorProfile::class, ['record' => $profile->id])
            ->fillForm(['harvest_access_token' => 'tok', 'harvest_account_id' => null])
            ->call('save')
            ->assertHasFormErrors(['harvest_account_id']);

        Http::assertNothingSent();
    }

    public function test_leaving_harvest_blank_does_not_call_harvest(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $profile = $this->profileFor($user);

        Livewire::actingAs($user)
            ->test(EditContractorProfile::class, ['record' => $profile->id])
            ->fillForm(['billing_name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        Http::assertNothingSent();
    }
}
