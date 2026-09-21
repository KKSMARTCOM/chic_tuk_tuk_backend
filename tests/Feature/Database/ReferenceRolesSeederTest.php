<?php

namespace Tests\Feature\Database;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\ReferenceRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le seeder de référence est la source de vérité des rôles et permissions.
 *
 * Ces tests couvrent les quatre propriétés dont dépend sa raison d'être : il
 * converge, il est idempotent, le code fait foi, et il ne détruit pas de données.
 */
class ReferenceRolesSeederTest extends TestCase
{
    use RefreshDatabase;

    private function semer(): void
    {
        $this->seed(ReferenceRolesAndPermissionsSeeder::class);
    }

    public function test_les_cinq_roles_sont_crees_avec_leurs_libelles_de_production(): void
    {
        $this->semer();

        $attendus = [
            'admin' => 'Administrateur',
            // Renommé depuis `lecteur` le 2026-09-21. Une migration déplace les comptes
            // de l'ancien rôle vers celui-ci : changer la clé du seeder ne renomme rien
            // en base, Spatie cherchant les rôles par leur nom.
            'utilisateur' => 'Utilisateur',
            'driver' => 'Agent',
            'proprietaire' => 'Propriétaire',
            'client' => 'Client',
        ];

        foreach ($attendus as $name => $label) {
            $this->assertSame(
                $label,
                Role::query()->where('name', $name)->value('label'),
                "le libellé du rôle {$name} doit être celui de la production",
            );
        }

        $this->assertSame(5, Role::query()->count());
    }

    public function test_le_role_admin_recoit_tout_sauf_les_permissions_du_proprietaire(): void
    {
        $this->semer();

        $admin = Role::query()->where('name', 'admin')->firstOrFail();
        $noms = $admin->permissions->pluck('name');

        // Les `view-own-*` sont à portée propriétaire : elles n'ont aucun sens pour
        // un administrateur, qui voit déjà tout par les permissions globales.
        $this->assertTrue(
            $noms->filter(fn (string $n) => str_starts_with($n, 'view-own-'))->isEmpty(),
            'admin ne doit porter aucune permission à portée propriétaire',
        );

        $this->assertSame(
            Permission::query()->where('name', 'not like', 'view-own-%')->count(),
            $noms->count(),
        );
    }

    public function test_le_proprietaire_peut_voir_son_tableau_de_bord(): void
    {
        $this->semer();

        // Régression : cette permission manquait en production, ce qui vidait la
        // barre latérale du front `client` pour un propriétaire.
        $this->assertTrue(
            Role::query()->where('name', 'proprietaire')->firstOrFail()
                ->hasPermissionTo('view-dashboard'),
        );
    }

    public function test_le_seeder_corrige_un_libelle_divergent(): void
    {
        $this->semer();

        // Reproduit la divergence observée : le libellé du rôle avait été saisi
        // différemment dans chaque environnement.
        Role::query()->where('name', 'utilisateur')->update(['label' => 'Lecteur']);

        $this->semer();

        $this->assertSame(
            'Utilisateur',
            Role::query()->where('name', 'utilisateur')->value('label'),
            'un libellé divergent doit être ramené à la référence',
        );
    }

    public function test_le_code_fait_foi_sur_les_attributions(): void
    {
        $this->semer();

        $client = Role::query()->where('name', 'client')->firstOrFail();
        $avant = $client->permissions->count();

        // Simule une attribution faite à la main depuis l'administration.
        $client->givePermissionTo('manage-settings');
        $this->assertSame($avant + 1, $client->fresh()->permissions->count());

        $this->semer();

        $this->assertFalse(
            $client->fresh()->hasPermissionTo('manage-settings'),
            'une attribution hors référence doit être retirée : le code fait foi',
        );
        $this->assertSame($avant, $client->fresh()->permissions->count());
    }

    public function test_une_permission_hors_reference_est_conservee(): void
    {
        $this->semer();

        // Non destructif sur les données : une permission inconnue peut être une
        // addition légitime qu'il faut remonter dans la référence, ou un reliquat.
        // Le seeder la signale mais ne tranche pas à la place d'un humain.
        Permission::query()->create([
            'name' => 'permission-hors-reference',
            'label' => 'Ajout manuel',
            'description' => 'Ajoutée hors du seeder',
            'guard_name' => 'web',
        ]);

        $this->semer();

        $this->assertDatabaseHas('permissions', ['name' => 'permission-hors-reference']);
    }

    public function test_le_seeder_est_idempotent(): void
    {
        $this->semer();

        $empreinte = fn () => Role::query()->with('permissions')->orderBy('name')->get()
            ->mapWithKeys(fn (Role $r) => [
                $r->name => $r->label.'|'.$r->permissions->pluck('name')->sort()->implode(','),
            ])->toArray();

        $premier = $empreinte();

        $this->semer();
        $this->semer();

        $this->assertSame($premier, $empreinte(), 'trois exécutions doivent donner le même état');
        $this->assertSame(5, Role::query()->count());
    }
}
