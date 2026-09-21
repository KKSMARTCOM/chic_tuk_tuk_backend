<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Source de vérité unique des rôles, des permissions et de leurs attributions.
 *
 * ## Pourquoi ce seeder existe
 *
 * Le 2026-09-17, deux divergences entre la base de développement et la production
 * ont été relevées le même jour, et chacune a coûté un aller-retour :
 *
 * - le rôle `lecteur` portait le libellé « Lecteur » en local et « Utilisateur » en
 *   ligne ;
 * - le rôle `proprietaire` avait `view-dashboard` en local et pas en ligne, ce qui
 *   vidait la barre latérale du front `client` pour un propriétaire.
 *
 * Les deux avaient la même cause. Le rôle `lecteur` n'était créé par AUCUN seeder :
 * il avait été ajouté à la main dans chaque environnement, indépendamment. Et
 * `RoleAndPermissionSeeder` utilisait `firstOrCreate`, qui ne met jamais à jour un
 * enregistrement existant — même en corrigeant la liste, les libellés n'auraient
 * jamais convergé.
 *
 * Ce fichier remplace trois seeders : `RoleAndPermissionSeeder` et ses deux
 * rustines successives, `AddMissingPermissionsSeeder` et `AddOwnerRoleSeeder`.
 * C'est l'empilement de rustines qui était le mécanisme de la dérive.
 *
 * ## Ce qu'il fait, et ce qu'il défait
 *
 * ⚠️ **Le code fait foi.** `syncPermissions` remet chaque rôle dans l'état décrit
 * ici, donc **toute attribution faite depuis l'écran d'administration des rôles est
 * temporaire** et disparaîtra à la prochaine exécution. C'est le prix de la
 * convergence, et c'est assumé : une permission qui doit durer s'ajoute ici.
 *
 * Il est en revanche non destructif sur les données : il ne supprime ni rôle ni
 * permission, et ne touche pas aux attributions faites directement à un
 * utilisateur.
 *
 * ## Note sur le rôle `lecteur`
 *
 * Son libellé de production, « Utilisateur », et la description historique
 * laissent croire à un accès en lecture. **C'est faux** : sur ses 41 permissions,
 * 27 sont des écritures — `create-drivers`, `manage-payments`, `manage-settings`,
 * `approve-leave-requests`… Il ne lui manque, par rapport à `admin`, que la gestion
 * des rôles, des permissions et des véhicules.
 *
 * Cet état est repris tel quel, sur décision explicite du 2026-09-17, pour ne rien
 * retirer à des comptes en service. Mais le libellé décrit mal ce niveau d'accès :
 * à renommer quand l'occasion se présentera, et à ne pas documenter ailleurs comme
 * un rôle en lecture seule — le `CLAUDE.md` l'a longtemps fait à tort.
 */
final class ReferenceRolesAndPermissionsSeeder extends Seeder
{
    /** Sentinelle : toutes les permissions sauf celles à portée propriétaire. */
    private const TOUTES_SAUF_PROPRIETAIRE = ['*'];

    /** Préfixe des permissions propres au propriétaire, exclues du rôle admin. */
    private const PREFIXE_PROPRIETAIRE = 'view-own-';

