<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use App\Models\User;
use App\Shared\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use App\Shared\Http\ApiException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Les réservations, vues de l'administration — ex-Admin\BookingController::index().
 *
 * Les trois filtres du Blade : le statut, une recherche libre, et le sens du tri par date
 * de création — sous la convention commune des listes depuis le 2026-09-28. Depuis le
 * 2026-10-05, le TYPE (`filter[kind]=subscription|single`) et l'AGENT
 * (`filter[driver_id]`, les courses qu'il a prises).
 *
 * ⚠️ La liste est PAGINÉE depuis le 2026-09-22. Elle ne l'était pas, contrairement à
 * celle des pauses : une flotte se compte en dizaines d'agents, mais les réservations
 * s'accumulent sans fin — chaque course de chaque jour y reste. Tout renvoyer aurait fini
 * par charger des milliers de lignes dans un seul appel.
 *
 * ⚠️ La recherche porte sur QUATRE colonnes — numéro, téléphone, départ, arrivée — et pas
 * sur le nom du client. C'est ce que fait le contrôleur Blade, et ce n'est pas un oubli à
 * corriger ici : `client_name` n'est renseigné que sur les courses créées depuis
 * l'administration, si bien qu'une recherche par nom ne trouverait qu'une partie des
 * courses et laisserait croire que les autres n'existent pas. Un demi-résultat est pire
 * qu'un résultat absent.
 */
final class ListAdminBookings
{
    /** Les colonnes que la recherche libre traverse. */
    private const SEARCHABLE = ['booking_number', 'phone', 'from_location', 'to_location'];

    /**
     * @param  array<string, mixed>  $params  `filter[status|search|kind|driver_id]`, `sort` (created_at — `-created_at`
     *                                         par défaut —, booking_number, base_price, pickup_at,
     *                                         client_name, driver_name), `page`, `per_page`
     * @return LengthAwarePaginator<Booking>
     */
    public function __invoke(array $params = []): LengthAwarePaginator
    {
        // `parentBooking` est chargé pour le libellé des courses filles et des retours :
        // sans lui, chaque ligne de la liste déclencherait sa propre requête, et une liste
        // de cent courses en ferait cent.
        $bookings = Booking::query()->with(['user', 'driver.user', 'parentBooking.user']);

        // La convention commune des listes depuis le 2026-09-28 (`ListQuery`) : le filtre
        // `status`, la recherche, et le tri par date de création dans les deux sens — les
        // plus récentes d'abord, comme le Blade. Le socle départage les dates identiques
        // par l'identifiant : le cron crée toutes les courses filles d'un abonnement dans
        // la même seconde.
        return ListQuery::paginate(ListQuery::build($bookings, $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                ListQuery::exact('status'),
                // L'agent qui a PRIS la course : une journée d'abonnement qui lui est
                // réservée (`subscription_driver_id`) sans être acceptée n'est pas la sienne.
                ListQuery::exact('driver_id'),
                AllowedFilter::callback('kind', fn (Builder $q, $value) => self::filterKind($q, (string) $value)),
                ListQuery::search(fn (Builder $q, string $search) => $q->where(function (Builder $inner) use ($search) {
                    foreach (self::SEARCHABLE as $column) {
                        $inner->orWhere($column, 'LIKE', "%{$search}%");
                    }
                })),
            ])
            ->allowedSorts([
                'created_at', 'booking_number', 'base_price',
                // Les colonnes du tableau, triées sur toute la liste depuis le 2026-09-28.
                AllowedSort::callback('pickup_at', fn (Builder $q, bool $descending) => $q
                    ->orderBy('pickup_date', $descending ? 'desc' : 'asc')
                    ->orderBy('pickup_time', $descending ? 'desc' : 'asc')),
                // Ce que l'écran affiche : le nom saisi, sinon celui du compte.
                AllowedSort::callback('client_name', fn (Builder $q, bool $descending) => $q->orderByRaw(
                    'COALESCE(bookings.client_name, (SELECT users.name FROM users WHERE users.id = bookings.user_id)) '.($descending ? 'desc' : 'asc'),
                )),
                AllowedSort::callback('driver_name', fn (Builder $q, bool $descending) => $q->orderBy(
                    User::select('users.name')
                        ->join('drivers', 'drivers.user_id', '=', 'users.id')
                        ->whereColumn('drivers.id', 'bookings.driver_id')
                        ->limit(1),
                    $descending ? 'desc' : 'asc',
                )),
            ])
            ->defaultSort('-created_at')), $params);
    }

    /**
     * Abonnement = le parent (`is_recurring`) et ses journées (parent récurrent) ; course
     * unique = tout le reste, retour d'une course simple compris. La règle de
     * `DescribesBookingKind`, la colonne « Type » de l'écran, traduite en SQL.
     */
    private static function filterKind(Builder $query, string $kind): void
    {
        $subscription = fn (Builder $q) => $q
            ->where('is_recurring', true)
            ->orWhereHas('parentBooking', fn (Builder $parent) => $parent->where('is_recurring', true));

        match ($kind) {
            '' => null,
            'subscription' => $query->where($subscription),
            'single' => $query->whereNot($subscription),
            default => throw new ApiException(400, 'INVALID_LIST_QUERY', 'Ce tri ou ce filtre n\'est pas proposé par cette liste.'),
        };
    }
}
