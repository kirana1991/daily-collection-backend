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
            'period' => ['nullable', 'in:all,today,yesterday,month'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $period = $data['period'] ?? 'all';
        $query = CollectionEntry::with(['client', 'loan', 'employee', 'user', 'receipt']);

        if ($period === 'today') {
            $query->whereDate('collection_date', now()->toDateString());
        } elseif ($period === 'yesterday') {
            $query->whereDate('collection_date', now()->subDay()->toDateString());
        } elseif ($period === 'month') {
            $query
                ->whereYear('collection_date', now()->year)
                ->whereMonth('collection_date', now()->month);
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
