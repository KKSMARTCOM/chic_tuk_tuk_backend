<?php

namespace App\Models;

use App\Domains\Booking\Domain\BookingLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Notification extends Model
{
    /** La notification « Nouvelle réservation disponible » des agents (2026-10-27). */
    public const KIND_BOOKING_AVAILABLE = 'booking_available';

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'type',
        'is_read',
        'data',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Les notifications à montrer : sans celles d'une course qui n'est plus libre (spec
     * 2026-10-27-liste-des-notifications, §2.2). Une course redevenue libre fait revenir
     * la sienne : la règle se lit à chaque fois, rien n'est écrit.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $q) => self::whereStaleBooking($q->getQuery()));
    }

    /**
     * La notification « course disponible » d'un agent, dont aucune course ne répond encore :
     * libre (`pending` sans agent), ou, avec `$terminalOnly`, seulement pas encore terminée.
     *
     * ⚠️ `data` est une colonne `json` sans garantie de forme : la comparaison se fait en
     * TEXTE (`bookings.id::text`), jamais en `uuid`, qui ferait échouer toute la requête
     * sur un identifiant illisible. Et `coalesce` sur `kind` : sans lui, une charge nulle
     * rendrait la condition NULL, et `NOT (NULL)` cacherait la notification.
     */
    public static function whereStaleBooking(QueryBuilder $query, bool $terminalOnly = false): QueryBuilder
    {
        return $query
            ->whereRaw("coalesce(notifications.data->>'kind', '') = ?", [self::KIND_BOOKING_AVAILABLE])
            ->whereRaw("notifications.data->>'booking_id' is not null")
            ->whereIn('notifications.user_id', User::query()->select('id')->where('profil', 'driver'))
            ->whereNotExists(fn (QueryBuilder $bookings) => $bookings->selectRaw('1')->from('bookings')
                ->whereRaw("bookings.id::text = notifications.data->>'booking_id'")
                ->when(! $terminalOnly, fn (QueryBuilder $b) => $b->where('bookings.status', 'pending')->whereNull('bookings.driver_id'))
                ->when($terminalOnly, fn (QueryBuilder $b) => $b->whereNotIn('bookings.status', BookingLifecycle::TERMINAL)));
    }

    public function getIconAttribute()
    {
        return match ($this->type) {
            'success' => 'fas fa-check-circle text-green-500',
            'warning' => 'fas fa-exclamation-triangle text-yellow-500',
            'error' => 'fas fa-times-circle text-red-500',
            default => 'fas fa-info-circle text-blue-500',
        };
    }
}
