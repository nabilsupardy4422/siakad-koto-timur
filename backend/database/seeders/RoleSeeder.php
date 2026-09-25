<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'code' => 'TU',
                'name' => 'Tata Usaha',
            ],
            [
                'code' => 'KEPALA_SEKOLAH',
                'name' => 'Kepala Sekolah',
            ],
            [
                'code' => 'GURU',
                'name' => 'Guru',
            ],
            [
                'code' => 'SISWA',
                'name' => 'Siswa',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['code' => $role['code']],
                ['name' => $role['name']]
            );
        }
    }
}