<?php

namespace Database\Seeders;

use App\Models\ServiceFee;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceFeeSeeder extends Seeder
{
    public function run(): void
    {
        $byName = ServiceType::pluck('id', 'name');

        // [service type, unit type, hp_value (null = flat), price]
        $fees = [
            ['Cleaning', 'Wall Mounted', 1.0, 50],
            ['Cleaning', 'Wall Mounted', 1.5, 60],
            ['Cleaning', 'Wall Mounted', 2.0, 80],
            ['Cleaning', 'Cassette', 1.0, 70],
            ['Cleaning', 'Cassette', 1.5, 85],
            ['Cleaning', 'Cassette', 2.0, 110],
            ['Gas Top-Up', '20 PSI', null, 80],
            ['Gas Top-Up', 'Half Top-Up', null, 150],
            ['Gas Top-Up', 'Full Top-Up', null, 280],
            ['Installation', 'Wall Mounted', null, 120],
            ['Installation', 'Cassette', null, 180],
            ['Troubleshoot', 'Wall Mounted', null, 80],
            ['Troubleshoot', 'Cassette', null, 110],
        ];

        foreach ($fees as [$service, $unitType, $hp, $price]) {
            if (! isset($byName[$service])) {
                continue;
            }
            ServiceFee::updateOrCreate(
                ['service_type_id' => $byName[$service], 'unit_type' => $unitType, 'hp_value' => $hp],
                ['price' => $price],
            );
        }
    }
}
