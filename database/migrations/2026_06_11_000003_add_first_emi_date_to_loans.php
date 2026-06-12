<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->date('first_emi_date')->nullable()->after('weekly_emi');
        });

        DB::table('loans')->orderBy('id')->each(function ($loan): void {
            $hasCollections = DB::table('collection_entries')
                ->where('loan_id', $loan->id)
                ->exists();

            $firstEmiDate = $hasCollections
                ? Carbon::parse($loan->loan_date)
                    ->addDays($loan->loan_type === '100_days' ? 0 : 7)
                    ->toDateString()
                : ($loan->next_due_date ?: $loan->loan_date);

            DB::table('loans')
                ->where('id', $loan->id)
                ->update(['first_emi_date' => $firstEmiDate]);
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->date('first_emi_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('first_emi_date');
        });
    }
};
