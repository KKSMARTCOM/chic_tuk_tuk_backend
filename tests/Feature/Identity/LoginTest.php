<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function user(Profil $profil, string $email = 'agent@chictuktuk.com'): User
    {
        return User::factory()->profil($profil)->create([
            'email' => $email,
            'password' => Hash::make('mot-de-passe-valide'),
        ]);
    }

    public function test_connexion_nominale_renvoie_un_jeton_et_le_profil(): void
    {
        $user = $this->user(Profil::Driver);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@chictuktuk.com',
            'password' => 'mot-de-passe-valide',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.profil', 'driver')
            ->assertJsonPath('user.dashboard_path', '/driver/dashboard')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'profil', 'dashboard_path', 'roles', 'permissions']]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_le_jeton_emis_est_nomme_api_et_porte_un_plafond_absolu(): void
    {
        $user = $this->user(Profil::Admin);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@chictuktuk.com',
            'password' => 'mot-de-passe-valide',
        ])->assertOk();

        $token = $user->tokens()->sole();

        // Toujours « api » : c'est le nom que portent les jetons en base (voir
        // `config/identity.php`).
        $this->assertSame('api', $token->name);
        $this->assertSame(['admin'], $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addDays(89), now()->addDays(91)));
    }

    public function test_le_jeton_emis_identifie_bien_son_porteur(): void
    {
        $user = $this->user(Profil::Owner);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@chictuktuk.com',
            'password' => 'mot-de-passe-valide',
        ])->json('token');

        // On vérifie ici que le jeton en clair résout bien vers ce compte. L'usage
        // du jeton sur une route protégée est testé en tâche 5, quand /auth/me
        // existe : /api/v1/health étant publique, l'y envoyer ne prouverait rien.
        $this->assertTrue(PersonalAccessToken::findToken($token)?->tokenable->is($user));
    }

    public function test_la_connexion_reinitialise_les_compteurs_d_echec(): void
    {
        $user = $this->user(Profil::Client);
        $user->forceFill(['failed_login_attempts' => 3, 'last_failed_login' => now()])->saveQuietly();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@chictuktuk.com',
            'password' => 'mot-de-passe-valide',
        ])->assertOk();

        $user->refresh();
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_les_champs_obligatoires_sont_valides(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
