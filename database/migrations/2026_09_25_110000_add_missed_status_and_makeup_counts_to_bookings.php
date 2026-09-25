<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les courses enfants d'abonnement non traitées, et leur rattrapage.
 *
 * - le statut `missed` (« Non traitée ») s'ajoute à la contrainte de `status` ;
 * - deux compteurs sur le PARENT disent combien de trajets restent dus au client, un par
 *   sens : un aller-retour dont seul le retour a été manqué ne rattrape que le retour.
 */
return new class extends Migration
{
    private const STATUSES_BEFORE = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'expired'];

    public function up(): void
    {
        $this->replaceStatusConstraint([...self::STATUSES_BEFORE, 'missed']);

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('makeup_go_count')->default(0)->after('remaining_days');
            $table->unsignedInteger('makeup_return_count')->default(0)->after('makeup_go_count');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['makeup_go_count', 'makeup_return_count']);
        });

        // Une course non traitée redevient ce qu'elle aurait été avant : expirée.
        DB::table('bookings')->where('status', 'missed')->update(['status' => 'expired']);
        $this->replaceStatusConstraint(self::STATUSES_BEFORE);
    }

    private function replaceStatusConstraint(array $statuses): void
    {
        $list = implode(', ', array_map(fn ($s) => "'{$s}'", $statuses));

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_status_check');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_check CHECK (status IN ({$list}))");
    }
};
