<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $query = Client::with([
            'employee',
            'loans' => fn ($loanQuery) => $loanQuery
                ->with(['employee', 'responsibleUser', 'collections'])
                ->with(['installments' => fn ($installmentQuery) => $installmentQuery->orderBy('due_date')])
                ->with(['penalties' => fn ($penaltyQuery) => $penaltyQuery
                    ->whereColumn('paid_amount', '<', 'penalty_amount')
                    ->orderBy('penalty_date')])
                ->withSum('collections as collected_amount', 'amount_collected')
                ->orderByDesc('loan_date')
                ->orderByDesc('id'),
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

        return $query->latest()->get()->each(fn (Client $client) => $this->attachLoanSummaries($client));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(), $this->messages());
        $data['client_code'] = $data['client_code'] ?? $this->nextCode();
        $data = $this->storeClientDocuments($request, $data);
        $data['verification_status'] = 'pending';
        $data['document_verification_status'] = 'pending';
        $data['field_verification_status'] = 'pending';

        return response()->json(Client::create($data)->load('employee'), 201);
    }

    public function show(Client $client)
    {
        $client->load([
            'employee',
            'loans' => fn ($loanQuery) => $loanQuery
                ->with(['employee', 'responsibleUser', 'collections'])
                ->with(['installments' => fn ($installmentQuery) => $installmentQuery->orderBy('due_date')])
                ->with(['penalties' => fn ($penaltyQuery) => $penaltyQuery
                    ->whereColumn('paid_amount', '<', 'penalty_amount')
                    ->orderBy('penalty_date')])
                ->withSum('collections as collected_amount', 'amount_collected')
                ->orderByDesc('loan_date')
                ->orderByDesc('id'),
        ]);

        return $this->attachLoanSummaries($client);
    }

    public function document(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
        ]);
        $path = ltrim($data['path'], '/');

        abort_unless(str_starts_with($path, 'client-documents/'), 404);
        abort_unless(Storage::disk('public')->exists($path), 404);
        abort_unless(preg_match('/\.(jpe?g|png)$/i', $path), 404);

        return Storage::disk('public')->response($path);
    }

    public function update(Request $request, Client $client)
    {
        $data = $request->validate($this->rules(false), $this->messages());
        $data = $this->normalizeKycPayload($data);
        $data = $this->storeClientDocuments($request, $data);
        $data = $this->storeFieldVerificationDocuments($request, $data, $client);
        $data = $this->deriveVerificationStatus($data, $client);
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
            'photo_path' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:25600'],
            'date_of_birth' => ['nullable', 'date'],
            'verification_status' => ['nullable', 'in:pending,document_verified,field_verified,verified,rejected,high_confidence,blacklisted'],
            'document_verification_status' => ['nullable', 'in:pending,verified,rejected'],
            'document_verification_details' => ['nullable'],
            'field_verification_status' => ['nullable', 'in:pending,submitted,verified,rejected,revisit'],
            'field_verification_details' => ['nullable'],
            'loan_taker_selfie_path' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:25600'],
            'loan_taker_place_path' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:25600'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private function attachLoanSummaries(Client $client): Client
    {
        if (! $client->relationLoaded('loans')) {
            return $client;
        }

        $client->loans->each(function ($loan): void {
            $loan->setAttribute('paid_emi', round($loan->paidEmi(), 2));
            $loan->setAttribute('paid_penalty', round($loan->paidPenalty(), 2));
            $loan->setAttribute('outstanding_emi', round($loan->outstandingEmi(), 2));
            $loan->setAttribute('outstanding_penalty', round($loan->outstandingPenalty(), 2));
            $loan->setAttribute('total_outstanding', round($loan->outstandingEmi() + $loan->outstandingPenalty(false), 2));
        });

        return $client;
    }

    private function storeClientDocuments(Request $request, array $data): array
    {
        foreach (['aadhaar_path', 'pan_path', 'photo_path'] as $field) {
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

    private function storeFieldVerificationDocuments(Request $request, array $data, Client $client): array
    {
        $details = $data['field_verification_details'] ?? $client->field_verification_details ?? [];
        $details = is_array($details) ? $details : [];

        foreach ([
            'loan_taker_selfie_path' => ['loanTaker', 'selfiePath'],
            'loan_taker_place_path' => ['loanTaker', 'placePath'],
        ] as $field => [$person, $key]) {
            unset($data[$field]);

            if (! $request->hasFile($field)) {
                continue;
            }

            $file = $request->file($field);

            if (! $file->isValid()) {
                throw ValidationException::withMessages([
                    $field => 'The selected field verification photo could not be uploaded.',
                ]);
            }

            $details[$person] = $details[$person] ?? [];
            $details[$person][$key] = $file->store('field-verifications', 'public');
        }

        if (! empty($details)) {
            $data['field_verification_details'] = $details;
        }

        return $data;
    }

    private function normalizeKycPayload(array $data): array
    {
        foreach (['document_verification_details', 'field_verification_details'] as $field) {
            if (! isset($data[$field]) || is_array($data[$field])) {
                continue;
            }

            $decoded = json_decode((string) $data[$field], true);
            $data[$field] = is_array($decoded) ? $decoded : null;
        }

        return $data;
    }

    private function deriveVerificationStatus(array $data, Client $client): array
    {
        $documentStatus = $data['document_verification_status'] ?? $client->document_verification_status ?? 'pending';
        $fieldStatus = $data['field_verification_status'] ?? $client->field_verification_status ?? 'pending';

        if ($documentStatus === 'verified' && empty($data['document_verified_at']) && ! $client->document_verified_at) {
            $data['document_verified_at'] = now();
        }

        if ($fieldStatus === 'verified' && empty($data['field_verified_at']) && ! $client->field_verified_at) {
            $data['field_verified_at'] = now();
        }

        if ($documentStatus === 'rejected' || $fieldStatus === 'rejected') {
            $data['verification_status'] = 'rejected';
        } elseif ($documentStatus === 'verified' && $fieldStatus === 'verified') {
            $data['verification_status'] = 'verified';
        } elseif ($documentStatus === 'verified') {
            $data['verification_status'] = 'document_verified';
        } elseif ($fieldStatus === 'verified') {
            $data['verification_status'] = 'field_verified';
        } else {
            $data['verification_status'] = 'pending';
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
            'photo_path.file' => 'Client photo must be a valid file.',
            'photo_path.mimes' => 'Client photo must be JPG, PNG, or PDF.',
            'photo_path.max' => 'Client photo must be 25 MB or smaller.',
        ];
    }

    private function nextCode(): string
    {
        return 'DM-C-'.str_pad((string) (Client::count() + 1), 4, '0', STR_PAD_LEFT);
    }
}
