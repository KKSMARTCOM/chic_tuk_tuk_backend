<?php

namespace App\Models;

use App\Traits\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Driver extends Model
{
    use HasUuid, Notifiable, HasFactory;

    protected $fillable = [
        'user_id',
        'license_number',
        //'vehicle_number',
        //'vehicle_type',
        'is_available',
        'rating',
        'total_trips',
        'agent_code',
        'agent_id',
        //'contract_type',
        //'start_date',
        //'tricycle_owner',
        //'owner_phone',
        'leave_days_used',
        'leave_dates'
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'rating' => 'decimal:2',
        'leave_dates' => 'array',
    ];

    protected $appends = [
        'available_leave_days',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'driver_id', 'id');
    }

    public function commissions()
    {
        return $this->hasMany(Commission::class, 'driver_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'driver_id', 'id');
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'driver_id', 'id');
    }

    public function hasConflictWithinTwoHours(Carbon $pickupDatetime): bool
    {
        $windowStart = $pickupDatetime->copy()->subHours(2);
        $windowEnd   = $pickupDatetime->copy()->addHours(2);

        return $this->bookings()
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->whereRaw("CONCAT(pickup_date, ' ', pickup_time) BETWEEN ? AND ?", [$windowStart->format('Y-m-d H:i:s'), $windowEnd->format('Y-m-d H:i:s')])
            ->exists();
    }

    public function hasOngoingTrip(): bool
    {
        return $this->bookings()
            ->where('status', 'in_progress')
            ->exists();
    }

    /**
     * L'agent a-t-il des courses antérieures non soldées ?
     *
     * ⚠️ Corrigé le 2026-09-18. La version antérieure comparait deux chaînes qui
     * n'étaient pas construites de la même façon :
     *
     *   - à gauche, PostgreSQL rendait CONCAT(pickup_date, ' ', pickup_time), soit
     *     « 2026-09-19 07:00:00 » ;
     *   - à droite, PHP concaténait $booking->pickup_date . ' ' . $booking->pickup_time.
     *     Or `pickup_date` est casté en `date`, donc en Carbon, et sa conversion en
     *     chaîne rend « 2026-09-19 00:00:00 ». Le repère valait donc
     *     « 2026-09-19 00:00:00 10:00 » — la date, MINUIT, puis l'heure collée derrière.
     *
     * La comparaison lexicographique butait au douzième caractère ('7' > '0') et aucune
     * course du même jour n'était jamais vue comme antérieure : un agent pouvait démarrer
     * sa course de 10:00 en laissant celle de 07:00 en plan, ce que ce refus existe
     * précisément pour empêcher.
     *
     * La comparaison porte désormais sur des TIMESTAMPS et non sur des chaînes. C'est
     * l'idiome déjà utilisé par ListAvailableBookings pour trier, et il supprime la
     * classe entière de ces bugs plutôt que cette seule instance : le résultat ne dépend
     * plus ni du réglage DateStyle de PostgreSQL, ni de la façon dont PHP rend un Carbon.
     *
     * La comparaison reste STRICTE : la course visée figure dans $this->bookings() et
     * porte le même repère qu'elle-même, donc un `<=` ferait qu'aucune course ne pourrait
     * plus jamais démarrer.
     */
    public function hasBlockingPreviousBookings(Booking $currentBooking): bool
    {
        $repere = Carbon::parse($currentBooking->pickup_date)->format('Y-m-d')
            . ' ' . Carbon::parse($currentBooking->pickup_time)->format('H:i:s');

        return $this->bookings()
            ->whereRaw('(pickup_date::date + pickup_time::time) < ?::timestamp', [$repere])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->exists();
    }

    // Leave management methods
    public function ongoingLeaveRequest()
    {
        return $this->hasOne(LeaveRequest::class, 'driver_id', 'id')->where('status', 'ongoing');
    }

    public function isOnLeaveToday(): bool
    {
        $ongoing = $this->ongoingLeaveRequest()->first();
        return $ongoing && $ongoing->start_date->lte(now()->startOfDay());
    }

    public function hasOngoingLeave(): bool
    {
        return $this->leaveRequests()->where('status', 'ongoing')->exists();
    }

    public function getLeaveDaysPerMonth(): int
    {
        return 2; // 2 days per month
    }

    /**
     * La durée du contrat en mois.
     *
     * ⚠️ Rend **0** quand l'agent n'a AUCUN contrat actif. Auparavant, le `?? 24` par
     * défaut s'appliquait aussi dans ce cas, et l'écran des pauses annonçait donc
     * « 48 jours de congés » à quelqu'un qui n'a pas de contrat du tout — un chiffre
     * inventé, présenté comme un droit acquis. Constaté le 2026-09-21 sur un agent réel.
     *
     * Le défaut par défaut de 24 mois reste, mais seulement lorsqu'un contrat EXISTE
     * sans durée renseignée : là, il comble une donnée manquante au lieu d'en fabriquer
     * une de toutes pièces.
     */
    public function getContractMonths(): int
    {
        if (! $this->activeDriverContract) {
            return 0;
        }

        return (int) ($this->activeDriverContract->contract_months ?? 24);
    }

    /**
     * Les jours de pause d'un statut donné, sur le contrat ACTIF.
     *
     * ⚠️ La portée au contrat n'est pas un raffinement : c'est ce que le reste du code
     * suppose déjà. Le droit annoncé vaut `2 × contract_months`, donc celui du contrat en
     * cours ; et `DriverContractService` remet `leave_days_used` à zéro quand un contrat
     * se termine, ce qui dit explicitement que le compteur repart avec chaque contrat.
     *
     * Sans cette portée, les pauses d'un contrat PRÉCÉDENT se déduisaient du solde du
     * contrat courant. Sur un agent sans contrat actif, l'écran affichait
     * « disponibles : -5 » — cinq jours d'un contrat révolu retranchés d'une acquisition
     * nulle. Constaté le 2026-09-21.
     *
     * Toutes les pauses en base portent un `driver_contract_id` (vérifié), donc aucune
     * ligne n'est perdue par ce filtre.
     */
    public function getLeaveRequestsByStatus(string $status): int
    {
        $contrat = $this->activeDriverContract;

        // Pas de contrat actif : aucun solde en cours, donc rien à décompter. Les pauses
        // passées appartiennent à un contrat clos et ne regardent plus ce calcul.
        if (! $contrat) {
            return 0;
        }

        return $this->leaveRequests()
            ->where('status', $status)
            ->where('driver_contract_id', $contrat->id)
            ->get()
            ->sum(fn($leave) => $leave->effective_days ?? $leave->requested_days ?? 0);
    }

    public function getTotalLeaveDays(): int
    {
        return $this->getLeaveDaysPerMonth() * $this->getContractMonths();
    }

    /**
     * Les jours de pause RÉELLEMENT pris, comptés depuis les pauses terminées.
     *
     * ⚠️ Et non depuis la colonne `leave_days_used`. Celle-ci n'est alimentée que par
     * `markLeaveDaysUsed()`, à la clôture d'une pause par un administrateur, et elle
     * était restée à zéro sur des agents dont l'historique affichait cinq jours : l'écran
     * montrait « Jours utilisés : 0 » juste au-dessus de la liste qui le démentait.
     *
     * On compte les jours EFFECTIFS quand ils sont connus — une pause écourtée n'a pas
     * consommé ce qui avait été demandé.
     */
    public function getLeaveDaysTaken(): int
    {
        return $this->getLeaveRequestsByStatus('completed');
    }

    public function getRemainingLeaveDays(): int
    {
        return $this->getTotalLeaveDays() - $this->getLeaveDaysTaken();
    }

    public function getContractMonthsElapsed(): int
    {
        if (!$this->activeDriverContract?->start_date) {
            return 0;
        }

        $start = Carbon::parse($this->activeDriverContract->start_date)->startOfDay();

        $now = now()->startOfDay();

        if ($now->lt($start)) {
            return 0;
        }

        // ⚠️ `diffInMonths` rend un FLOTTANT dans les versions récentes de Carbon — 2.2
        // pour deux mois et six jours. Le laisser tel quel déclenchait un avertissement de
        // dépréciation PHP à chaque lecture de l'écran des pauses, et deviendra une erreur
        // en PHP 9. La troncature explicite donne exactement le même résultat : seuls les
        // mois RÉVOLUS comptent, plus le mois en cours par le `+ 1`.
        $revolus = (int) $start->diffInMonths($now);

        return min($revolus + 1, $this->getContractMonths());
    }

    public function getAccruedLeaveDays(): int
    {
        return $this->getLeaveDaysPerMonth() * $this->getContractMonthsElapsed();
    }

    public function getAvailableLeaveDaysAttribute(): int
    {
        return $this->getAccruedLeaveDays()
            - $this->getLeaveRequestsByStatus('ongoing')
            - $this->getLeaveRequestsByStatus('pending')
            - $this->getLeaveRequestsByStatus('completed');
    }

    /**
     * ── Compteur indicatif, alimenté à la clôture d'une pause ──
     *
     * ⚠️ `leave_days_used` n'est PLUS la source de vérité des jours pris : elle avait
     * dérivé de la réalité sur des agents existants, faute d'avoir toujours été mise à
     * jour. `getLeaveDaysTaken()` recompte depuis les pauses terminées, qui ne peuvent
     * pas mentir. La colonne est conservée — des écrans d'administration la lisent
     * encore par l'intermédiaire de ce modèle — mais ne doit plus être lue directement.
     */
    public function markLeaveDaysUsed(int $days): void
    {
        $this->leave_days_used = max(0, ($this->leave_days_used ?? 0) + $days);
        $this->save();
    }

    // Nouvelles relations
    public function driverContracts()
    {
        return $this->hasMany(DriverContract::class);
    }

    public function activeDriverContract()
    {
        return $this->hasOne(DriverContract::class)->where('status', 'active');
    }

    // currentVehicle via le contrat actif
    public function currentVehicle()
    {
        return $this->hasOneThrough(
            Vehicle::class,
            DriverContract::class,
            'driver_id',
            'id',
            'id',
            'vehicle_id'
        )->where('driver_contracts.status', 'active');
    }
}
