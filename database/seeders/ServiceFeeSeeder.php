<?php

namespace Database\Seeders;

use App\Models\ServiceFee;
use Illuminate\Database\Seeder;

class ServiceFeeSeeder extends Seeder
{
    /**
     * Reference price book from docs/02-domain-model.md.
     */
    public function run(): void
    {
        $fees = [
            ['Cleaning', 'Wall Mounted', 60, 'fixed_per_unit'],
            ['Cleaning', 'Cassette', 90, 'fixed_per_unit'],
            ['Gas Top-Up', '20 PSI', 80, 'fixed_per_unit'],
            ['Gas Top-Up', 'Half Top-Up', 150, 'fixed_per_unit'],
            ['Gas Top-Up', 'Full Top-Up', 280, 'fixed_per_unit'],
            ['Repair', null, null, 'flexible'],
            ['Installation', 'Wall Mounted', 120, 'fixed_per_unit'],
            ['Installation', 'Cassette', 180, 'fixed_per_unit'],
            ['Troubleshoot', 'Wall Mounted', 80, 'fixed_per_unit'],
            ['Troubleshoot', 'Cassette', 110, 'fixed_per_unit'],
        ];

        foreach ($fees as [$service, $option, $rate, $mode]) {
            ServiceFee::updateOrCreate(
                ['service_type' => $service, 'option' => $option],
                ['rate' => $rate, 'pricing_mode' => $mode],
            );
        }
    }
}
