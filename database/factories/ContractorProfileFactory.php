<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ContractorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContractorProfile> */
class ContractorProfileFactory extends Factory
{
    protected $model = ContractorProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'billing_name' => fake()->name(),
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'region' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'Canada',
            'phone' => fake()->numerify('###-###-####'),
            'tax_number' => fake()->numerify('#########RT0001'),
            'tax_rate' => 0.05,
            'payment_terms' => 'Net 15 days',
            'invoice_number_pattern' => '{MM}-{seq}',
        ];
    }
}
