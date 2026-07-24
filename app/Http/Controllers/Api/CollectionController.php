<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollectionEntry;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollectionController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:all,today,yesterday,week,month'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'q' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date'],
            'date_field' => ['nullable', 'in:payment,entry'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $period = $data['period'] ?? 'all';
        $dateColumn = ($data['date_field'] ?? 'payment') === 'entry'
            ? DB::raw('COALESCE(collected_at, created_at)')
            : 'collection_date';
        $query = CollectionEntry::with(['client', 'loan', 'employee', 'user', 'receipt']);

        if ($period === 'today') {
            $query->whereDate($dateColumn, now()->toDateString());
        } elseif ($period === 'yesterday') {
            $query->whereDate($dateColumn, now()->subDay()->toDateString());
        } elseif ($period === 'week') {
            $query->whereDate($dateColumn, '>=', now()->startOfWeek()->toDateString());
        } elseif ($period === 'month') {
            $query->whereDate($dateColumn, '>=', now()->startOfMonth()->toDateString());
        }

        if (! empty($data['date'])) {
            $query->whereDate($dateColumn, $data['date']);
        }

        if (! empty($data['user_id'])) {
            $query->where('user_id', $data['user_id']);
        }

        if (! empty($data['q'])) {
            $search = $data['q'];

            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('collection_mode', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('client_code', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%"))
                    ->orWhereHas('loan', fn ($loanQuery) => $loanQuery
                        ->where('loan_code', 'like', "%{$search}%"))
                    ->orWhereHas('user', fn ($userQuery) => $userQuery
                        ->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('employee', fn ($employeeQuery) => $employeeQuery
                        ->where('name', 'like', "%{$search}%"));
            });
        }

        $totalAmount = (clone $query)->sum('amount_collected');
        $collections = $query
            ->latest('collection_date')
            ->latest('collected_at')
            ->latest('id')
            ->paginate($data['per_page'] ?? 10)
            ->withQueryString();

        return response()->json([
            ...$collections->toArray(),
            'total_amount' => round((float) $totalAmount, 2),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'loan_id' => ['required', 'exists:loans,id'],
            ...$this->collectionRules(),
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0'],
            'location_address' => ['nullable', 'string'],
            'collected_at' => ['nullable', 'date'],
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $collection = DB::transaction(function () use ($data): CollectionEntry {
            $loan = Loan::with(['client', 'employee'])->lockForUpdate()->findOrFail($data['loan_id']);
            $collector = User::findOrFail($data['user_id']);

            $collection = CollectionEntry::create([
                ...$data,
                'client_id' => $loan->client_id,
                'employee_id' => $collector->role === 'collection_executive' ? $collector->employee_id : null,
                'user_id' => $collector->id,
                'emi_amount' => 0,
                'penalty_amount' => 0,
                'collected_at' => $data['collected_at'] ?? now(),
                'status' => 'approved',
            ]);

            $loan->rebuildCollectionAccounting();

            return $collection->fresh();
        });

        return response()->json($collection->load(['client', 'loan', 'employee', 'user']), 201);
    }

    public function update(Request $request, CollectionEntry $collection)
    {
        $data = $request->validate($this->collectionRules(editing: true));

        $collection = DB::transaction(function () use ($collection, $data): CollectionEntry {
            $loan = Loan::lockForUpdate()->findOrFail($collection->loan_id);
            $collection->update($data);
            $loan->rebuildCollectionAccounting();

            return $collection->fresh();
        });

        return $collection->load(['client', 'loan', 'employee', 'user']);
    }

    public function destroy(CollectionEntry $collection)
    {
        DB::transaction(function () use ($collection): void {
            $loan = Loan::lockForUpdate()->findOrFail($collection->loan_id);
            $collection->delete();
            $loan->rebuildCollectionAccounting();
        });

        return response()->noContent();
    }

    private function collectionRules(bool $editing = false): array
    {
        return [
            'collection_date' => ['required', 'date', 'before_or_equal:today'],
            'amount_collected' => ['required', 'numeric', 'min:1'],
            'collection_mode' => ['required', 'in:cash,upi,bank_transfer'],
            'remarks' => $editing ? ['prohibited'] : ['nullable', 'string'],
        ];
    }

    public function show(CollectionEntry $collection)
    {
        return $collection->load(['client', 'loan', 'employee', 'user']);
    }
}
