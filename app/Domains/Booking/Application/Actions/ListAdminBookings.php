<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Les réservations, vues de l'administration — ex-Admin\BookingController::index().
 *
 * Les trois filtres du Blade, repris tels quels : le statut, une recherche libre, et le
 * sens du tri par date de création.
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

    /** Vingt-cinq lignes : ce qu'un écran de bureau montre sans défilement excessif. */
    public const PER_PAGE = 25;

    /** Plafond de sécurité : `per_page=100000` ne doit pas pouvoir tout charger. */
    private const MAX_PER_PAGE = 100;

    /**
     * @param  array{status?: ?string, search?: ?string, sort?: ?string, page?: ?int, per_page?: ?int}  $filters
     * @return LengthAwarePaginator<Booking>
     */
    public function __invoke(array $filters = []): LengthAwarePaginator
    {
        $query = Booking::query()
            // `parentBooking` est chargé pour le libellé des courses filles et des
            // retours : sans lui, chaque ligne de la liste déclencherait sa propre
            // requête, et une liste de cent courses en ferait cent.
            ->with(['user', 'driver.user', 'parentBooking.user']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                foreach (self::SEARCHABLE as $column) {
                    $q->orWhere($column, 'LIKE', "%{$search}%");
                }
            });
        }

        // Tout sauf `asc` vaut `desc` : les plus récentes en premier, comme le Blade.
        $sort = ($filters['sort'] ?? null) === 'asc' ? 'asc' : 'desc';

        $perPage = (int) ($filters['per_page'] ?? self::PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return $query
            ->orderBy('created_at', $sort)
            // ⚠️ Un second critère de tri, sur une colonne UNIQUE. `created_at` n'est pas
            // unique — le cron crée toutes les courses filles d'un abonnement dans la
            // même seconde — et PostgreSQL ne garantit alors aucun ordre stable entre
            // deux requêtes. Sans cela, une même course peut apparaître sur deux pages
            // ou sur aucune.
            ->orderBy('id', $sort)
            ->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }
}
