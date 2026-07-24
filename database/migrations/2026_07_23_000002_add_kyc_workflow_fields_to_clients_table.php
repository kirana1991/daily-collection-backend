<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('document_verification_status')->default('pending')->after('verification_status');
            $table->json('document_verification_details')->nullable()->after('document_verification_status');
            $table->string('field_verification_status')->default('pending')->after('document_verification_details');
            $table->json('field_verification_details')->nullable()->after('field_verification_status');
            $table->timestamp('document_verified_at')->nullable()->after('field_verification_details');
            $table->timestamp('field_verified_at')->nullable()->after('document_verified_at');
        });

        DB::table('clients')
            ->where('verification_status', 'verified')
            ->update([
                'document_verification_status' => 'verified',
                'field_verification_status' => 'verified',
                'document_verified_at' => now(),
                'field_verified_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn([
                'document_verification_status',
                'document_verification_details',
                'field_verification_status',
                'field_verification_details',
                'document_verified_at',
                'field_verified_at',
            ]);
        });
    }
};
