<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    public function index(Request $request)
    {
        return Loan::with(['client', 'employee', 'responsibleUser'])
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $client = Client::findOrFail($data['client_id']);

        if ($client->verification_status !== 'verified') {
            throw ValidationException::withMessages([
                'client_id' => 'Client KYC must be fully verified before creating a loan.',
            ]);
        }

        $data['loan_code'] = $data['loan_code'] ?? $this->nextCode();
        $data['penalty_per_day'] = $data['penalty_per_day'] ?? 50;
        $data['status'] = $data['status'] ?? 'active';
        $data['first_emi_date'] = $data['next_due_date'];
        $data = $this->syncResponsibleUser($data);

        $loan = Loan::create($data);
        $loan->ensureInstallmentSchedule();

        return response()->json($loan->load(['client', 'employee', 'responsibleUser']), 201);
    }

    public function show(Loan $loan)
    {
        $loan->load([
            'client',
            'employee',
            'responsibleUser',
            'penalties' => fn ($query) => $query
                ->whereColumn('paid_amount', '<', 'penalty_amount')
                ->orderBy('penalty_date'),
            'collections' => fn ($query) => $query->with(['employee', 'user'])->orderByDesc('collection_date')->orderByDesc('collected_at'),
        ]);

        $loan->setAttribute('paid_emi', round($loan->paidEmi(), 2));
        $loan->setAttribute('paid_penalty', round($loan->paidPenalty(), 2));
        $loan->setAttribute('collected_amount', round((float) $loan->collections()->sum('amount_collected'), 2));
        $loan->setAttribute('outstanding_emi', round($loan->outstandingEmi(), 2));
        $loan->setAttribute('accrued_penalty', round($loan->accruedPenalty(), 2));
        $loan->setAttribute('outstanding_penalty', round($loan->outstandingPenalty(), 2));
        $loan->setAttribute('total_outstanding', round($loan->outstandingEmi() + $loan->outstandingPenalty(), 2));

        return $loan;
    }

    public function update(Request $request, Loan $loan)
    {
        $data = $this->syncResponsibleUser($request->validate($this->rules(false)));
        $loan->update($data);
        $loan->rebuildCollectionAccounting();

        return $loan->fresh(['client', 'employee', 'responsibleUser', 'collections']);
    }

    public function destroy(Loan $loan)
    {
        $loan->delete();

        return response()->noContent();
    }

    private function rules(bool $creating = true): array
    {
        return [
            'loan_code' => ['sometimes', 'string'],
            'client_id' => [$creating ? 'required' : 'sometimes', 'exists:clients,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'responsible_user_id' => [$creating ? 'required' : 'sometimes', 'nullable', 'exists:users,id'],
            'loan_date' => [$creating ? 'required' : 'sometimes', 'date'],
            'loan_amount' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:1'],
            'loan_type' => ['nullable', 'in:100_days,weekly,monthly,custom'],
            'daily_collection_amount' => ['nullable', 'numeric', 'min:0'],
            'weekly_emi' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:1'],
            'next_due_date' => $creating
                ? ['required', 'date', 'after_or_equal:loan_date']
                : ['sometimes', 'nullable', 'date'],
            'expected_closure_date' => ['nullable', 'date'],
            'penalty_per_day' => ['nullable', 'numeric', 'min:0'],
            'force_closure_amount' => ['nullable', 'numeric', 'min:0'],
            'force_closure_validity_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,closed,force_closed,default'],
        ];
    }

    private function nextCode(): string
    {
        return 'DM-L-'.str_pad((string) (Loan::count() + 1), 4, '0', STR_PAD_LEFT);
    }

    private function syncResponsibleUser(array $data): array
    {
        if (! array_key_exists('responsible_user_id', $data)) {
            return $data;
        }

        $responsibleUser = $data['responsible_user_id']
            ? User::query()->findOrFail($data['responsible_user_id'])
            : null;

        $data['employee_id'] = $responsibleUser?->role === 'collection_executive'
            ? $responsibleUser->employee_id
            : null;

        return $data;
    }
}
