<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rend leur statut d'abonnement aux abonnements déjà terminés.
 *
 * Jusqu'ici, la génération de la dernière course enfant passait le parent à
 * `is_recurring = false` : l'abonnement et ses enfants sortaient du récap des revenus du
 * dossier agent. Un parent d'abonnement se reconnaît sans ambiguïté — pas de parent et
 * plus d'un jour —, puisque c'est la seule règle qui en crée un (`days > 1`, dans
 * `BookingService::create()` comme à l'édition).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')
            ->whereNull('parent_booking_id')
            ->where('days', '>', 1)
            ->where('is_recurring', false)
            ->update(['is_recurring' => true]);
    }

    /** Rien à défaire : l'ancien état était le défaut. */
    public function down(): void {}
};
