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
 */
class AnnouncementRecipient extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'announcement_id', 'user_id', 'profil', 'read_at', 'emailed_at', 'email_skipped', 'reminded_count',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'emailed_at' => 'datetime',
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
