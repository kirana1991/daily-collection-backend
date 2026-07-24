<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CollectionEntry;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\Penalty;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function show(Request $request, string $type)
    {
        $date = $request->date('date') ?: now();

        return match ($type) {
            'all' => $this->collectionReport(null, null, $request),
            'today' => $this->collectionReport(now()->toDateString(), now()->toDateString(), $request),
            'yesterday' => $this->collectionReport(now()->subDay()->toDateString(), now()->subDay()->toDateString(), $request),
            'week' => $this->collectionReport(now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString(), $request),
            'month' => $this->collectionReport(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), $request),
            'custom' => $this->collectionReport($date->toDateString(), $date->toDateString(), $request),
            'daily' => $this->collectionReport($date->toDateString(), $date->toDateString(), $request),
            'weekly' => $this->collectionReport($date->copy()->startOfWeek()->toDateString(), $date->copy()->endOfWeek()->toDateString(), $request),
            'monthly' => $this->collectionReport($date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString(), $request),
            'client' => $this->clientPaymentReport((int) $request->query('client_id')),
            'employee-locations' => $this->employeeLocationReport($date->toDateString(), $request),
            'employee-detail' => $this->employeeDetailReport((int) $request->query('employee_id'), $date->toDateString()),
            'pending-dues' => $this->pendingDuesReport($request),
            'penalty' => $this->penaltyReport($request),
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

    private function collectionReport(?string $from, ?string $to, Request $request): array
    {
        $rows = CollectionEntry::with(['client', 'loan', 'employee', 'user'])
            ->when($from && $to, fn ($query) => $query->whereBetween('collection_date', [$from, $to]))
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
            ->when($request->query('collector_user_id'), fn ($query, $collectorUserId) => $query->where('responsible_user_id', $collectorUserId))
            ->get()
            ->map(function (Loan $loan) {
                $overdueInstallments = $loan->overdueInstallments();
                $pendingEmi = round($overdueInstallments->sum(fn ($installment) => $installment->outstandingAmount()), 2);
                $dueToday = round($loan->dueTodayAmount(), 2);
                $pendingPenalty = round($loan->outstandingPenalty(), 2);

                return [
                    'id' => $loan->id,
                    'loan_code' => $loan->loan_code,
                    'client' => $loan->client,
                    'employee' => $loan->employee,
                    'responsible_user' => $loan->responsibleUser,
                    'loan_amount' => (float) $loan->loan_amount,
                    'emi_amount' => round($loan->scheduledInstallmentAmount(), 2),
                    'pending_emi' => $pendingEmi,
                    'total_pending_emis' => $overdueInstallments->count(),
                    'overdue_installments_count' => $overdueInstallments->count(),
                    'overdue_installments' => $overdueInstallments->map(fn ($installment) => [
                        'id' => $installment->id,
                        'due_date' => $installment->due_date,
                        'amount_due' => round($installment->outstandingAmount(), 2),
                    ])->values(),
                    'oldest_due_date' => $overdueInstallments->first()?->due_date,
                    'due_today' => $dueToday,
                    'pending_penalty' => $pendingPenalty,
                    'total_due' => round($pendingEmi + $dueToday + $pendingPenalty, 2),
                    'next_due_date' => $loan->next_due_date,
                    'status' => $loan->status,
                ];
            })
            ->filter(fn (array $loan) => $loan['total_pending_emis'] > 0)
            ->sortByDesc('total_pending_emis')
            ->values();

        return [
            'count' => $rows->count(),
            'total_loan_amount' => round($rows->sum('loan_amount'), 2),
            'total_pending_emi' => round($rows->sum('pending_emi'), 2),
            'total_pending_emis' => $rows->sum('total_pending_emis'),
            'total_due_today' => round($rows->sum('due_today'), 2),
            'total_pending_penalty' => round($rows->sum('pending_penalty'), 2),
            'total_due' => round($rows->sum('total_due'), 2),
            'rows' => $rows,
        ];
    }

    private function penaltyReport(Request $request): array
    {
        Loan::where('status', 'active')->get()->each(fn (Loan $loan) => $loan->syncCurrentPenalties());

        $penalties = Penalty::with(['client', 'loan.employee', 'loan.responsibleUser'])
            ->whereColumn('paid_amount', '<', 'penalty_amount')
            ->when($request->query('client_id'), fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when($request->query('loan_id'), fn ($query, $loanId) => $query->where('loan_id', $loanId))
            ->when($request->query('collector_user_id'), fn ($query, $collectorUserId) => $query->whereHas('loan', fn ($loanQuery) => $loanQuery->where('responsible_user_id', $collectorUserId)))
            ->orderBy('penalty_date')
            ->get();

        $rows = $penalties
            ->groupBy('loan_id')
            ->map(function ($loanPenalties) {
                $firstPenalty = $loanPenalties->first();
                $loan = $firstPenalty->loan;

                return [
                    'loan_id' => $loan?->id,
                    'loan_code' => $loan?->loan_code,
                    'client' => $firstPenalty->client,
                    'collector' => $loan?->responsibleUser?->name ?: $loan?->employee?->name ?: 'Unassigned',
                    'pending_penalty_count' => $loanPenalties->count(),
                    'pending_penalty_amount' => round($loanPenalties->sum(fn (Penalty $penalty) => max(0, (float) $penalty->penalty_amount - (float) $penalty->paid_amount)), 2),
                    'penalties' => $loanPenalties->map(fn (Penalty $penalty) => [
                        'id' => $penalty->id,
                        'emi_due_date' => $penalty->emi_due_date,
                        'penalty_date' => $penalty->penalty_date,
                        'penalty_amount' => (float) $penalty->penalty_amount,
                        'paid_amount' => (float) $penalty->paid_amount,
                        'pending_amount' => round(max(0, (float) $penalty->penalty_amount - (float) $penalty->paid_amount), 2),
                        'status' => $penalty->status,
                    ])->values(),
                ];
            })
            ->values();

        return [
            'count' => $rows->count(),
            'total_pending_penalty' => round($rows->sum('pending_penalty_amount'), 2),
            'rows' => $rows,
        ];
    }
}
