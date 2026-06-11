<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CollectionEntry;
use App\Models\Employee;
use App\Models\Loan;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function show(Request $request, string $type)
    {
        $date = $request->date('date') ?: now();

        return match ($type) {
            'daily' => $this->collectionReport($date->toDateString(), $date->toDateString(), $request),
            'weekly' => $this->collectionReport($date->copy()->startOfWeek()->toDateString(), $date->copy()->endOfWeek()->toDateString(), $request),
            'monthly' => $this->collectionReport($date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString(), $request),
            'client' => $this->clientPaymentReport((int) $request->query('client_id')),
            'employee-locations' => $this->employeeLocationReport($date->toDateString(), $request),
            'employee-detail' => $this->employeeDetailReport((int) $request->query('employee_id'), $date->toDateString()),
            'pending-dues' => $this->pendingDuesReport($request),
            'penalty' => ['rows' => Loan::where('status', 'active')->get()->map(fn ($loan) => [
                'loan_code' => $loan->loan_code,
                'client' => $loan->client?->name,
                'penalty_outstanding' => round($loan->outstandingPenalty(), 2),
            ])],
            'employee' => ['rows' => Employee::where('role', 'collection_executive')->withCount('loans')->withSum('collections', 'amount_collected')->get()],
            'outstanding' => ['rows' => Loan::with('client')->where('status', 'active')->get()->map(fn ($loan) => [
                'loan_code' => $loan->loan_code,
                'client' => $loan->client?->name,
                'outstanding' => round($loan->outstandingEmi(), 2),
            ])],
            'force-closure' => ['rows' => Loan::with('client')->where('status', 'force_closed')->get()],
            default => response()->json(['message' => 'Unknown report type'], 404),
        };
    }

    private function collectionReport(string $from, string $to, Request $request): array
    {
        $rows = CollectionEntry::with(['client', 'loan', 'employee', 'user'])
            ->whereBetween('collection_date', [$from, $to])
            ->when($request->query('client_id'), fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when($request->query('employee_id'), fn ($query, $employeeId) => $query->where('employee_id', $employeeId))
            ->orderByDesc('collection_date')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'client_id' => $request->query('client_id'),
            'employee_id' => $request->query('employee_id'),
            'total' => round($rows->sum('amount_collected'), 2),
            'count' => $rows->count(),
            'rows' => $rows,
        ];
    }

    private function clientPaymentReport(int $clientId): array
    {
        $client = Client::with([
            'loans.employee',
            'loans.responsibleUser',
            'loans.collections' => fn ($query) => $query->with(['employee', 'user'])->orderByDesc('collection_date')->orderByDesc('collected_at'),
        ])->findOrFail($clientId);
        $rows = CollectionEntry::with(['loan', 'employee', 'user'])
            ->where('client_id', $client->id)
            ->orderByDesc('collection_date')
            ->get();
        $loans = $client->loans->map(function (Loan $loan) {
            $paidAmount = (float) $loan->collections->sum('amount_collected');
            $paidEmi = (float) $loan->collections->sum('emi_amount');
            $paidPenalty = (float) $loan->collections->sum('penalty_amount');

            return [
                'id' => $loan->id,
                'loan_code' => $loan->loan_code,
                'loan_date' => $loan->loan_date,
                'loan_amount' => (float) $loan->loan_amount,
                'loan_type' => $loan->loan_type,
                'daily_collection_amount' => (float) $loan->daily_collection_amount,
                'weekly_emi' => (float) $loan->weekly_emi,
                'next_due_date' => $loan->next_due_date,
                'expected_closure_date' => $loan->expected_closure_date,
                'status' => $loan->status,
                'employee' => $loan->employee,
                'responsible_user' => $loan->responsibleUser,
                'paid_amount' => round($paidAmount, 2),
                'paid_emi' => round($paidEmi, 2),
                'paid_penalty' => round($paidPenalty, 2),
                'outstanding' => round(max(0, (float) $loan->loan_amount - $paidEmi), 2),
                'outstanding_penalty' => round(max(0, $loan->accruedPenalty() - $paidPenalty), 2),
                'payment_count' => $loan->collections->count(),
                'payments' => $loan->collections->values(),
            ];
        })->values();
        $client->unsetRelation('loans');

        return [
            'client' => $client,
            'total' => round($rows->sum('amount_collected'), 2),
            'count' => $rows->count(),
            'loan_count' => $loans->count(),
            'loan_total' => round($loans->sum('loan_amount'), 2),
            'total_outstanding' => round($loans->sum('outstanding'), 2),
            'loans' => $loans,
            'rows' => $rows,
        ];
    }

    private function employeeLocationReport(string $date, Request $request): array
    {
        $rows = CollectionEntry::with(['client', 'loan', 'employee', 'user'])
            ->whereDate('collection_date', $date)
            ->when($request->query('client_id'), fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when($request->query('employee_id'), fn ($query, $employeeId) => $query->where('employee_id', $employeeId))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('employee_id')
            ->orderBy('collected_at')
            ->get();

        return [
            'from' => $date,
            'to' => $date,
            'client_id' => $request->query('client_id'),
            'employee_id' => $request->query('employee_id'),
            'total' => round($rows->sum('amount_collected'), 2),
            'count' => $rows->count(),
            'rows' => $rows,
        ];
    }

    private function employeeDetailReport(int $employeeId, string $date): array
    {
        $employee = Employee::where('role', 'collection_executive')->findOrFail($employeeId);
        $rows = CollectionEntry::with(['client', 'loan', 'employee', 'user'])
            ->where('employee_id', $employee->id)
            ->whereDate('collection_date', $date)
            ->orderBy('collected_at')
            ->orderBy('created_at')
            ->get();

        return [
            'employee' => $employee,
            'from' => $date,
            'to' => $date,
            'total' => round($rows->sum('amount_collected'), 2),
            'count' => $rows->count(),
            'locations_count' => $rows->whereNotNull('latitude')->whereNotNull('longitude')->count(),
            'rows' => $rows,
        ];
    }

    private function pendingDuesReport(Request $request): array
    {
        $rows = Loan::with(['client', 'employee', 'responsibleUser'])
            ->where('status', 'active')
            ->when($request->query('client_id'), fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when($request->query('employee_id'), fn ($query, $employeeId) => $query->where('employee_id', $employeeId))
            ->get()
            ->map(function (Loan $loan) {
                $pendingEmi = round($loan->outstandingEmi(), 2);
                $pendingPenalty = round($loan->outstandingPenalty(), 2);

                return [
                    'id' => $loan->id,
                    'loan_code' => $loan->loan_code,
                    'client' => $loan->client,
                    'employee' => $loan->employee,
                    'responsible_user' => $loan->responsibleUser,
                    'loan_amount' => (float) $loan->loan_amount,
                    'pending_emi' => $pendingEmi,
                    'pending_penalty' => $pendingPenalty,
                    'total_due' => round($pendingEmi + $pendingPenalty, 2),
                    'next_due_date' => $loan->next_due_date,
                    'status' => $loan->status,
                ];
            })
            ->filter(fn (array $loan) => $loan['total_due'] > 0)
            ->sortByDesc('total_due')
            ->values();

        return [
            'count' => $rows->count(),
            'total_loan_amount' => round($rows->sum('loan_amount'), 2),
            'total_pending_emi' => round($rows->sum('pending_emi'), 2),
            'total_pending_penalty' => round($rows->sum('pending_penalty'), 2),
            'total_due' => round($rows->sum('total_due'), 2),
            'rows' => $rows,
        ];
    }
}