    /** Permissions de référence : nom technique => [libellé, description]. */
    private const PERMISSIONS = [
        // Réservations
        'create-bookings' => ['Créer une réservation', 'Créer une réservation'],
        'delete-bookings' => ['Supprimer une réservation', 'Supprimer une réservation'],
        'edit-bookings' => ['Modifier une réservation', 'Modifier une réservation'],
        'manage-bookings' => ['Gérer les réservations', 'Gérer les réservations'],
        'view-bookings' => ['Voir les réservations', 'Voir les réservations'],

        // Circuits
        'create-circuits' => ['Créer un circuit', 'Créer un circuit touristique'],
        'delete-circuits' => ['Supprimer un circuit', 'Supprimer un circuit touristique'],
        'edit-circuits' => ['Modifier un circuit', 'Modifier un circuit touristique'],
        'view-circuits' => ['Voir les circuits', 'Voir la liste des circuits touristiques'],

        // Commissions
        'manage-commissions' => ['Gérer les commissions', 'Gérer les commissions'],
        'view-commissions' => ['Voir les commissions', 'Voir les commissions'],

        // Contrats
        'manage-contracts' => ['Gérer les contrats', 'Gérer les contrats véhicule et agent'],

        // Tableau de bord
        'view-dashboard' => ['Voir le tableau de bord', 'Accès au tableau de bord'],

        // Agents
        'create-drivers' => ['Créer un chauffeur', 'Créer un chauffeur'],
        'delete-drivers' => ['Supprimer un chauffeur', 'Supprimer un chauffeur'],
        'edit-drivers' => ['Modifier un chauffeur', 'Modifier un chauffeur'],
        'export-drivers' => ['Exporter les agents', 'Exporter la liste des agents'],
        'import-drivers' => ['Importer des agents', 'Importer des agents via fichier'],
        'view-drivers' => ['Voir les chauffeurs', 'Voir les chauffeurs'],

        // Demandes de congé
        'approve-leave-requests' => ['Approuver une demande', 'Approuver une demande de congé'],
        'reject-leave-requests' => ['Rejeter une demande', 'Rejeter une demande de congé'],
        'view-leave-requests' => ['Voir les demandes de congé', 'Voir les demandes de congé'],

        // Congés
        'create-leaves' => ['Créer un congé', 'Ajouter un congé à un agent'],
        'delete-leaves' => ['Révoquer un congé', 'Révoquer un congé d\'un agent'],
        'view-leaves' => ['Voir les congés', 'Voir les congés des agents'],

        // Propriétaire — ses contrats
        'view-own-contracts' => ['Voir ses contrats', 'Voir ses contrats véhicule'],

        // Propriétaire — ses pauses
        'view-own-leaves' => ['Voir les pauses véhicule', 'Voir les pauses liées à ses véhicules'],

        // Propriétaire — ses paiements
        'view-own-payments' => ['Voir ses paiements', 'Voir les paiements liés à ses véhicules'],

        // Propriétaire — ses véhicules
        'view-own-vehicles' => ['Voir ses véhicules', 'Voir les véhicules du propriétaire'],

        // Paiements
        'create-payments' => ['Créer un paiement', 'Créer un paiement'],
        'manage-payments' => ['Gérer les paiements', 'Gérer les paiements'],
        'view-payments' => ['Voir les paiements', 'Voir les paiements'],

        // Permissions
        'create-permissions' => ['Créer une permission', 'Créer une nouvelle permission'],
        'delete-permissions' => ['Supprimer une permission', 'Supprimer une permission'],
        'edit-permissions' => ['Modifier une permission', 'Modifier une permission existante'],
        'view-permissions' => ['Voir les permissions', 'Voir la liste des permissions'],

        // Tarifs
        'create-pricing' => ['Créer un tarif', 'Créer un tarif'],
        'edit-pricing' => ['Modifier un tarif', 'Modifier un tarif'],
        'manage-pricing' => ['Gérer les tarifs', 'Gérer les tarifs'],
        'view-pricing' => ['Voir les tarifs', 'Voir les tarifs'],

        // Codes promo
        'create-promo-codes' => ['Créer un code promo', 'Créer un code promo'],
        'delete-promo-codes' => ['Supprimer un code promo', 'Supprimer un code promo'],
        'edit-promo-codes' => ['Modifier un code promo', 'Modifier un code promo'],
        'view-promo-codes' => ['Voir les codes promo', 'Voir la liste des codes promo'],

        // Rapports
        'export-reports' => ['Exporter les rapports', 'Exporter les rapports'],
        'view-reports' => ['Voir les rapports', 'Voir les rapports'],

        // Rôles
        'create-roles' => ['Créer un rôle', 'Créer un nouveau rôle'],
        'delete-roles' => ['Supprimer un rôle', 'Supprimer un rôle'],
        'edit-roles' => ['Modifier un rôle', 'Modifier un rôle existant'],
        'view-roles' => ['Voir les rôles', 'Voir la liste des rôles'],

        // Réglages
        'manage-settings' => ['Gérer les paramètres', 'Gérer les paramètres'],

        // Avis
        'moderate-testimonials' => ['Modérer les avis', 'Modérer les avis'],
        'view-testimonials' => ['Voir les avis', 'Voir les avis'],

        // Utilisateurs
        'create-users' => ['Créer un utilisateur', 'Créer un utilisateur'],
        'delete-users' => ['Supprimer un utilisateur', 'Supprimer un utilisateur'],
        'edit-users' => ['Modifier un utilisateur', 'Modifier un utilisateur'],
        'view-users' => ['Voir les utilisateurs', 'Voir les utilisateurs'],

        // Pauses véhicule
        'manage-vehicle-pauses' => ['Gérer les pauses véhicule', 'Gérer les pauses des véhicules'],

        // Véhicules
        'create-vehicles' => ['Créer un véhicule', 'Créer un véhicule'],
        'delete-vehicles' => ['Supprimer un véhicule', 'Supprimer un véhicule'],
        'edit-vehicles' => ['Modifier un véhicule', 'Modifier un véhicule'],
        'view-vehicles' => ['Voir les véhicules', 'Voir tous les véhicules'],

        // Zones
        'create-zones' => ['Créer une zone', 'Créer une zone'],
        'edit-zones' => ['Modifier une zone', 'Modifier une zone'],
        'manage-zones' => ['Gérer les zones', 'Gérer les zones'],
        'view-zones' => ['Voir les zones', 'Voir les zones'],
    ];

