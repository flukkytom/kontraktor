<?php

namespace Database\Factories;

use App\Models\ContractorProfile;
use App\Models\Rate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Rate> */
class RateFactory extends Factory
{
    protected $model = Rate::class;

    public function definition(): array
    {
        return [
            'contractor_profile_id' => ContractorProfile::factory(),
            'hourly_rate' => 80.00,
            'effective_from' => '2020-01-01',
        ];
    }
}
