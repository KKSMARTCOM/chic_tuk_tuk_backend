<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le montant investi, porté par le contrat (2026-10-14).
 *
 * Il n'est plus le même pour tous les propriétaires : il se saisit à la création du
 * contrat, et le réglage par durée ne sert plus qu'à le proposer. Les contrats existants
 * reçoivent le montant des réglages de leur durée — l'écran du propriétaire ne change pas
 * au déploiement —, puis se corrigent un par un. Une durée sans montant laisse le contrat
 * vide, comme elle laissait la tuile à « — ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_contracts', function (Blueprint $table) {
            $table->decimal('invested_amount', 12, 2)->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE vehicle_contracts AS c
            SET invested_amount = t.invested_amount
            FROM vehicle_contract_terms AS t
            WHERE t.months = c.contract_months
        SQL);
    }

    public function down(): void
    {
        Schema::table('vehicle_contracts', function (Blueprint $table) {
            $table->dropColumn('invested_amount');
        });
    }
};
