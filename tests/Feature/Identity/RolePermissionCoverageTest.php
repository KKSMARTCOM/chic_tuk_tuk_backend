<?php

namespace Tests\Feature\Identity;

use Database\Seeders\ReferenceRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chaque rôle de référence peut-il atteindre les écrans de son propre espace ?
 *
 * ⚠️ Ce test existe parce que le défaut s'est produit DEUX fois. Le rôle `driver` ne
 * portait pas `view-dashboard` alors que `/driver/dashboard` existe — corrigé le
 * 2026-09-18 —, et le rôle `client` avait exactement le même trou, découvert dans la
 * foulée et resté ouvert jusqu'au 2026-09-21.
 *
 * Le symptôme est particulièrement sournois côté front : la navigation Nuxt se construit
 * sur les permissions EFFECTIVES, donc l'entrée disparaît du menu au lieu de mener à un
 * refus. L'utilisateur n'a aucun lien vers son propre écran d'accueil, et rien n'indique
 * qu'il manque quelque chose.
 *
 * Plutôt que de vérifier une permission à la main, on parcourt les routes déclarées :
 * toute route qui exige à la fois un profil et une permission doit être atteignable par
 * le rôle de référence de ce profil.
 */
class RolePermissionCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le rôle de référence de chaque profil.
     *
     * ⚠️ `owner` porte le rôle `proprietaire` : le nom du profil et celui du rôle ne
     * coïncident que pour trois profils sur quatre. `UserService::create()` assigne
     * `$data['role'] ?: $data['profil']`, ce qui rend l'écart facile à oublier.
     */
    private const ROLE_DU_PROFIL = [
        'admin' => 'admin',
        'driver' => 'driver',
        'client' => 'client',
        'owner' => 'proprietaire',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ La source de vérité est le SEEDER, pas l'état de la base : `syncPermissions`
        // y remet chaque rôle dans l'état décrit, donc toute attribution faite depuis
        // l'écran d'administration est temporaire. C'est le seeder qu'on éprouve.
        $this->seed(ReferenceRolesAndPermissionsSeeder::class);
    }

    /**
     * Les routes exigeant un profil ET une permission.
     *
     * @return array<int, array{profil: string, permission: string, uri: string}>
     */
    private function routesGardees(): array
    {
        $trouvees = [];

        foreach (Route::getRoutes() as $route) {
            $profil = null;
            $permissions = [];

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                if (str_starts_with($middleware, 'profil:')) {
                    $profil = substr($middleware, 7);
                }

                if (str_starts_with($middleware, 'permission:')) {
                    // Un middleware peut en lister plusieurs, séparés par des virgules :
                    // l'une suffit alors, et on les traite comme une alternative.
                    $permissions[] = explode(',', substr($middleware, 11));
                }
            }

            if ($profil === null) {
                continue;
            }

            foreach ($permissions as $alternative) {
                $trouvees[] = [
                    'profil' => $profil,
                    'permission' => $alternative,
                    'uri' => $route->uri(),
                ];
            }
        }

        return $trouvees;
    }

    public function test_chaque_role_de_reference_atteint_les_routes_de_son_espace(): void
    {
        $manquantes = [];

        foreach ($this->routesGardees() as $garde) {
            $nomRole = self::ROLE_DU_PROFIL[$garde['profil']] ?? null;

            // Un profil sans rôle de référence connu n'est pas l'objet de ce test.
            if ($nomRole === null) {
                continue;
            }

            $role = Role::where('name', $nomRole)->first();
            $this->assertNotNull($role, "le rôle de référence {$nomRole} n'existe pas");

            $portees = $role->permissions->pluck('name')->all();

            // Une seule des permissions listées suffit : c'est la sémantique de
            // `hasAnyPermission`, qu'applique le middleware `CheckPermission`.
            if (array_intersect($garde['permission'], $portees) === []) {
                $manquantes[] = sprintf(
                    '%s → /%s exige [%s]',
                    $nomRole,
                    $garde['uri'],
                    implode(' ou ', $garde['permission']),
                );
            }
        }

        $this->assertSame([], array_values(array_unique($manquantes)), implode("\n", $manquantes));
    }

    public function test_le_tableau_de_bord_de_chaque_espace_est_atteignable(): void
    {
        // Le cas concret, écrit séparément pour que l'échec nomme le vrai sujet plutôt
        // qu'une liste de routes.
        foreach (self::ROLE_DU_PROFIL as $profil => $nomRole) {
            $role = Role::where('name', $nomRole)->firstOrFail();

            $this->assertTrue(
                $role->hasPermissionTo('view-dashboard'),
                "le rôle {$nomRole} ({$profil}) ne peut pas ouvrir son propre tableau de bord",
            );
        }
    }
}
