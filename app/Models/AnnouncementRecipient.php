<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un destinataire d'un communiqué, arrêté à l'envoi.
 *
 * ⚠️ `read_at` est la PREMIÈRE ouverture : il ne s'écrase jamais (`ReadAnnouncement`).
 * `profil` est celui du compte au moment de l'envoi, pour le suivi.
 *
 * `notified_at`, `emailed_at`/`email_skipped`, `reminder_notified_at` et `reminder_emailed_at`
 * disent ce qui a déjà été remis : le job de remise les consulte pour ne rien envoyer deux
 * fois s'il est rejoué. `email_skipped` vaut `failed` quand l'envoi a échoué.
 */
class AnnouncementRecipient extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'announcement_id', 'user_id', 'profil', 'notified_at', 'read_at', 'emailed_at', 'email_skipped',
        'reminded_count', 'reminder_notified_at', 'reminder_emailed_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'read_at' => 'datetime',
        'emailed_at' => 'datetime',
        'reminder_notified_at' => 'datetime',
        'reminder_emailed_at' => 'datetime',
        'reminded_count' => 'integer',
    ];

    public function announcement()
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
