<?php

namespace App\Domains\Identity\Domain;

/**
 * La famille d'une permission, pour la ranger à l'écran : « Voir les réservations » et
 * « Créer une réservation » vont ensemble. Le Blade les alignait en une liste plate de
 * soixante-dix cases.
 *
 * La famille se lit dans le nom technique, `{verbe}-{ressource}` : `view-own-*` forme
 * celle de l'espace propriétaire. L'ordre suit à peu près celui du menu.
 */
final class PermissionFamily
{
    /** Ressource => libellé, dans l'ordre d'affichage. */
    private const LABELS = [
        'dashboard' => 'Tableau de bord',
        'bookings' => 'Réservations',
        'leaves' => 'Pauses',
        'leave-requests' => 'Demandes de pause',
        'drivers' => 'Agents',
        'owners' => 'Propriétaires',
        'vehicles' => 'Véhicules',
        'vehicle-pauses' => 'Pauses véhicule',
        'contracts' => 'Contrats',
        'commissions' => 'Commissions',
        'payments' => 'Paiements',
        'users' => 'Administrateurs',
        'roles' => 'Rôles',
        'permissions' => 'Permissions',
        'promo-codes' => 'Codes promo',
        'circuits' => 'Circuits',
        'zones' => 'Zones',
        'testimonials' => 'Avis',
        'reports' => 'Rapports',
        'settings' => 'Paramètres',
        'own' => 'Espace propriétaire',
    ];

    private const OTHER = 'Autres';

    public static function of(string $permission): string
    {
        return self::LABELS[self::resource($permission)] ?? self::OTHER;
    }

    /** Position de la famille à l'écran ; les familles inconnues ferment la marche. */
    public static function rank(string $family): int
    {
        $position = array_search($family, array_values(self::LABELS), true);

        return $position === false ? count(self::LABELS) : $position;
    }

    /**
     * Clé de tri d'une permission : sa famille dans l'ordre du menu, puis « voir »,
     * créer, modifier, supprimer — l'ordre de lecture.
     */
    public static function sortKey(string $permission): string
    {
        $verbs = ['view' => 0, 'create' => 1, 'edit' => 2, 'delete' => 3];
        $verb = strtok($permission, '-');

        return sprintf('%03d-%d-%s', self::rank(self::of($permission)), $verbs[$verb] ?? 4, $permission);
    }

    private static function resource(string $permission): string
    {
        $resource = str_contains($permission, '-') ? substr($permission, strpos($permission, '-') + 1) : $permission;

        return str_starts_with($resource, 'own-') ? 'own' : $resource;
    }
}
