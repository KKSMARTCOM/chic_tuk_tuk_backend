<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La reconstitution des fiches (spec 2026-10-01).
 *
 * - `payments.collected_on` : la date d'ENCAISSEMENT. Reprise : la date de dernière
 *   modification des paiements déjà validés, meilleure approximation disponible.
 * - `remuneration_statements.issued_on` : la date d'ÉTABLISSEMENT. Reprise : le jour de la
 *   validation. Les fiches à venir sortent ainsi exactement comme avant.
 * - `remuneration_statement_numbers` : le dernier rang attribué par mois. Compter les
 *   fiches numérotées réattribuerait un numéro après la purge des fiches annulées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->date('collected_on')->nullable();
        });
        DB::statement("UPDATE payments SET collected_on = updated_at::date WHERE status = 'completed'");

        Schema::table('remuneration_statements', function (Blueprint $table) {
            $table->date('issued_on')->nullable();
            $table->string('delivery')->default('email');
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamp('pdf_purged_at')->nullable();
            $table->unsignedSmallInteger('owner_download_count')->default(0);
        });
        DB::statement('UPDATE remuneration_statements SET issued_on = validated_at::date WHERE validated_at IS NOT NULL');
        DB::statement('UPDATE remuneration_statements SET pdf_generated_at = COALESCE(sent_at, validated_at) WHERE pdf_path IS NOT NULL');

        Schema::create('remuneration_statement_numbers', function (Blueprint $table) {
            $table->date('month')->primary();
            $table->unsignedInteger('last_rank');
        });
        DB::statement('INSERT INTO remuneration_statement_numbers (month, last_rank)
            SELECT month, MAX(CAST(RIGHT(number, 3) AS integer)) FROM remuneration_statements
            WHERE number IS NOT NULL GROUP BY month');
    }

    public function down(): void
    {
        Schema::dropIfExists('remuneration_statement_numbers');
        Schema::table('remuneration_statements', function (Blueprint $table) {
            $table->dropColumn(['issued_on', 'delivery', 'pdf_generated_at', 'pdf_purged_at', 'owner_download_count']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('collected_on');
        });
    }
};
