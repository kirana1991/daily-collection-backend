<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_code')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('loan_date');
            $table->decimal('loan_amount', 12, 2);
            $table->string('loan_type')->default('weekly');
            $table->decimal('daily_collection_amount', 12, 2)->default(0);
            $table->decimal('weekly_emi', 12, 2);
            $table->date('next_due_date')->nullable();
            $table->date('expected_closure_date')->nullable();
            $table->decimal('penalty_per_day', 10, 2)->default(50);
            $table->decimal('force_closure_amount', 12, 2)->nullable();
            $table->date('force_closure_validity_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
