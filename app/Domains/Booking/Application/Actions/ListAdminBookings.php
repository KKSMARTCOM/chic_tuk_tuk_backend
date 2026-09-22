<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use Illuminate\Support\Collection;

/**
 * Les réservations, vues de l'administration — ex-Admin\BookingController::index().
 *
 * Les trois filtres du Blade, repris tels quels : le statut, une recherche libre, et le
 * sens du tri par date de création.
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
     * @param  array{status?: ?string, search?: ?string, sort?: ?string}  $filters
     * @return Collection<int, Booking>
     */
    public function __invoke(array $filters = []): Collection
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

        return $query->orderBy('created_at', $sort)->get();
    }
}
