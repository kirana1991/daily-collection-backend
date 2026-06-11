<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollectionEntry;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function index()
    {
        $fromDate = min(
            now()->startOfMonth()->toDateString(),
            now()->subDay()->toDateString(),
        );

        return CollectionEntry::with(['client', 'loan', 'employee', 'user'])
            ->whereDate('collection_date', '>=', $fromDate)
            ->latest('collection_date')
            ->latest('collected_at')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'loan_id' => ['required', 'exists:loans,id'],
            'collection_date' => ['required', 'date', 'before_or_equal:today'],
            'amount_collected' => ['required', 'numeric', 'min:1'],
            'collection_mode' => ['required', 'in:cash,upi,bank_transfer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0'],
            'location_address' => ['nullable', 'string'],
            'collected_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $loan = Loan::with(['client', 'employee'])->findOrFail($data['loan_id']);
        $collector = User::findOrFail($data['user_id']);
        $amount = (float) $data['amount_collected'];
        $emiAmount = min($amount, $loan->outstandingEmi());
        $penaltyAmount = min(max(0, $amount - $emiAmount), $loan->outstandingPenalty());

        $collection = CollectionEntry::create([
            ...$data,
            'client_id' => $loan->client_id,
            'employee_id' => $collector->role === 'collection_executive' ? $collector->employee_id : null,
            'user_id' => $collector->id,
            'emi_amount' => $emiAmount,
            'penalty_amount' => $penaltyAmount,
            'collected_at' => $data['collected_at'] ?? now(),
            'status' => 'approved',
        ]);

        $loan->applyPenaltyPayment($penaltyAmount);

        if ($loan->fresh()->outstandingEmi() <= 0) {
            $loan->update(['status' => 'closed']);
        } else {
            $loan->update(['next_due_date' => $loan->nextDueDateFrom($data['collection_date'])]);
        }

        return response()->json($collection->load(['client', 'loan', 'employee', 'user']), 201);
    }

    public function show(CollectionEntry $collection)
    {
        return $collection->load(['client', 'loan', 'employee', 'user']);
    }
}
