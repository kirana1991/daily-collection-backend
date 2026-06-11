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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('email')->constrained()->nullOnDelete();
            }
        });

        Schema::table('collection_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('collection_entries', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('collection_mode');
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
                $table->decimal('location_accuracy', 10, 2)->nullable()->after('longitude');
                $table->string('location_address')->nullable()->after('location_accuracy');
                $table->timestamp('collected_at')->nullable()->after('location_address');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            if (Schema::hasColumn('collection_entries', 'latitude')) {
                $table->dropColumn([
                    'latitude',
                    'longitude',
                    'location_accuracy',
                    'location_address',
                    'collected_at',
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'employee_id')) {
                $table->dropConstrainedForeignId('employee_id');
            }
        });
    }
};