    /** Rôles de référence. Le nom technique est stable : du code en dépend. */
    private const ROLES = [
        'admin' => [
            'label' => 'Administrateur',
            'description' => 'Accès complet à toutes les fonctionnalités du système',
            // Calculé : toutes les permissions SAUF les `view-own-*`, à portée
            // propriétaire et sans objet pour un administrateur. Calculé et non
            // listé, pour qu'une permission ajoutée plus tard lui revienne d'office.
            'permissions' => self::TOUTES_SAUF_PROPRIETAIRE,
        ],
        'lecteur' => [
            'label' => 'Utilisateur',
            'description' => 'Accès aux fonctionnalités de lectures',
            'permissions' => [
                'approve-leave-requests',
                'create-bookings',
                'create-circuits',
                'create-drivers',
                'create-leaves',
                'create-payments',
                'create-pricing',
                'create-promo-codes',
                'create-zones',
                'delete-leaves',
                'edit-bookings',
                'edit-circuits',
                'edit-drivers',
                'edit-pricing',
                'edit-promo-codes',
                'edit-zones',
                'export-drivers',
                'export-reports',
                'import-drivers',
                'manage-bookings',
                'manage-commissions',
                'manage-payments',
                'manage-pricing',
                'manage-settings',
                'manage-zones',
                'moderate-testimonials',
                'reject-leave-requests',
                'view-bookings',
                'view-circuits',
                'view-commissions',
                'view-dashboard',
                'view-drivers',
                'view-leave-requests',
                'view-leaves',
                'view-payments',
                'view-pricing',
                'view-promo-codes',
                'view-reports',
                'view-testimonials',
                'view-users',
                'view-zones',
            ],
        ],
        'driver' => [
            'label' => 'Agent',
            'description' => 'Accès aux fonctionnalités liées aux réservations et aux trajets',
            'permissions' => [
                'create-bookings',
                // Ajoutée le 2026-09-18 : l'agent a un tableau de bord, en Blade comme
                // dans le front Nuxt, mais le rôle ne portait pas la permission qui le
                // désigne. La navigation du front se construisant sur les permissions
                // EFFECTIVES, l'entrée « Tableau de bord » était filtrée et l'agent
                // n'avait aucun lien vers son propre écran d'accueil.
                //
                // N'ouvre rien d'indu : /admin/dashboard et /client/dashboard portent
                // aussi `profil:admin` et `profil:client`.
                'view-dashboard',
                'edit-bookings',
                'view-bookings',
                'view-payments',
                'view-pricing',
                'view-testimonials',
                'view-zones',
            ],
        ],
        'proprietaire' => [
            'label' => 'Propriétaire',
            'description' => 'Propriétaire de véhicule',
            'permissions' => [
                'view-dashboard',
                'view-own-contracts',
                'view-own-leaves',
                'view-own-payments',
                'view-own-vehicles',
            ],
        ],
        'client' => [
            'label' => 'Client',
            'description' => 'Accès aux fonctionnalités liées aux réservations',
            'permissions' => [
                'create-bookings',
                // Ajoutée le 2026-09-21, même défaut que `driver` le 2026-09-18 et même
                // cause : `/client/dashboard` est gardé par `permission:view-dashboard`,
                // et le rôle ne la portait pas. Le client ne pouvait donc pas ouvrir son
                // propre écran d'accueil — et côté front Nuxt, la navigation se
                // construisant sur les permissions EFFECTIVES, l'entrée disparaissait du
                // menu au lieu de mener à un refus : rien n'indiquait le manque.
                //
                // N'ouvre rien d'indu : /admin/dashboard et /driver/dashboard portent
                // aussi leur propre `profil:`.
                'view-dashboard',
                'edit-bookings',
                'view-bookings',
                'view-payments',
                'view-pricing',
                'view-zones',
            ],
        ],
    ];

