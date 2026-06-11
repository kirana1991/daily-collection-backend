<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('responsible_user_id')
                ->nullable()
                ->after('employee_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::table('users')
            ->whereNotNull('employee_id')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('loans')
                    ->where('employee_id', $user->employee_id)
                    ->whereNull('responsible_user_id')
                    ->update(['responsible_user_id' => $user->id]);
            });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_user_id');
        });
    }
};
