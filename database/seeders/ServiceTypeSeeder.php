<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Cleaning',     'requires_next_service' => true],
            ['name' => 'Gas Top-Up',   'requires_next_service' => false],
            ['name' => 'Repair',       'requires_next_service' => false],
            ['name' => 'Installation', 'requires_next_service' => true],
            ['name' => 'Troubleshoot', 'requires_next_service' => true],
            ['name' => 'Dismantle',    'requires_next_service' => false],
        ];

        foreach ($types as $type) {
            ServiceType::updateOrCreate(
                ['name' => $type['name']],
                ['requires_next_service' => $type['requires_next_service']],
            );
        }
    }
}
