<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $executives = collect([
            [
                'employee_code' => 'DM-E-001',
                'name' => 'Ravi Kumar',
                'mobile' => '9000000001',
                'email' => 'ravi@dmoney.local',
            ],
            [
                'employee_code' => 'DM-E-002',
                'name' => 'Nisha Verma',
                'mobile' => '9000000002',
                'email' => 'nisha@dmoney.local',
            ],
        ])->map(function (array $executive): array {
            $employee = Employee::query()->updateOrCreate(
                ['employee_code' => $executive['employee_code']],
                [
                    'name' => $executive['name'],
                    'mobile' => $executive['mobile'],
                    'role' => 'collection_executive',
                    'status' => 'active',
                    'commission_rate' => 0,
                ]
            );

            return [
                'name' => $executive['name'],
                'email' => $executive['email'],
                'role' => 'collection_executive',
                'employee_id' => $employee->id,
            ];
        });

        $executives
            ->prepend([
                'name' => 'D Money Admin',
                'email' => 'admin@dmoney.local',
                'role' => 'admin',
                'employee_id' => null,
            ])
            ->each(fn (array $user) => User::query()->updateOrCreate(
                ['email' => $user['email']],
                [...$user, 'status' => 'active', 'password' => 'password']
            ));
    }
}
