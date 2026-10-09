<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'affectation interne (spec 2026-10-09, §3.2) : un agent sur un véhicule, sans paiement.
 * Table à part : aucun code des paiements ne la lit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_contract_id')->constrained('vehicle_contracts')->cascadeOnDelete();
            $table->foreignUuid('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('ended_reason')->nullable(); // manual | driver_contract
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX internal_assignments_one_ongoing_per_contract
            ON internal_assignments (vehicle_contract_id) WHERE end_date IS NULL');
        DB::statement('CREATE UNIQUE INDEX internal_assignments_one_ongoing_per_driver
            ON internal_assignments (driver_id) WHERE end_date IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_assignments');
    }
};
