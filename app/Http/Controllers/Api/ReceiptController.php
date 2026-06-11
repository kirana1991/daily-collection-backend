<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Loan;
use App\Models\Receipt;

class ReceiptController extends Controller
{
    public function ledger(Client $client)
    {
        $loan = $client->loans()->with('collections.employee')->latest()->firstOrFail();

        return [
            'client' => $client->load('employee'),
            'loan' => $loan,
            'ledger' => $this->ledgerRows($loan),
            'summary' => [
                'emi_outstanding' => round($loan->outstandingEmi(), 2),
                'penalty_outstanding' => round($loan->outstandingPenalty(), 2),
                'total_due' => round($loan->outstandingEmi() + $loan->outstandingPenalty(), 2),
                'final_payable' => round($loan->force_closure_amount ?: ($loan->outstandingEmi() + $loan->outstandingPenalty()), 2),
            ],
        ];
    }

    public function store(Loan $loan)
    {
        $loan->load('client', 'collections');
        $ledger = $this->ledgerRows($loan);
        $totalDue = $loan->outstandingEmi() + $loan->outstandingPenalty();

        return response()->json(Receipt::create([
            'receipt_code' => 'DM-R-'.str_pad((string) (Receipt::count() + 1), 5, '0', STR_PAD_LEFT),
            'client_id' => $loan->client_id,
            'loan_id' => $loan->id,
            'collection_entry_id' => $loan->collections()->latest()->value('id'),
            'total_due' => $totalDue,
            'final_payable' => $loan->force_closure_amount ?: $totalDue,
            'ledger_snapshot' => $ledger,
            'generated_at' => now(),
        ])->load('client', 'loan'), 201);
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
