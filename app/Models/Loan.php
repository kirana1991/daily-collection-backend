<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Loan extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Loan $loan): void {
            $loan->first_emi_date ??= $loan->next_due_date ?? $loan->loan_date;
        });
    }

    protected $casts = [
        'loan_date' => 'date',
        'first_emi_date' => 'date',
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

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class);
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

    public function outstandingPenalty(bool $sync = true): float
    {
        if ($sync) {
            $this->syncCurrentPenalties();
        }

        return (float) $this->penalties()
            ->selectRaw('COALESCE(SUM(CASE WHEN penalty_amount > paid_amount THEN penalty_amount - paid_amount ELSE 0 END), 0) as outstanding')
            ->value('outstanding');
    }

    public function syncCurrentPenalties(): int
    {
        $this->ensureInstallmentSchedule();

        return $this->accruePenaltiesThrough(now()->startOfDay());
    }

    public function applyPenaltyPayment(float $amount, bool $sync = true): void
    {
        $remaining = $amount;

        if ($remaining <= 0) {
            return;
        }

        if ($sync) {
            $this->syncCurrentPenalties();
        }

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

    public function removePenaltiesAfter(string $collectionDate, string $emiDueDate): int
    {
        return $this->penalties()
            ->whereDate('emi_due_date', $emiDueDate)
            ->whereDate('penalty_date', '>', $collectionDate)
            ->where('paid_amount', 0)
            ->delete();
    }

    public function ensureInstallmentSchedule(): void
    {
        $installmentAmount = $this->scheduledInstallmentAmount();

        if ($installmentAmount <= 0) {
            return;
        }

        $remaining = (float) $this->loan_amount;
        $dueDate = ($this->first_emi_date ?? $this->next_due_date ?? $this->loan_date)->copy()->startOfDay();
        $installmentNumber = 1;

        while ($remaining > 0) {
            $amountDue = min($remaining, $installmentAmount);

            $this->installments()->updateOrCreate(
                ['installment_number' => $installmentNumber],
                [
                    'due_date' => $dueDate->toDateString(),
                    'amount_due' => $amountDue,
                ],
            );

            $remaining -= $amountDue;
            $installmentNumber++;
            $dueDate->addDays($this->collectionIntervalDays());
        }

        $this->installments()->where('installment_number', '>=', $installmentNumber)->delete();
    }

    public function scheduledInstallmentAmount(): float
    {
        return (float) ($this->loan_type === '100_days'
            ? $this->daily_collection_amount
            : $this->weekly_emi);
    }

    public function overdueInstallments(): Collection
    {
        $this->ensureInstallmentSchedule();

        return $this->installments()
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereColumn('paid_amount', '<', 'amount_due')
            ->orderBy('due_date')
            ->get();
    }

    public function dueTodayInstallments(): Collection
    {
        $this->ensureInstallmentSchedule();

        return $this->installments()
            ->whereDate('due_date', now()->toDateString())
            ->whereColumn('paid_amount', '<', 'amount_due')
            ->orderBy('due_date')
            ->get();
    }

    public function overdueEmiAmount(): float
    {
        return (float) $this->overdueInstallments()->sum(
            fn (LoanInstallment $installment) => $installment->outstandingAmount(),
        );
    }

    public function dueTodayAmount(): float
    {
        return (float) $this->dueTodayInstallments()->sum(
            fn (LoanInstallment $installment) => $installment->outstandingAmount(),
        );
    }

    public function accruePenaltiesThrough(Carbon $throughDate): int
    {
        $synced = 0;
        $installments = $this->installments()
            ->whereDate('due_date', '<', $throughDate->toDateString())
            ->whereColumn('paid_amount', '<', 'amount_due')
            ->get();

        foreach ($installments as $installment) {
            for (
                $penaltyDate = $installment->due_date->copy()->addDay();
                $penaltyDate->lessThanOrEqualTo($throughDate);
                $penaltyDate->addDay()
            ) {
                $penalty = $this->penalties()
                    ->where('loan_installment_id', $installment->id)
                    ->whereDate('penalty_date', $penaltyDate->toDateString())
                    ->first() ?? $this->penalties()->make([
                        'loan_installment_id' => $installment->id,
                        'penalty_date' => $penaltyDate->toDateString(),
                    ]);

                $penalty->fill([
                    'client_id' => $this->client_id,
                    'emi_due_date' => $installment->due_date->toDateString(),
                    'penalty_days' => 1,
                    'penalty_per_day' => $this->penalty_per_day,
                    'penalty_amount' => $this->penalty_per_day,
                    'status' => (float) $penalty->paid_amount >= (float) $this->penalty_per_day ? 'paid' : 'pending',
                ])->save();

                $synced++;
            }
        }

        return $synced;
    }

    public function rebuildCollectionAccounting(): void
    {
        $this->penalties()->delete();
        $this->ensureInstallmentSchedule();
        $this->installments()->update([
            'paid_amount' => 0,
            'status' => 'pending',
        ]);
        $collections = $this->collections()
            ->orderBy('collection_date')
            ->orderBy('collected_at')
            ->orderBy('id')
            ->get();

        foreach ($collections as $collection) {
            $collectionDate = $collection->collection_date->copy()->startOfDay();
            $this->accruePenaltiesThrough($collectionDate);
            $remaining = (float) $collection->amount_collected;
            $emiAmount = $this->applyInstallmentPayment($remaining, $collectionDate, dueOnly: true);
            $remaining -= $emiAmount;
            $penaltyAmount = min($remaining, $this->outstandingPenalty(false));
            $this->applyPenaltyPayment($penaltyAmount, false);
            $remaining -= $penaltyAmount;
            $emiAmount += $this->applyInstallmentPayment($remaining, $collectionDate, dueOnly: false);

            $collection->update([
                'emi_amount' => $emiAmount,
                'penalty_amount' => $penaltyAmount,
            ]);
        }

        $nextInstallment = $this->installments()
            ->whereColumn('paid_amount', '<', 'amount_due')
            ->orderBy('due_date')
            ->first();
        $this->update([
            'next_due_date' => $nextInstallment?->due_date->toDateString(),
            'status' => $nextInstallment ? 'active' : 'closed',
        ]);

        if ($nextInstallment) {
            $this->refresh()->syncCurrentPenalties();
        }
    }

    private function applyInstallmentPayment(float $amount, Carbon $collectionDate, bool $dueOnly): float
    {
        $remaining = $amount;
        $query = $this->installments()
            ->whereColumn('paid_amount', '<', 'amount_due')
            ->orderBy('due_date');

        if ($dueOnly) {
            $query->whereDate('due_date', '<=', $collectionDate->toDateString());
        } else {
            $query->whereDate('due_date', '>', $collectionDate->toDateString());
        }

        foreach ($query->get() as $installment) {
            if ($remaining <= 0) {
                break;
            }

            $applied = min($remaining, $installment->outstandingAmount());
            $paidAmount = (float) $installment->paid_amount + $applied;
            $installment->update([
                'paid_amount' => $paidAmount,
                'status' => $paidAmount >= (float) $installment->amount_due ? 'paid' : 'partial',
            ]);
            $remaining -= $applied;
        }

        return $amount - $remaining;
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
