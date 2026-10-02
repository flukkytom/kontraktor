<?php

namespace Database\Factories;

use App\Enums\InvoiceSource;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'contractor_profile_id' => ContractorProfile::factory(),
            'client_id' => Client::factory(),
            'invoice_number' => fake()->unique()->numerify('##-#'),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->startOfMonth()->setDay(15),
            'invoice_date' => now(),
            'status' => InvoiceStatus::Draft,
            'source' => InvoiceSource::Manual,
            'tax_rate' => 0.05,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ];
    }
}
