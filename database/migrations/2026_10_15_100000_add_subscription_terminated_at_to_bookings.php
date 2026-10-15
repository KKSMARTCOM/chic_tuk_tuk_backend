<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La date de résiliation d'un abonnement dont le premier jour a eu lieu (2026-10-15).
 *
 * `TerminateSubscription` laisse le parent dans son statut : rien ne gardait le jour de la
 * résiliation. La pièce d'identité de l'inscription est effacée 3 mois après lui, et non
 * 3 mois après la fin prévue. Les abonnements déjà résiliés restent sans date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('subscription_terminated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('subscription_terminated_at');
        });
    }
};
