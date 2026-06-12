<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CollectionEntry;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\User;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $startOfWeek = now()->startOfWeek()->toDateString();
        $startOfMonth = now()->startOfMonth()->toDateString();
        $activeLoans = Loan::where('status', 'active')->get();

        return [
            'metrics' => [
                'today_collection' => CollectionEntry::whereDate('collection_date', $today)->sum('amount_collected'),
                'week_collection' => CollectionEntry::whereDate('collection_date', '>=', $startOfWeek)->sum('amount_collected'),
                'month_collection' => CollectionEntry::whereDate('collection_date', '>=', $startOfMonth)->sum('amount_collected'),
                'active_clients' => Client::whereHas('loans', fn ($query) => $query->where('status', 'active'))->count(),
                'overdue_clients' => $activeLoans
                    ->filter(fn (Loan $loan) => $loan->overdueInstallments()->isNotEmpty())
                    ->unique('client_id')
                    ->count(),
                'total_outstanding' => round($activeLoans->sum(fn ($loan) => $loan->outstandingEmi()), 2),
                'penalty_outstanding' => round($activeLoans->sum(fn ($loan) => $loan->outstandingPenalty()), 2),
                'closed_loans' => Loan::where('status', 'closed')->count(),
                'force_closure_cases' => Loan::where('status', 'force_closed')->count(),
            ],
            'collections_graph' => CollectionEntry::selectRaw('collection_date, sum(amount_collected) as total')
                ->groupBy('collection_date')
                ->orderBy('collection_date')
                ->limit(10)
                ->get(),
            'employee_performance' => Employee::where('role', 'collection_executive')
                ->withCount('loans')
                ->withSum(['collections as month_collection' => fn ($query) => $query->whereDate('collection_date', '>=', $startOfMonth)], 'amount_collected')
                ->get()
                ->map(function (Employee $employee) {
                    $lastCollection = $employee->collections()
                        ->whereNotNull('latitude')
                        ->latest('collected_at')
                        ->first();

                    $employee->last_location = $lastCollection ? [
                        'latitude' => $lastCollection->latitude,
                        'longitude' => $lastCollection->longitude,
                        'accuracy' => $lastCollection->location_accuracy,
                        'address' => $lastCollection->location_address ?: 'Map location saved',
                        'collected_at' => $lastCollection->collected_at,
                    ] : null;

                    return $employee;
                }),
            'collection_by_user' => User::query()
                ->withCount('collections')
                ->withSum(['collections as today_collection' => fn ($query) => $query->whereDate('collection_date', $today)], 'amount_collected')
                ->withSum(['collections as yesterday_collection' => fn ($query) => $query->whereDate('collection_date', $yesterday)], 'amount_collected')
                ->withSum(['collections as month_collection' => fn ($query) => $query->whereDate('collection_date', '>=', $startOfMonth)], 'amount_collected')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                    'status' => $user->status,
                    'collections_count' => $user->collections_count,
                    'today_collection' => round((float) $user->today_collection, 2),
                    'yesterday_collection' => round((float) $user->yesterday_collection, 2),
                    'month_collection' => round((float) $user->month_collection, 2),
                ]),
            'pending_dues' => $activeLoans->load(['client', 'employee', 'responsibleUser'])->map(function (Loan $loan) {
                $overdueInstallments = $loan->overdueInstallments();
                $dueToday = $loan->dueTodayAmount();

                return [
                    'id' => $loan->id,
                    'loan_code' => $loan->loan_code,
                    'client' => $loan->client,
                    'employee' => $loan->employee,
                    'responsible_user' => $loan->responsibleUser,
                    'loan_amount' => (float) $loan->loan_amount,
                    'pending_due' => round($overdueInstallments->sum(fn ($installment) => $installment->outstandingAmount()), 2),
                    'overdue_installments_count' => $overdueInstallments->count(),
                    'overdue_installments' => $overdueInstallments->map(fn ($installment) => [
                        'id' => $installment->id,
                        'due_date' => $installment->due_date,
                        'amount_due' => round($installment->outstandingAmount(), 2),
                    ])->values(),
                    'oldest_due_date' => $overdueInstallments->first()?->due_date,
                    'due_today' => round($dueToday, 2),
                    'penalty_due' => round($loan->outstandingPenalty(), 2),
                    'next_due_date' => $loan->next_due_date,
                    'status' => $loan->status,
                ];
            })->filter(fn (array $loan) => $loan['pending_due'] > 0 || $loan['due_today'] > 0 || $loan['penalty_due'] > 0)
                ->sortByDesc(fn (array $loan) => $loan['pending_due'] + $loan['penalty_due'])
                ->values(),
            'recent_clients' => Client::with('employee', 'loans')->latest()->limit(5)->get(),
        ];
    }
}
