<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'role' => 'TU',
                'username' => env('SEED_TU_USERNAME', 'tu.dev'),
                'name' => 'TU Development',
                'email' => env('SEED_TU_EMAIL', 'tu.dev@siakad.test'),
                'password' => env('SEED_TU_PASSWORD'),
            ],
            [
                'role' => 'KEPALA_SEKOLAH',
                'username' => env('SEED_KEPALA_USERNAME', 'kepala.dev'),
                'name' => 'Kepala Sekolah Development',
                'email' => env('SEED_KEPALA_EMAIL', 'kepala.dev@siakad.test'),
                'password' => env('SEED_KEPALA_PASSWORD'),
            ],
            [
                'role' => 'GURU',
                'username' => env('SEED_GURU_USERNAME', 'guru.dev'),
                'name' => 'Guru Development',
                'email' => env('SEED_GURU_EMAIL', 'guru.dev@siakad.test'),
                'password' => env('SEED_GURU_PASSWORD'),
            ],
            [
                'role' => 'SISWA',
                'username' => env('SEED_SISWA_USERNAME', 'siswa.dev'),
                'name' => 'Siswa Development',
                'email' => env('SEED_SISWA_EMAIL', 'siswa.dev@siakad.test'),
                'password' => env('SEED_SISWA_PASSWORD'),
            ],
        ];

        foreach ($accounts as $account) {
            $role = Role::where('code', $account['role'])->firstOrFail();

            User::updateOrCreate(
                [
                    'username' => $account['username'],
                ],
                [
                    'role_id' => $role->id,
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => Hash::make(
                        $account['password'] ?? throw new \RuntimeException(
                            "Password seed untuk role {$account['role']} belum dikonfigurasi."
                        )
                    ),
                    'account_status' => 'active',
                ]
            );
        }
    }
}