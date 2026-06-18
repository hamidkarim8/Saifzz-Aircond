<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Cleaning',     'pricing_mode' => 'hp_tiered', 'requires_next_service' => true],
            ['name' => 'Gas Top-Up',   'pricing_mode' => 'flat',      'requires_next_service' => false],
            ['name' => 'Repair',       'pricing_mode' => 'flexible',  'requires_next_service' => false],
            ['name' => 'Installation', 'pricing_mode' => 'flat',      'requires_next_service' => true],
            ['name' => 'Troubleshoot', 'pricing_mode' => 'flat',      'requires_next_service' => true],
            ['name' => 'Dismantle',    'pricing_mode' => 'flexible',  'requires_next_service' => false],
        ];

        foreach ($types as $type) {
            ServiceType::updateOrCreate(
                ['name' => $type['name']],
                ['pricing_mode' => $type['pricing_mode'], 'requires_next_service' => $type['requires_next_service']],
            );
        }
    }
}
