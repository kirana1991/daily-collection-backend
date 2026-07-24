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
            ->select(['id', 'employee_id', 'name', 'mobile', 'email', 'role', 'status', 'created_at'])
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
            'mobile' => ['required', 'string', 'max:20', 'unique:users,mobile'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:admin,manager,collection_executive'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $data['status'] = $data['status'] ?? 'active';
        $data['email'] = ($data['email'] ?? null) ?: $this->emailFromMobile($data['mobile']);
        if ($data['role'] !== 'collection_executive') {
            $data['employee_id'] = null;
        }
        $data['employee_id'] = ($data['employee_id'] ?? null) ?: $this->employeeForUser($data);

        $user = User::query()->create($data)->load('employee:id,name,employee_code');

        return response()->json([
            'id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'mobile' => $user->mobile,
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
            'mobile' => ['sometimes', 'string', 'max:20', 'unique:users,mobile,'.$user->id],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['sometimes', 'in:admin,manager,collection_executive'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }
        if (array_key_exists('email', $data) && empty($data['email'])) {
            unset($data['email']);
        }

        $finalRole = $data['role'] ?? $user->role;
        if ($finalRole !== 'collection_executive') {
            $data['employee_id'] = null;
        } elseif (array_key_exists('employee_id', $data) && ! $data['employee_id']) {
            $data['employee_id'] = $this->employeeForUser([
                'role' => 'collection_executive',
                'name' => $data['name'] ?? $user->name,
                'mobile' => $data['mobile'] ?? null,
            ]);
        }

        if (array_key_exists('mobile', $data)) {
            $user->employee?->update(['mobile' => $data['mobile'] ?: '0000000000']);
        }

        $user->update($data);

        return $user->only(['id', 'name', 'mobile', 'email', 'role', 'status', 'created_at']);
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
            'mobile' => $data['mobile'] ?? '0000000000',
            'role' => $data['role'],
            'status' => 'active',
            'commission_rate' => 0,
        ])->id;
    }

    private function emailFromMobile(string $mobile): string
    {
        $normalized = preg_replace('/\D+/', '', $mobile) ?: uniqid('user');

        return $normalized.'@dmoney.local';
    }
}
