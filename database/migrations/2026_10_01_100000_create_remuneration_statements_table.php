<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les fiches de rémunération (spec 2026-09-30, §4).
 *
 * Un brouillon ne stocke que ses SAISIES (prélèvements, reste d'ouverture, note) : ses
 * chiffres se recalculent à chaque lecture. À la validation, tout est figé dans
 * `figures`, un JSON — une fiche validée ne se relit plus jamais depuis les paiements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remuneration_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_contract_id');
            $table->foreign('vehicle_contract_id')->references('id')->on('vehicle_contracts')->cascadeOnDelete();
            $table->date('month');
            $table->string('status')->default('draft');
            $table->string('number')->nullable()->unique();

            // `null` = la valeur proposée par le compte de charges.
            $table->decimal('deducted_internet', 10, 2)->nullable();
            $table->decimal('deducted_spotify', 10, 2)->nullable();
            $table->decimal('deducted_manager', 12, 2)->nullable();
            // Le reste à recouvrer d'avant la mise en service, saisi sur la PREMIÈRE fiche.
            $table->decimal('opening_internet', 10, 2)->default(0);
            $table->decimal('opening_spotify', 10, 2)->default(0);
            $table->decimal('opening_manager', 12, 2)->default(0);
            $table->text('note')->nullable();

            $table->json('figures')->nullable();
            $table->decimal('balance_due', 12, 2)->nullable();
            $table->uuid('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->uuid('replaces_id')->nullable();
            $table->timestamps();

            $table->index(['vehicle_contract_id', 'month']);
        });

        // Une seule fiche VIVANTE par contrat et par mois : une fiche annulée libère son
        // mois pour le brouillon qui la remplace.
        DB::statement("CREATE UNIQUE INDEX remuneration_statements_live_month ON remuneration_statements (vehicle_contract_id, month) WHERE status <> 'cancelled'");

        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('remuneration_statement_id')->nullable();
            $table->foreign('remuneration_statement_id')->references('id')->on('remuneration_statements')->nullOnDelete();
            $table->index('remuneration_statement_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['remuneration_statement_id']);
            $table->dropColumn('remuneration_statement_id');
        });
        Schema::dropIfExists('remuneration_statements');
    }
};
