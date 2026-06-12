<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Khalid',
            'email' => 'admin@saifzz.test',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->call(ServiceFeeSeeder::class);
        $this->call(ServiceTypeSeeder::class);
    }
}
