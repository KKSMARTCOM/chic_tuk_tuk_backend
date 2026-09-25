<?php

namespace App\Models;

use App\Traits\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasUuid, Notifiable, HasFactory;

    protected $fillable = [
        'booking_number',
        'user_id',
        'driver_id',
        'tourist_circuit_id',
        'from_zone_id',
        'to_zone_id',
        'phone',
        'days',
        'remaining_days',
        'makeup_go_count',
        'makeup_return_count',
        'pickup_date',
        'pickup_time',
        'passengers',
        'special_requests',
        'base_price',
        'status',
        'cancellation_reason',
        'started_at',
        'cancelled_at',
        'completed_at',
        'parent_booking_id',
        'is_recurring',
        'next_recurring_date',

        //Champs code promo
        'discount',
        'total_price',
        'promo_code_id',
        'commission',
        'driver_earning',

        // Nouveau champs pour calcul distance
        'from_location',
        'to_location',
        'from_lng',
        'from_lat',
        'to_lng',
        'to_lat',
        'distance',

        // Champs pour les trajets récurrents
        'week_days',
        'round_trip',
        'return_time',
        'trip_type',
        'trip_count',
        'subscription_driver_id',
        'is_revoked',
        'revoked_at',
        'revoked_by',
        'client_name',
        'subscription_end_date',
    ];

    protected $casts = [
        'pickup_date' => 'date',
        'pickup_time' => 'string',
        'started_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'next_recurring_date' => 'datetime',
        'base_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'total_price' => 'decimal:2',
        'is_recurring' => 'boolean',

        'round_trip'  => 'boolean',
        'is_revoked'  => 'boolean',
        'revoked_at'  => 'datetime',
        'subscription_end_date' => 'date',
        'remaining_days' => 'integer',
        'makeup_go_count' => 'integer',
        'makeup_return_count' => 'integer',
    ];

    // Génération du booking_number unique à la création d'une réservation
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($booking) {
            $booking->booking_number = 'CTT-' . strtoupper(Str::random(8));
        });
    }
    //*********** */
    // RELATIONS
    //*********** */
    // Une réservation appartient à un utilisateur (client)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Une réservation peut être associée à un agent (driver)
    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    // Une réservation peut être associée à un circuit touristique
    public function touristCircuit()
    {
        return $this->belongsTo(TouristCircuit::class);
    }

    // Une réservation peut être associée à un code promo
    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }

    // Une réservation peut avoir un témoignage
    public function testimonial()
    {
        return $this->hasOne(Testimonial::class);
    }

    // Réservations parent/enfant pour les trajets récurrents
    public function parentBooking()
    {
        return $this->belongsTo(Booking::class, 'parent_booking_id');
    }

    // Réservations enfants pour les trajets récurrents
    public function childBookings()
    {
        return $this->hasMany(Booking::class, 'parent_booking_id');
    }

    // Relation agent abonnement
    public function subscriptionDriver()
    {
        return $this->belongsTo(Driver::class, 'subscription_driver_id');
    }

    //*********** */
    // ACCESSORS
    //*********** */
    // Formatage de l'heure de prise en charge en HH:MM pour affichage
    public function getPickupTimeFormattedAttribute()
    {
        if (!$this->pickup_time) {
            return null;
        }

        return $this->pickup_time instanceof Carbon
            ? $this->pickup_time->format('H:i')
            : Carbon::parse($this->pickup_time)->format('H:i');
    }

    // Combinaison de la date et de l'heure de prise en charge pour affichage ou calculs
    public function getPickupDateTimeAttribute()
    {
        $date = $this->pickup_date instanceof Carbon ? $this->pickup_date->format('Y-m-d') : $this->pickup_date;
        $time = $this->pickup_time_formatted;

        return trim($date . ' ' . $time);
    }

    // Aperçu commission (calculé à la volée si pas encore enregistré)
    public function getCommissionPreviewAttribute(): int
    {
        if (!is_null($this->commission) && $this->commission > 0) {
            return (int) $this->commission;
        }

        return (int) ceil((($this->base_price * 15) / 100) / 50) * 50;
    }

    // Aperçu revenu agent
    public function getDriverEarningPreviewAttribute(): int
    {
        if (!is_null($this->driver_earning) && $this->driver_earning > 0) {
            return (int) $this->driver_earning;
        }

        return (int) $this->base_price - $this->commission_preview;
    }

    // Accessors pour timestamps
    public function getStartedAtTimestampAttribute()
    {
        return $this->started_at
            ? Carbon::parse($this->started_at)
            ->timezone(config('app.timezone'))
            ->timestamp
            : null;
    }

    // Accessor pour durée de la course
    public function getDurationAttribute()
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }

        $seconds = $this->started_at->diffInSeconds($this->completed_at);

        return gmdate('H:i:s', $seconds);
    }

    // Indique si c'est un abonnement parent
    public function getIsSubscriptionParentAttribute(): bool
    {
        return $this->is_recurring && is_null($this->parent_booking_id);
    }

    // Indique si c'est une course enfant d'abonnement
    public function getIsSubscriptionChildAttribute(): bool
    {
        return !is_null($this->parent_booking_id) && $this->is_recurring === false
            && optional($this->parentBooking)->is_recurring === true;
    }

    // Indique si c'est un retour d'une course simple
    public function getIsSimpleReturnAttribute(): bool
    {
        return $this->trip_type === 'return'
            && !is_null($this->parent_booking_id)
            && optional($this->parentBooking)->is_recurring === false;
    }

    // Numéro de la course dans l'abonnement (ex: "Course 3")
    public function getSubscriptionIndexAttribute(): ?int
    {
        if (!$this->parent_booking_id) {
            return null;
        }

        // Compte les courses créées avant celle-ci dans le même abonnement
        return Booking::where('parent_booking_id', $this->parent_booking_id)
            ->where('created_at', '<=', $this->created_at)
            ->count();
    }

    // Label complet ex: "Course 3 — Abonnement CTT-XXXXXXXX"
    public function getSubscriptionLabelAttribute(): string
    {
        if ($this->is_subscription_parent) {
            return 'Abonn. parent — ' . $this->booking_number;
        }

        if ($this->is_subscription_child) {
            $parent = $this->parentBooking;
            $index  = $this->subscription_index + 1; // +1 car le parent = J1
            return "Course {$index} — Abonn. {$parent?->booking_number}";
        }

        return 'Course unique';
    }

    //*********** */
    // MÉTHODES DE LOGIQUE MÉTIER
    //*********** */
    // Vérifie si la réservation peut être annulée (non encore complétée, annulée ou expirée)
    public function canBeCancelled()
    {
        return $this->status !== 'completed' &&
            $this->status !== 'cancelled' &&
            $this->status !== 'expired' &&
            $this->status !== 'missed';
    }

    // Vérifie si la réservation peut être démarrée (acceptée par un agent et pas encore commencée)
    public function canBeCompleted()
    {
        return $this->status === 'in_progress';
    }

    // Vérifie si la course est visible par un agent donné
    public function isVisibleToDriver(string $driverId): bool
    {
        // Course unique → visible par tous
        if (!$this->is_recurring) {
            return true;
        }

        // Course d'abonnement révoquée → visible par tous comme course libre
        if ($this->is_revoked) {
            return true;
        }

        // Abonnement sans agent lié encore → visible par tous
        if (!$this->subscription_driver_id) {
            return true;
        }

        // Abonnement avec agent lié → uniquement cet agent
        return $this->subscription_driver_id === $driverId;
    }
}
