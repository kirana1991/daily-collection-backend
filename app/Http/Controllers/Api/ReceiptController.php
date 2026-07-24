<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollectionEntry;
use App\Models\Loan;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

class ReceiptController extends Controller
{
    public function store(CollectionEntry $collection)
    {
        $collection->load(['client', 'loan.collections', 'employee', 'user']);
        $loan = $collection->loan;
        $ledger = $this->ledgerRows($loan);
        $collectionRow = collect($ledger)->firstWhere('collection_entry_id', $collection->id);
        $totalDue = $loan->outstandingEmi() + $loan->outstandingPenalty();

        $receipt = DB::transaction(function () use ($collection, $collectionRow, $ledger, $loan, $totalDue): Receipt {
            return Receipt::firstOrCreate(
                ['collection_entry_id' => $collection->id],
                [
                    'receipt_code' => 'DM-R-'.str_pad((string) (Receipt::count() + 1), 5, '0', STR_PAD_LEFT),
                    'client_id' => $loan->client_id,
                    'loan_id' => $loan->id,
                    'total_due' => $totalDue,
                    'final_payable' => $loan->force_closure_amount ?: $totalDue,
                    'ledger_snapshot' => [
                        'collection' => [
                            'id' => $collection->id,
                            'date' => $collection->collection_date->toDateString(),
                            'amount' => (float) $collection->amount_collected,
                            'emi_amount' => (float) $collection->emi_amount,
                            'penalty_amount' => (float) $collection->penalty_amount,
                            'mode' => $collection->collection_mode,
                            'collector' => $collection->user?->name ?? $collection->employee?->name,
                        ],
                        'balance_after_payment' => $collectionRow['running_balance'] ?? null,
                        'ledger' => $ledger,
                    ],
                    'generated_at' => now(),
                ],
            );
        });

        return response()->json([
            'receipt' => $receipt->load(['client', 'loan', 'collectionEntry.user', 'collectionEntry.employee']),
            'collection' => $collection,
            'balance_after_payment' => $collectionRow['running_balance'] ?? null,
        ], $receipt->wasRecentlyCreated ? 201 : 200);
    }

    private function ledgerRows(Loan $loan): array
    {
        $balance = (float) $loan->loan_amount;

        return $loan->collections()
            ->orderBy('collection_date')
            ->get()
            ->map(function ($entry) use (&$balance) {
                $payment = (float) $entry->amount_collected;
                $balance = max(0, $balance - (float) $entry->emi_amount);

                return [
                    'collection_entry_id' => $entry->id,
                    'date' => $entry->collection_date->toDateString(),
                    'particulars' => 'Collection via '.str_replace('_', ' ', $entry->collection_mode),
                    'emi_due' => (float) $entry->emi_amount,
                    'penalty' => (float) $entry->penalty_amount,
                    'payment' => $payment,
                    'running_balance' => round($balance, 2),
                    'status' => $balance <= 0 ? 'closed' : ($entry->collection_date->isPast() ? 'overdue' : 'pending'),
                ];
            })
            ->values()
            ->all();
    }
}
