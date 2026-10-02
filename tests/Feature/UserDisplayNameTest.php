<?php

namespace Tests\Feature;

use App\Models\ContractorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_contractor_is_displayed_by_billing_name(): void
    {
        $user = User::factory()->create(['name' => 'Demo Contractor']);
        ContractorProfile::factory()->create(['user_id' => $user->id, 'billing_name' => 'Jordan Avery']);

        $this->assertSame('Jordan Avery', $user->fresh()->getFilamentName());
        $this->actingAs($user)->get('/admin')->assertSee('Jordan Avery');
    }

    public function test_user_without_profile_falls_back_to_account_name(): void
    {
        $user = User::factory()->create(['name' => 'Demo Admin']);

        $this->assertSame('Demo Admin', $user->getFilamentName());
    }

    public function test_account_page_is_reachable(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/profile')->assertOk();
    }
}
