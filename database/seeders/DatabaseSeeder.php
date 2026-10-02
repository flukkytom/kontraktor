<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ContractorProfile;
use App\Models\Rate;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Demo Admin',
            'email' => 'admin@kontractor.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        $client = Client::factory()->create([
            'name' => 'Example Client Inc.',
            'street' => '123 Example Way',
            'city' => 'Vancouver',
            'region' => 'BC',
            'postal_code' => 'V0V 0V0',
            'country' => 'Canada',
            'contact_email' => 'billing@example.test',
        ]);

        $contractor = User::factory()->create([
            'name' => 'Demo Contractor',
            'email' => 'contractor@kontractor.test',
            'password' => 'password',
        ]);

        $profile = ContractorProfile::factory()->create([
            'user_id' => $contractor->id,
            'client_id' => $client->id,
            'billing_name' => $contractor->name,
        ]);

        Rate::factory()->create([
            'contractor_profile_id' => $profile->id,
            'hourly_rate' => 80.00,
            'effective_from' => '2026-01-01',
        ]);
    }
}
