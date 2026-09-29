<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Les montants des contrats véhicule deviennent réglables par l'administration.
 *
 * Trois temps, dans une même migration parce qu'aucun ne tient sans les autres :
 *
 *   1. `vehicle_contract_terms` (une ligne par durée proposée) et
 *      `vehicle_contract_charge_defaults` (les trois charges mensuelles par défaut),
 *      amorcées avec les valeurs de l'ex-`VehicleContractConsts` ;
 *   2. chaque contrat FIGE son versement et sa taxe journaliers (`daily_amount`,
 *      `daily_tax`). ⚠️ Jusqu'ici, la génération du soir et le contrôle des paiements
 *      les relisaient dans les constantes à chaque passage : rendre les montants
 *      réglables sans les figer aurait changé dès le lendemain les versements de tous
 *      les contrats en cours ;
 *   3. les contrats existants reçoivent les valeurs de leur durée. Une durée hors des
 *      trois historiques (« autre ») n'en avait AUCUNE — la génération créait chaque soir
 *      un paiement de 0 FCFA — et reste à null : la génération l'écarte désormais et le
 *      journalise.
 *
 * Écrit en migration et non en seeder : le conteneur ne lance que `migrate --force`.
 * Colonnes nouvelles et facultatives : l'image précédente continue de fonctionner.
 */
return new class extends Migration
{
    /** Les valeurs de l'ex-`VehicleContractConsts`, recopiées ici : la classe disparaît. */
    private const TERMS = [
        24 => ['total_amount' => 3_100_000, 'daily_amount' => 6112, 'daily_tax' => 241],
        30 => ['total_amount' => 3_604_872, 'daily_amount' => 5691, 'daily_tax' => 229],
        36 => ['total_amount' => 4_049_100, 'daily_amount' => 5251, 'daily_tax' => 211],
    ];

    public function up(): void
    {
        Schema::create('vehicle_contract_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('months')->unique();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('daily_amount', 10, 2);
            $table->decimal('daily_tax', 10, 2);
            $table->timestamps();
        });

        Schema::create('vehicle_contract_charge_defaults', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('unlimited_internet', 10, 2);
            $table->decimal('spotify_premium', 10, 2);
            $table->decimal('manager_remuneration', 10, 2);
            $table->timestamps();
        });

        Schema::table('vehicle_contracts', function (Blueprint $table) {
            $table->decimal('daily_amount', 10, 2)->nullable();
            $table->decimal('daily_tax', 10, 2)->nullable();
        });

        $now = now();

        foreach (self::TERMS as $months => $term) {
            DB::table('vehicle_contract_terms')->insert([
                'id' => (string) Str::uuid(),
                'months' => $months,
                ...$term,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('vehicle_contracts')
                ->where('contract_months', $months)
                ->update(['daily_amount' => $term['daily_amount'], 'daily_tax' => $term['daily_tax']]);
        }

        DB::table('vehicle_contract_charge_defaults')->insert([
            'id' => (string) Str::uuid(),
            'unlimited_internet' => 5_000,
            'spotify_premium' => 2_500,
            'manager_remuneration' => 20_000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::table('vehicle_contracts', function (Blueprint $table) {
            $table->dropColumn(['daily_amount', 'daily_tax']);
        });

        Schema::dropIfExists('vehicle_contract_charge_defaults');
        Schema::dropIfExists('vehicle_contract_terms');
    }
};
