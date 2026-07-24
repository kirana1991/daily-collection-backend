<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Penalty;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function show(Request $request, string $type)
    {
        return match ($type) {
            'pending-dues' => $this->pendingDuesReport($request),
            'penalty' => $this->penaltyReport($request),
            default => response()->json(['message' => 'Unknown report type'], 404),
        };
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
