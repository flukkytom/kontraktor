<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceLine> */
class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    public function definition(): array
    {
        $hours = fake()->randomFloat(2, 1, 80);
        $rate = 80.00;

        return [
            'invoice_id' => Invoice::factory(),
            'description' => 'Hours for '.fake()->date('F jS, Y'),
            'hours' => $hours,
            'rate' => $rate,
            'amount' => round($hours * $rate, 2),
        ];
    }
}
