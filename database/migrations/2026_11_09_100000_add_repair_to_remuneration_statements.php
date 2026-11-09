<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La réparation du tricycle sur la fiche (spec 2026-11-09) : deux saisies de brouillon.
 * `repair_opened` — montant ouvert sur cette fiche (null = 0) ; `repair_deducted` — retenu ce
 * mois (null = la valeur proposée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remuneration_statements', function (Blueprint $table) {
            $table->decimal('repair_opened', 12, 2)->nullable();
            $table->decimal('repair_deducted', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('remuneration_statements', function (Blueprint $table) {
            $table->dropColumn(['repair_opened', 'repair_deducted']);
        });
    }
};
