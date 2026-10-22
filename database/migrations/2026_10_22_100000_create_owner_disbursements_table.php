<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les versements faits au propriétaire (spec 2026-10-22-versement-proprietaire, §3.1) : ils
 * réduisent son revenu dû, sans être rattachés à un mois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_disbursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Un contrat qui a des versements ne se supprime pas (§4.6) : la clé sert de filet.
            $table->foreignUuid('vehicle_contract_id')->constrained('vehicle_contracts')->restrictOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_original_name')->nullable();
            $table->string('proof_mime_type')->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('owner_notified_at')->nullable();
            $table->timestamps();
            $table->index(['vehicle_contract_id', 'status']);
        });

        DB::statement("ALTER TABLE owner_disbursements ADD CONSTRAINT owner_disbursements_method_check CHECK (method IN ('mobile_money', 'bank_transfer'))");
        DB::statement("ALTER TABLE owner_disbursements ADD CONSTRAINT owner_disbursements_status_check CHECK (status IN ('recorded', 'cancelled'))");
        DB::statement('ALTER TABLE owner_disbursements ADD CONSTRAINT owner_disbursements_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_disbursements');
    }
};
