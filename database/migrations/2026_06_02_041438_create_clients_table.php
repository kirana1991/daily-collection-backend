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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_code')->unique();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('mobile', 20);
            $table->string('alternative_mobile', 20)->nullable();
            $table->text('address');
            $table->string('village')->nullable();
            $table->string('pin_code', 12)->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guarantor_name')->nullable();
            $table->string('guarantor_mobile', 20)->nullable();
            $table->string('aadhaar_number', 20)->nullable()->unique();
            $table->string('pan_number', 20)->nullable()->unique();
            $table->date('date_of_birth')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('aadhaar_path')->nullable();
            $table->string('pan_path')->nullable();
            $table->string('cheque_path')->nullable();
            $table->string('agreement_path')->nullable();
            $table->string('verification_status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
