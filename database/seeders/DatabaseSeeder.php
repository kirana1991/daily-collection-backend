<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => 'D Money Admin', 'email' => 'admin@dmoney.local', 'role' => 'admin', 'employee_id' => null],
            ['name' => 'Branch Manager', 'email' => 'manager@dmoney.local', 'role' => 'manager', 'employee_id' => null],
        ])->each(fn ($user) => User::query()->updateOrCreate(
            ['email' => $user['email']],
            [...$user, 'status' => 'active', 'password' => Hash::make('password')]
        ));
    }
}
