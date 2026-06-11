<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index()
    {
        return Employee::query()
            ->where('role', 'collection_executive')
            ->withCount('clients')
            ->latest()
            ->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:20'],
            'role' => ['nullable', 'in:collection_executive'],
            'status' => ['nullable', 'in:active,inactive'],
            'commission_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['employee_code'] = 'DM-E-'.str_pad((string) (Employee::query()->count() + 1), 3, '0', STR_PAD_LEFT);
        $data['role'] = 'collection_executive';
        $data['status'] = $data['status'] ?? 'active';
        $data['commission_rate'] = $data['commission_rate'] ?? 0;

        return response()->json(Employee::query()->create($data), 201);
    }
}
