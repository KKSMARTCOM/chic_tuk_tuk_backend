<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * L'inscription d'un abonnement (spec 2026-10-14, §3.1), rattachée à la course PARENTE.
 *
 * ⚠️ Le jeton est gardé en clair : l'admin recopie le lien depuis la fiche. Il ne donne
 * accès qu'au formulaire de CETTE inscription, tant qu'elle n'est pas envoyée.
 */
class SubscriptionEnrollment extends Model
{
    use HasFactory, HasUuid;

    public const TOKEN_LENGTH = 64;

    public const LINK_DAYS = 30;

    protected $fillable = [
        'booking_id', 'token', 'token_expires_at', 'client_name', 'whatsapp', 'email', 'home_address',
        'kind', 'school_name', 'school_contact', 'workplace', 'work_hours', 'pickup_landmark',
        'dropoff_landmark', 'commitments_accepted_at', 'id_document_type', 'id_document_other',
        'id_document_path', 'id_document_uploaded_at', 'id_document_purged_at', 'submitted_at',
        'updated_by', 'corrected_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'commitments_accepted_at' => 'datetime',
        'id_document_uploaded_at' => 'datetime',
        'id_document_purged_at' => 'datetime',
        'submitted_at' => 'datetime',
        'corrected_at' => 'datetime',
    ];

    public static function newToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    public static function isWellFormedToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{'.self::TOKEN_LENGTH.'}$/', $token) === 1;
    }

    public function url(): string
    {
        return rtrim((string) config('app.front_landing_url'), '/').'/inscription/'.$this->token;
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function people()
    {
        return $this->hasMany(SubscriptionEnrollmentPerson::class, 'enrollment_id')->orderBy('position');
    }
}
