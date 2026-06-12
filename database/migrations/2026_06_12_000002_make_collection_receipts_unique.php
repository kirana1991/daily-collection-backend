<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('receipts')
            ->whereNotNull('collection_entry_id')
            ->select('collection_entry_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('collection_entry_id')
            ->orderBy('collection_entry_id')
            ->each(function ($row): void {
                DB::table('receipts')
                    ->where('collection_entry_id', $row->collection_entry_id)
                    ->where('id', '!=', $row->keep_id)
                    ->delete();
            });

        Schema::table('receipts', function (Blueprint $table) {
            $table->unique('collection_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique(['collection_entry_id']);
        });
    }
};
