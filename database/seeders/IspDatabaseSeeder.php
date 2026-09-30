<?php

namespace Database\Seeders;

use App\Isp\Models\Package;
use App\Isp\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class IspDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = trim((string) env('ISP_ADMIN_EMAIL'));
        $password = (string) env('ISP_ADMIN_PASSWORD');

        if ($email === '' || $password === '') {
            throw new RuntimeException('Set ISP_ADMIN_EMAIL and ISP_ADMIN_PASSWORD before running IspDatabaseSeeder.');
        }

        User::updateOrCreate(['email' => $email], [
            'name' => env('ISP_ADMIN_NAME', 'ISP Administrator'),
            'password' => $password,
            'role' => 'admin',
            'branch_id' => null,
            'is_active' => true,
        ]);

        Package::firstOrCreate(['mikrotik_profile' => 'starter-10m'], [
            'name' => 'Starter 10 Mbps',
            'rate_limit' => '10M/10M',
            'price' => 499,
            'validity_days' => 30,
        ]);
        Package::firstOrCreate(['mikrotik_profile' => 'family-30m'], [
            'name' => 'Family 30 Mbps',
            'rate_limit' => '30M/30M',
            'price' => 899,
            'validity_days' => 30,
        ]);
    }
}
