<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qui a déjà été remis à chaque destinataire (relecture finale du 2026-10-13).
 *
 * ⚠️ Un job de remise tué par le délai du worker est rejoué par la file. Sans ces marques,
 * le rejouer renvoyait cloche, push et e-mail à ceux qui les avaient déjà reçus. Les marques
 * de relance valent `announcements.last_reminded_at` de la relance remise : une relance
 * suivante porte une autre date, et repart donc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcement_recipients', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('profil');
            $table->timestamp('reminder_notified_at')->nullable()->after('reminded_count');
            $table->timestamp('reminder_emailed_at')->nullable()->after('reminder_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcement_recipients', function (Blueprint $table) {
            $table->dropColumn(['notified_at', 'reminder_notified_at', 'reminder_emailed_at']);
        });
    }
};
