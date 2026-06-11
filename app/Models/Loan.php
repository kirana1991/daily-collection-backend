<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Loan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'loan_date' => 'date',
        'next_due_date' => 'date',
        'expected_closure_date' => 'date',
        'force_closure_validity_date' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CollectionEntry::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }

    public function paidEmi(): float
    {
        return (float) $this->collections()->sum('emi_amount');
    }

    public function paidPenalty(): float
    {
        return (float) $this->collections()->sum('penalty_amount');
    }

    public function outstandingEmi(): float
    {
        return max(0, (float) $this->loan_amount - $this->paidEmi());
    }

    public function accruedPenalty(): float
    {
        $this->syncCurrentPenalties();

        return (float) $this->penalties()->sum('penalty_amount');
    }

    public function outstandingPenalty(): float
    {
        $this->syncCurrentPenalties();

        return (float) $this->penalties()
            ->selectRaw('COALESCE(SUM(CASE WHEN penalty_amount > paid_amount THEN penalty_amount - paid_amount ELSE 0 END), 0) as outstanding')
            ->value('outstanding');
    }

    public function syncCurrentPenalties(): int
    {
        if (! $this->next_due_date || $this->outstandingEmi() <= 0) {
            return 0;
        }

        $today = now()->startOfDay();
        $dueDate = $this->next_due_date->copy()->startOfDay();

        if ($today->lessThanOrEqualTo($dueDate)) {
            return 0;
        }

        $synced = 0;

        for ($penaltyDate = $dueDate->copy()->addDay(); $penaltyDate->lessThanOrEqualTo($today); $penaltyDate->addDay()) {
            $penalty = $this->penalties()
                ->whereDate('penalty_date', $penaltyDate->toDateString())
                ->first() ?? $this->penalties()->make([
                    'penalty_date' => $penaltyDate->toDateString(),
                ]);

            $penalty->fill([
                'client_id' => $this->client_id,
                'emi_due_date' => $dueDate->toDateString(),
                'penalty_days' => 1,
                'penalty_per_day' => $this->penalty_per_day,
                'penalty_amount' => $this->penalty_per_day,
                'status' => (float) $penalty->paid_amount >= (float) $this->penalty_per_day ? 'paid' : 'pending',
            ])->save();

            $synced++;
        }

        return $synced;
    }

    public function applyPenaltyPayment(float $amount): void
    {
        $remaining = $amount;

        if ($remaining <= 0) {
            return;
        }

        $this->syncCurrentPenalties();

        $this->penalties()
            ->whereColumn('paid_amount', '<', 'penalty_amount')
            ->orderBy('penalty_date')
            ->get()
            ->each(function (Penalty $penalty) use (&$remaining): void {
                if ($remaining <= 0) {
                    return;
                }

                $outstanding = (float) $penalty->penalty_amount - (float) $penalty->paid_amount;
                $applied = min($remaining, $outstanding);
                $paidAmount = (float) $penalty->paid_amount + $applied;

                $penalty->update([
                    'paid_amount' => $paidAmount,
                    'status' => $paidAmount >= (float) $penalty->penalty_amount ? 'paid' : 'partial',
                ]);

                $remaining -= $applied;
            });
    }

    public function collectionIntervalDays(): int
    {
        return $this->loan_type === '100_days' ? 1 : 7;
    }

    public function nextDueDateFrom(string $date): string
    {
        return Carbon::parse($date)->addDays($this->collectionIntervalDays())->toDateString();
    }

    public function firstDueDateFrom(string $date): string
    {
        return Carbon::parse($date)
            ->addDays($this->loan_type === '100_days' ? 0 : 7)
            ->toDateString();
    }
}
