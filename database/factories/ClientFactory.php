<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'owner_user_id' => null,
            'name' => fake()->company(),
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'region' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'Canada',
            'contact_email' => fake()->companyEmail(),
        ];
    }
}
