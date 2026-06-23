<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        $data = $request->validate($this->rules(), $this->messages());
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
        $data = $request->validate($this->rules(false), $this->messages());
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
            'aadhaar_path' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:25600'],
            'pan_path' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:25600'],
            'date_of_birth' => ['nullable', 'date'],
            'verification_status' => ['nullable', 'in:pending,verified,high_confidence,blacklisted'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private function storeClientDocuments(Request $request, array $data): array
    {
        foreach (['aadhaar_path', 'pan_path'] as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);

                if (! $file->isValid()) {
                    throw ValidationException::withMessages([
                        $field => 'The selected document could not be uploaded. Please try a smaller or valid PDF/image file.',
                    ]);
                }

                try {
                    $data[$field] = $file->store('client-documents', 'public');
                } catch (Throwable $exception) {
                    Log::warning('Client document upload failed', [
                        'field' => $field,
                        'name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'mime' => $file->getMimeType(),
                        'error' => $exception->getMessage(),
                    ]);

                    throw ValidationException::withMessages([
                        $field => 'The document could not be saved. Please upload a smaller PDF/image or try again.',
                    ]);
                }
            }
        }

        return $data;
    }

    private function messages(): array
    {
        return [
            'aadhaar_path.file' => 'Aadhaar photocopy must be a valid file.',
            'aadhaar_path.mimes' => 'Aadhaar photocopy must be JPG, PNG, or PDF.',
            'aadhaar_path.max' => 'Aadhaar photocopy must be 25 MB or smaller.',
            'pan_path.file' => 'PAN photocopy must be a valid file.',
            'pan_path.mimes' => 'PAN photocopy must be JPG, PNG, or PDF.',
            'pan_path.max' => 'PAN photocopy must be 25 MB or smaller.',
        ];
    }

    private function nextCode(): string
    {
        return 'DM-C-'.str_pad((string) (Client::count() + 1), 4, '0', STR_PAD_LEFT);
    }
}
