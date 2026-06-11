<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        return User::query()
            ->select(['id', 'employee_id', 'name', 'email', 'role', 'status', 'created_at'])
            ->withCount('responsibleLoans')
            ->with([
                'responsibleLoans' => fn ($loanQuery) => $loanQuery
                    ->with('client:id,name,mobile,client_code')
                    ->orderByDesc('loan_date')
                    ->orderByDesc('id'),
                'employee' => fn ($query) => $query
                    ->select(['id', 'name', 'employee_code'])
                    ->withCount('loans')
                    ->with([
                        'loans' => fn ($loanQuery) => $loanQuery
                            ->with('client:id,name,mobile,client_code')
                            ->orderByDesc('loan_date')
                            ->orderByDesc('id'),
                    ]),
            ])
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:admin,manager,collection_executive'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $data['status'] = $data['status'] ?? 'active';
        if ($data['role'] !== 'collection_executive') {
            $data['employee_id'] = null;
        }
        $data['employee_id'] = $data['employee_id'] ?: $this->employeeForUser($data);

        $user = User::query()->create($data)->load('employee:id,name,employee_code');

        return response()->json([
            'id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'employee' => $user->employee,
            'created_at' => $user->created_at,
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['sometimes', 'in:admin,manager,collection_executive'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $finalRole = $data['role'] ?? $user->role;
        if ($finalRole !== 'collection_executive') {
            $data['employee_id'] = null;
        } elseif (array_key_exists('employee_id', $data) && ! $data['employee_id']) {
            $data['employee_id'] = $this->employeeForUser([
                'role' => 'collection_executive',
                'name' => $data['name'] ?? $user->name,
            ]);
        }

        $user->update($data);

        return $user->only(['id', 'name', 'email', 'role', 'status', 'created_at']);
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->noContent();
    }

    private function employeeForUser(array $data): ?int
    {
        if ($data['role'] !== 'collection_executive') {
            return null;
        }

        return Employee::query()->create([
            'employee_code' => 'DM-E-'.str_pad((string) (Employee::query()->count() + 1), 3, '0', STR_PAD_LEFT),
            'name' => $data['name'],
            'mobile' => '0000000000',
            'role' => $data['role'],
            'status' => 'active',
            'commission_rate' => 0,
        ])->id;
    }
}
