<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le contrat propriétaire « en attente » (spec 2026-10-09, §3.1) : sa date de début est vide
 * si et seulement si son statut est `pending`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_contracts', fn ($table) => $table->date('start_date')->nullable()->change());

        DB::statement("ALTER TABLE vehicle_contracts ADD CONSTRAINT vehicle_contracts_pending_start_date
            CHECK ((status = 'pending') = (start_date IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vehicle_contracts DROP CONSTRAINT vehicle_contracts_pending_start_date');
        Schema::table('vehicle_contracts', fn ($table) => $table->date('start_date')->nullable(false)->change());
    }
};
