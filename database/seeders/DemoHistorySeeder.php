<?php

namespace Database\Seeders;

use App\Enums\InvoiceSource;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\ContractorProfile;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Twelve months of semi-monthly invoices across a few contractors so the
 * dashboard and history views have something to show. Local/demo only.
 */
class DemoHistorySeeder extends Seeder
{
    public function run(): void
    {
        $client = Client::firstOrFail();

        $profiles = collect([
            ['Demo Contractor', 'contractor@kontractor.test', 80],
            ['Priya Natarajan', 'priya@kontractor.test', 95],
            ['Marcus Oyelaran', 'marcus@kontractor.test', 70],
        ])->map(function (array $row) use ($client) {
            [$name, $email, $rate] = $row;

            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
            ]);

            $profile = ContractorProfile::firstOrCreate(
                ['user_id' => $user->id],
                ContractorProfile::factory()->raw([
                    'user_id' => $user->id,
                    'client_id' => $client->id,
                    'billing_name' => $name,
                ]),
            );

            if ($profile->rates()->doesntExist()) {
                Rate::create([
                    'contractor_profile_id' => $profile->id,
                    'hourly_rate' => $rate,
                    'effective_from' => '2025-01-01',
                ]);
            }

            return $profile;
        });

        $start = CarbonImmutable::now()->startOfMonth()->subMonths(11);

        foreach ($profiles as $profile) {
            $rate = (float) $profile->rateFor(now())->hourly_rate;

            for ($m = 0; $m < 12; $m++) {
                $month = $start->addMonths($m);

                foreach ([[1, 15], [16, (int) $month->endOfMonth()->format('d')]] as $i => [$from, $to]) {
                    $periodStart = $month->setDay($from);
                    $periodEnd = $month->setDay($to);

                    if ($periodEnd->isFuture()) {
                        continue;
                    }

                    $number = $month->format('m').'-'.($i + 1);

                    if ($profile->invoices()->where('invoice_number', $number)->whereYear('invoice_date', $month->year)->exists()) {
                        continue;
                    }

                    $hours = round(random_int(60, 88) + random_int(0, 3) * 0.25, 2);
                    $invoiceDate = $periodEnd->addDays(random_int(0, 2));

                    $status = match (true) {
                        $invoiceDate->diffInDays(now()) < 10 => InvoiceStatus::Sent,
                        random_int(1, 20) === 1 => InvoiceStatus::Sent,
                        default => InvoiceStatus::Paid,
                    };

                    $invoice = Invoice::create([
                        'contractor_profile_id' => $profile->id,
                        'client_id' => $client->id,
                        'invoice_number' => $number,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'invoice_date' => $invoiceDate,
                        'status' => $status,
                        'source' => InvoiceSource::Harvest,
                        'tax_rate' => $profile->tax_rate,
                    ]);

                    $invoice->lines()->create([
                        'description' => sprintf('Hours for %s %s - %s, %s',
                            $month->format('F'), $periodStart->format('jS'), $periodEnd->format('jS'), $month->format('Y')),
                        'hours' => $hours,
                        'rate' => $rate,
                        'amount' => round($hours * $rate, 2),
                    ]);

                    $invoice->recalculateTotals();
                    $invoice->save();
                }
            }
        }
    }
}
