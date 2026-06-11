<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $query = Client::with([
            'employee',
            'loans' => fn ($loanQuery) => $loanQuery->with(['employee', 'responsibleUser'])->orderByDesc('loan_date')->orderByDesc('id'),
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($builder) use ($search) {
                $builder->where('client_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('aadhaar_number', 'like', "%{$search}%")
                    ->orWhere('pan_number', 'like', "%{$search}%");
            });
        }

        return $query->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $data['client_code'] = $data['client_code'] ?? $this->nextCode();
        $data = $this->storeClientDocuments($request, $data);

        return response()->json(Client::create($data)->load('employee'), 201);
    }

    public function show(Client $client)
    {
        return $client->load([
            'employee',
            'loans' => fn ($loanQuery) => $loanQuery->with(['employee', 'responsibleUser', 'collections'])->orderByDesc('loan_date')->orderByDesc('id'),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $data = $request->validate($this->rules(false));
        $data = $this->storeClientDocuments($request, $data);
        $client->update($data);

        return $client->fresh([
            'employee',
            'loans' => fn ($loanQuery) => $loanQuery->with(['employee', 'responsibleUser'])->orderByDesc('loan_date')->orderByDesc('id'),
        ]);
    }

    public function destroy(Client $client)
    {
        $client->delete();

        return response()->noContent();
    }

    private function rules(bool $creating = true): array
    {
        return [
            'client_code' => ['sometimes', 'string'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'name' => [$creating ? 'required' : 'sometimes', 'string'],
            'mobile' => [$creating ? 'required' : 'sometimes', 'string'],
            'alternative_mobile' => ['nullable', 'string'],
            'address' => [$creating ? 'required' : 'sometimes', 'string'],
            'village' => ['nullable', 'string'],
            'pin_code' => ['nullable', 'string'],
            'guardian_name' => ['nullable', 'string'],
            'guarantor_name' => ['nullable', 'string'],
            'guarantor_mobile' => ['nullable', 'string'],
            'aadhaar_number' => ['nullable', 'string'],
            'pan_number' => ['nullable', 'string'],
            'aadhaar_path' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
            'pan_path' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
            'date_of_birth' => ['nullable', 'date'],
            'verification_status' => ['nullable', 'in:pending,verified,high_confidence,blacklisted'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private function storeClientDocuments(Request $request, array $data): array
    {
        foreach (['aadhaar_path', 'pan_path'] as $field) {
            if ($request->hasFile($field)) {
                $data[$field] = $request->file($field)->store('client-documents', 'public');
            }
        }

        return $data;
    }

    private function nextCode(): string
    {
        return 'DM-C-'.str_pad((string) (Client::count() + 1), 4, '0', STR_PAD_LEFT);
    }
}
