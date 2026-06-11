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
        Schema::table('penalties', function (Blueprint $table) {
            $table->date('emi_due_date')->nullable()->after('loan_id');
            $table->date('penalty_date')->nullable()->after('emi_due_date');
        });

        DB::table('penalties')
            ->select('loan_id', DB::raw('MIN(due_date) as first_penalty_date'))
            ->groupBy('loan_id')
            ->orderBy('loan_id')
            ->each(function ($group): void {
                $emiDueDate = Carbon::parse($group->first_penalty_date)->subDay()->toDateString();

                DB::table('penalties')
                    ->where('loan_id', $group->loan_id)
                    ->update([
                        'emi_due_date' => $emiDueDate,
                        'penalty_date' => DB::raw('due_date'),
                    ]);
            });

        Schema::table('penalties', function (Blueprint $table) {
            $table->dropIndex(['loan_id', 'due_date']);
            $table->dropIndex(['status', 'due_date']);
            $table->dropColumn('due_date');
        });

        Schema::table('penalties', function (Blueprint $table) {
            $table->date('emi_due_date')->nullable(false)->change();
            $table->date('penalty_date')->nullable(false)->change();
            $table->unique(['loan_id', 'penalty_date']);
            $table->index(['status', 'penalty_date']);
            $table->index(['loan_id', 'emi_due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('loan_id');
        });

        DB::table('penalties')->update([
            'due_date' => DB::raw('penalty_date'),
        ]);

        Schema::table('penalties', function (Blueprint $table) {
            $table->dropUnique(['loan_id', 'penalty_date']);
            $table->dropIndex(['status', 'penalty_date']);
            $table->dropIndex(['loan_id', 'emi_due_date']);
            $table->dropColumn(['emi_due_date', 'penalty_date']);
            $table->index(['loan_id', 'due_date']);
            $table->index(['status', 'due_date']);
        });
    }
};
