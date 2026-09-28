<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use App\Shared\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Les réservations, vues de l'administration — ex-Admin\BookingController::index().
 *
 * Les trois filtres du Blade : le statut, une recherche libre, et le sens du tri par date
 * de création — sous la convention commune des listes depuis le 2026-09-28.
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
     * @param  array<string, mixed>  $params  `filter[status|search]`, `sort` (`created_at` ou
     *                                         `-created_at`, par défaut), `page`, `per_page`
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
                ListQuery::search(fn (Builder $q, string $search) => $q->where(function (Builder $inner) use ($search) {
                    foreach (self::SEARCHABLE as $column) {
                        $inner->orWhere($column, 'LIKE', "%{$search}%");
                    }
                })),
            ])
            ->allowedSorts(['created_at'])
            ->defaultSort('-created_at')), $params);
    }
}
