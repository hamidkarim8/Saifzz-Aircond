<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Uses firstOrCreate (no factory/faker) so this runs in production
     * builds installed with --no-dev. Idempotent: safe to re-run.
     */
    public function run(): void
    {
        // User::firstOrCreate(
        //     ['email' => 'admin@saifzz.test'],
        //     [
        //         'name' => 'Khalid',
        //         'role' => User::ROLE_ADMIN,
        //         'active' => true,
        //         'password' => Hash::make('password'),
        //     ],
        // );

        User::firstOrCreate(
            ['email' => 'khalid@admin.com'],
            [
                'name' => 'Superadmin Khalid',
                'role' => User::ROLE_ADMIN,
                'active' => true,
                'password' => Hash::make('khalid123'),
            ],
        );

        User::firstOrCreate(
            ['email' => 'saifzz@admin.com'],
            [
                'name' => 'Superadmin Saifzz',
                'role' => User::ROLE_ADMIN,
                'active' => true,
                'password' => Hash::make('saifzz123'),
            ],
        );

        $this->call(ServiceFeeSeeder::class);
        $this->call(ServiceTypeSeeder::class);
    }
}
