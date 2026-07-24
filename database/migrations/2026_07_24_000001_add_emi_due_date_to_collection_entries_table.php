<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_entries', function (Blueprint $table): void {
            $table->date('emi_due_date')->nullable()->after('collection_date');
        });
    }

    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table): void {
            $table->dropColumn('emi_due_date');
        });
    }
};
