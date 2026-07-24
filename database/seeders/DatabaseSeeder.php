<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@dmoney.local'],
            [
                'name' => 'D Money Admin',
                'mobile' => '9999999999',
                'role' => 'admin',
                'employee_id' => null,
                'status' => 'active',
                'password' => 'password',
            ]
        );
    }
}
