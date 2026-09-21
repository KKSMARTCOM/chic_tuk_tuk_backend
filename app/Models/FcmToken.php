<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Un appareil enregistré pour recevoir les notifications push.
 *
 * ⚠️ `HasUuid` n'est pas décoratif ici. La colonne `id` est un `uuid` NOT NULL sans
 * valeur par défaut ; sans ce trait, Eloquent croit la clé auto-incrémentée, omet la
 * colonne à l'insertion, et PostgreSQL refuse la ligne. `FcmController::store()` levait
 * donc une QueryException à chaque appel, aucun appareil n'était jamais enregistré, et
 * le seul déclencheur de notification de l'application parcourait toujours une liste
 * vide. Corrigé le 2026-09-21 ; `FcmTokenStorageTest` verrouille les trois cas.
 */
class FcmToken extends Model
{
    use HasUuid;

    protected $table = 'fcm_tokens';

    protected $fillable = [
        'user_id',
        'token',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
