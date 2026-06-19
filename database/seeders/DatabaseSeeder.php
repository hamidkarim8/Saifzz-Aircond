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

        $khalid = User::firstOrCreate(
            ['email' => 'khalid@admin.com'],
            [
                'name' => 'Superadmin Khalid',
                'role' => User::ROLE_ADMIN,
                'active' => true,
                'password' => Hash::make('khalid123'),
            ],
        );
        if ($khalid->tenant_id === null) {
            $khalid->update(['tenant_id' => $khalid->id]);
        }

        $saifzz = User::firstOrCreate(
            ['email' => 'saifzz@admin.com'],
            [
                'name' => 'Superadmin Saifzz',
                'role' => User::ROLE_ADMIN,
                'active' => true,
                'password' => Hash::make('saifzz123'),
            ],
        );
        if ($saifzz->tenant_id === null) {
            $saifzz->update(['tenant_id' => $saifzz->id]);
        }

        // Real-fresh start: accounts + tenants only. Everything else (Saifzz
        // business identity + QR, service types, fee schedule) left blank so the
        // bosses set it all up from the live UI. Re-enable to reseed defaults.
        // if ($saifzz) {
        //     // Copy the bundled Google Review QR onto the public disk for Saifzz.
        //     $qrSource = public_path('img/google-review-qr.png');
        //     $qrPath = "qr/tenant-{$saifzz->id}.png";
        //     if (is_file($qrSource)) {
        //         \Illuminate\Support\Facades\Storage::disk('public')->put($qrPath, file_get_contents($qrSource));
        //     }
        //
        //     \App\Models\BusinessSetting::updateOrCreate(
        //         ['tenant_id' => $saifzz->id],
        //         [
        //             'business_name' => config('business.name'),
        //             'address' => config('business.address'),
        //             'phone' => config('business.phone'),
        //             'ssm_no' => '202603093151 (003839732-K)',
        //             'google_review_qr_path' => is_file($qrSource) ? $qrPath : null,
        //         ],
        //     );
        // }
        //
        // $this->call(ServiceTypeSeeder::class);
        // $this->call(ServiceFeeSeeder::class);
    }
}
