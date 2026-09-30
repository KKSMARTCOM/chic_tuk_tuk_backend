<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le montant investi par le propriétaire, par durée de contrat (2026-09-30).
 *
 * Une donnée d'AFFICHAGE, lue selon la durée du contrat : elle n'est pas copiée sur le
 * contrat, contrairement au versement et à la taxe journaliers, qui déterminent de
 * l'argent. Les durées existantes reçoivent le montant de la simulation financière,
 * identique pour 24, 30 et 36 mois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_contract_terms', function (Blueprint $table) {
            $table->decimal('invested_amount', 12, 2)->nullable();
        });

        DB::table('vehicle_contract_terms')->update(['invested_amount' => 1_799_500]);
    }

    public function down(): void
    {
        Schema::table('vehicle_contract_terms', function (Blueprint $table) {
            $table->dropColumn('invested_amount');
        });
    }
};
