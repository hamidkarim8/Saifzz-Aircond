<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['Cleaning', 'Gas Top-Up', 'Repair', 'Installation', 'Troubleshoot', 'Dismantle'];

        foreach ($types as $name) {
            ServiceType::firstOrCreate(['name' => $name]);
        }
    }
}