    public function run(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        $this->seedPermissions();
        $this->seedRoles();

        app()['cache']->forget('spatie.permission.cache');
    }

    private function seedPermissions(): void
    {
        $crees = 0;
        $misAJour = 0;

        foreach (self::PERMISSIONS as $name => [$label, $description]) {
            $permission = Permission::query()->where('name', $name)->first();

            if ($permission === null) {
                Permission::query()->create([
                    'name' => $name,
                    'label' => $label,
                    'description' => $description,
                    'guard_name' => 'web',
                ]);
                $crees++;

                continue;
            }

            // updateOrCreate et non firstOrCreate : c'est ce qui fait converger les
            // libellés entre environnements, et l'absence de cette mise à jour est
            // l'une des deux racines de la dérive que ce fichier corrige.
            if ($permission->label !== $label || $permission->description !== $description) {
                $permission->update(['label' => $label, 'description' => $description]);
                $misAJour++;
            }
        }

        $this->command?->info(sprintf(
            'Permissions : %d de référence, %d créées, %d libellés corrigés.',
            count(self::PERMISSIONS),
            $crees,
            $misAJour,
        ));

        $inconnues = Permission::query()
            ->whereNotIn('name', array_keys(self::PERMISSIONS))
            ->pluck('name');

        if ($inconnues->isNotEmpty()) {
            // Signalées et non supprimées : une permission hors référence peut être
            // une addition légitime qu'il faut remonter ici, ou un reliquat. Le
            // seeder ne tranche pas à la place d'un humain.
            $this->command?->warn(
                'Permissions en base hors référence, à examiner : '.$inconnues->implode(', ')
            );
        }
    }

    private function seedRoles(): void
    {
        foreach (self::ROLES as $name => $definition) {
            $role = Role::query()->where('name', $name)->first();

            if ($role === null) {
                $role = Role::query()->create([
                    'name' => $name,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'guard_name' => 'web',
                ]);
            } elseif ($role->label !== $definition['label'] || $role->description !== $definition['description']) {
                $role->update([
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                ]);
            }

            $role->syncPermissions($this->permissionsPour($definition['permissions']));

            $this->command?->info(sprintf(
                '  %-14s %-16s %2d permissions',
                $name,
                $definition['label'],
                $role->permissions()->count(),
            ));
        }
    }

    /** @param  list<string>  $demandees */
    private function permissionsPour(array $demandees): array
    {
        if ($demandees === self::TOUTES_SAUF_PROPRIETAIRE) {
            return array_values(array_filter(
                array_keys(self::PERMISSIONS),
                fn (string $name) => ! str_starts_with($name, self::PREFIXE_PROPRIETAIRE),
            ));
        }

        return $demandees;
    }
}
