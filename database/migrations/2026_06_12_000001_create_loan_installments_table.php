<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('installment_number');
            $table->date('due_date');
            $table->decimal('amount_due', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['loan_id', 'installment_number']);
            $table->unique(['loan_id', 'due_date']);
            $table->index(['status', 'due_date']);
        });

        Schema::table('penalties', function (Blueprint $table) {
            $table->foreignId('loan_installment_id')
                ->nullable()
                ->after('loan_id')
                ->constrained('loan_installments')
                ->cascadeOnDelete();
        });

        Schema::table('penalties', function (Blueprint $table) {
            $table->dropUnique(['loan_id', 'penalty_date']);
            $table->unique(['loan_installment_id', 'penalty_date']);
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropUnique(['loan_installment_id', 'penalty_date']);
            $table->dropConstrainedForeignId('loan_installment_id');
            $table->unique(['loan_id', 'penalty_date']);
        });

        Schema::dropIfExists('loan_installments');
    }
};
