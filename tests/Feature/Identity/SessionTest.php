<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    private function login(Profil $profil = Profil::Driver): array
    {
        $user = User::factory()->profil($profil)->create([
            'email' => 'agent@chictuktuk.com',
            'password' => Hash::make('bon-mot-de-passe'),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@chictuktuk.com',
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    public function test_me_renvoie_l_utilisateur_courant_avec_ses_droits(): void
    {
        [$user, $token] = $this->login(Profil::Admin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('profil', 'admin')
            ->assertJsonPath('dashboard_path', '/admin/dashboard')
            ->assertJsonStructure(['id', 'name', 'email', 'profil', 'dashboard_path', 'roles', 'permissions']);
    }

    public function test_me_exige_une_authentification(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_logout_supprime_le_jeton_courant(): void
    {
        [$user, $token] = $this->login();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());

        // Le garde Laravel mémorise l'utilisateur résolu, et cette mémoire survit
        // d'une requête à l'autre au sein d'un même test : sans cet oubli explicite,
        // /me répondrait 200 avec un jeton pourtant supprimé.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_logout_ne_touche_pas_les_autres_jetons(): void
    {
        [$user, $premier] = $this->login();

        $second = $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@chictuktuk.com',
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$premier}")
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        // Multi-appareils : déconnecter le téléphone ne déconnecte pas le poste.
        $this->assertSame(1, $user->tokens()->count());
        $this->withHeader('Authorization', "Bearer {$second}")
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_un_jeton_inactif_depuis_plus_de_quatorze_jours_est_refuse(): void
    {
        [$user, $token] = $this->login();

        $user->tokens()->update(['last_used_at' => now()->subDays(15)]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_EXPIRED');

        // Le jeton périmé est supprimé, pas seulement refusé.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_un_jeton_jamais_utilise_retombe_sur_sa_date_de_creation(): void
    {
        [$user, $token] = $this->login();

        // Les jetons émis par le chemin Blade ont last_used_at à null : Sanctum
        // résout le garde de session avant le jeton, qui n'est jamais marqué.
        $user->tokens()->update(['last_used_at' => null, 'created_at' => now()->subDays(15)]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_EXPIRED');
    }

    public function test_un_jeton_recemment_utilise_reste_valide(): void
    {
        [$user, $token] = $this->login();

        $user->tokens()->update(['last_used_at' => now()->subDays(13), 'created_at' => now()->subDays(80)]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }
}
