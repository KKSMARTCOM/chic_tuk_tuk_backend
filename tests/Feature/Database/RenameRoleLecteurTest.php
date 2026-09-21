<?php

namespace Tests\Feature\Database;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La migration qui renomme `lecteur` en `utilisateur`.
 *
 * Elle existe parce que changer la clé dans le seeder de référence ne renomme RIEN en
 * base : Spatie cherche les rôles par leur nom, donc le seeder crée un second rôle et
 * laisse le premier avec ses comptes. Observé le 2026-09-21 — deux rôles au même libellé,
 * l'ancien portant les deux seuls comptes concernés.
 *
 * ⚠️ Les trois cas sont couverts parce que la migration est REJOUÉE à chaque démarrage du
 * conteneur : `docker/start.sh` lance `migrate --force`. Une migration qui suppose un
 * état initial casserait le redémarrage suivant.
 */
class RenameRoleLecteurTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $nom, string $libelle = 'Utilisateur'): Role
    {
        return Role::create(['name' => $nom, 'guard_name' => 'web', 'label' => $libelle]);
    }

    private function compte(Role $role): User
    {
        $user = User::factory()->profil(Profil::Admin)->create();
        $user->assignRole($role);

        return $user;
    }

    /** Rejoue la migration seule, comme le ferait un redémarrage. */
    private function migrer(): void
    {
        $migration = require database_path('migrations/2026_09_21_180000_rename_role_lecteur_to_utilisateur.php');
        $migration->up();

        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_le_role_seul_est_renomme_sur_place(): void
    {
        // Renommage sur place : l'`id` ne bouge pas, donc les affectations suivent sans
        // qu'on ait à les déplacer. C'est le cas le plus sûr, et on le préfère quand il
        // est possible.
        $lecteur = $this->role('lecteur');
        $user = $this->compte($lecteur);
        $idInitial = $lecteur->id;

        $this->migrer();

        $this->assertDatabaseMissing('roles', ['name' => 'lecteur']);
        $this->assertSame($idInitial, Role::where('name', 'utilisateur')->value('id'));
        $this->assertTrue($user->fresh()->hasRole('utilisateur'));
    }

    public function test_les_comptes_sont_deplaces_quand_les_deux_roles_coexistent(): void
    {
        // ⚠️ LE cas réellement rencontré : le seeder avait déjà créé `utilisateur`, vide,
        // à côté de `lecteur` et de ses comptes.
        $lecteur = $this->role('lecteur');
        $this->role('utilisateur');

        $premier = $this->compte($lecteur);
        $second = $this->compte($lecteur);

        $this->migrer();

        $this->assertDatabaseMissing('roles', ['name' => 'lecteur']);
        $this->assertTrue($premier->fresh()->hasRole('utilisateur'), 'un compte a perdu son rôle');
        $this->assertTrue($second->fresh()->hasRole('utilisateur'), 'un compte a perdu son rôle');
    }

    public function test_un_compte_portant_deja_les_deux_roles_ne_fait_pas_echouer_la_migration(): void
    {
        // ⚠️ `model_has_roles` a une clé primaire composite : réinsérer le même triplet
        // lèverait, et ferait échouer le démarrage du conteneur entier.
        $lecteur = $this->role('lecteur');
        $utilisateur = $this->role('utilisateur');

        $user = $this->compte($lecteur);
        $user->assignRole($utilisateur);

        $this->migrer();

        $this->assertDatabaseMissing('roles', ['name' => 'lecteur']);
        $this->assertTrue($user->fresh()->hasRole('utilisateur'));
        $this->assertSame(
            1,
            DB::table('model_has_roles')->where('model_id', $user->id)->count(),
            'le compte se retrouve avec deux lignes pour le même rôle',
        );
    }

    public function test_la_migration_est_rejouable(): void
    {
        // `docker/start.sh` lance `migrate --force` à CHAQUE démarrage.
        $lecteur = $this->role('lecteur');
        $user = $this->compte($lecteur);

        $this->migrer();
        $this->migrer();
        $this->migrer();

        $this->assertTrue($user->fresh()->hasRole('utilisateur'));
        $this->assertSame(1, Role::where('name', 'utilisateur')->count());
    }

    public function test_sans_ancien_role_elle_ne_fait_rien(): void
    {
        $utilisateur = $this->role('utilisateur');
        $user = $this->compte($utilisateur);

        $this->migrer();

        $this->assertTrue($user->fresh()->hasRole('utilisateur'));
        $this->assertSame(1, Role::count());
    }

    public function test_elle_ne_touche_pas_aux_autres_roles(): void
    {
        $this->role('lecteur');
        $admin = $this->role('admin', 'Administrateur');
        $patron = $this->compte($admin);

        $this->migrer();

        $this->assertTrue($patron->fresh()->hasRole('admin'));
        $this->assertSame('Administrateur', Role::where('name', 'admin')->value('label'));
    }
}
