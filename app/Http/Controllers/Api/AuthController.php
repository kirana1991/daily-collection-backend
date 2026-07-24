<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'mobile' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->with('employee')
            ->where('mobile', $credentials['mobile'])
            ->where('status', 'active')
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'email' => $user->email,
                'role' => $user->role,
                'employee_id' => $user->employee_id,
                'assigned_employee_name' => $user->employee?->name,
            ],
            'permissions' => $this->permissions($user->role),
        ];
    }

    private function permissions(string $role): array
    {
        return match ($role) {
            'admin' => ['full_access', 'create_users', 'edit_clients', 'delete_entries', 'generate_reports'],
            'manager' => ['view_all_clients', 'approve_collections', 'generate_receipts'],
            default => ['add_collections', 'view_assigned_clients'],
        };
    }
}
