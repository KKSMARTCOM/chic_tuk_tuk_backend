<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le mot de la dernière relance (spec 2026-11-04-communiques-relance-ajout) : gardé sur le
 * communiqué, pour qu'une remise rejouée par la file renvoie le même texte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('last_reminder_note', 200)->nullable()->after('last_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('last_reminder_note');
        });
    }
};
