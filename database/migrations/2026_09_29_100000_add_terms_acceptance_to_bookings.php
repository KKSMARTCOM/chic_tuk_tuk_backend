<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La preuve d'acceptation des CGU sur une réservation publique (2026-09-29).
 *
 * Deux colonnes NULLABLES : les réservations existantes, et celles que l'administration
 * saisit pour un client, n'ont pas de case cochée — rien ne doit l'inventer. Additive,
 * donc compatible avec l'image précédente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });
    }
};
