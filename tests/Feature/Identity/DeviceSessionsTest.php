<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Liste et révocation des appareils connectés.
 *
 * Avec le multi-appareils, un utilisateur ne pouvait couper l'accès d'un téléphone perdu
 * qu'en changeant son mot de passe. Et même alors, l'appareil continuait de recevoir les
 * notifications : `fcm_tokens` n'était relié à aucun jeton de connexion.
 */
class DeviceSessionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->profil(Profil::Driver)->create([
            'email' => 'agent@chictuktuk.com',
            'password' => Hash::make('bon-mot-de-passe'),
        ]);
    }

    private function login(string $userAgent = 'Mozilla/5.0 (Linux; Android 14) Chrome/128.0'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('User-Agent', $userAgent)
            ->postJson('/api/v1/auth/login', [
                'email' => 'agent@chictuktuk.com',
                'password' => 'bon-mot-de-passe',
            ])->json('token');
    }

    private function as(string $token): self
    {
        // Le garde mémorise l'utilisateur résolu d'une requête à l'autre d'un même test.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function tokenId(string $plain): int
    {
        return (int) explode('|', $plain, 2)[0];
    }

    public function test_la_connexion_retient_l_appareil_et_l_adresse(): void
    {
        $token = $this->login('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1');

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $this->tokenId($token),
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1',
            'ip_address' => '127.0.0.1',
        ]);
    }

    public function test_la_liste_montre_les_sessions_du_compte_et_marque_la_courante(): void
    {
        $phone = $this->login('Téléphone');
        $desk = $this->login('Poste');

        $other = User::factory()->profil(Profil::Driver)->create();
        $other->createToken('api');

        $response = $this->as($desk)->getJson('/api/v1/auth/sessions')->assertOk();

        $this->assertCount(2, $response->json());
        $current = collect($response->json())->firstWhere('is_current', true);
        $this->assertSame($this->tokenId($desk), $current['id']);
        $this->assertSame('Poste', $current['user_agent']);
        $this->assertSame(
            $this->tokenId($phone),
            collect($response->json())->firstWhere('is_current', false)['id'],
        );
    }

    public function test_la_liste_ecarte_les_sessions_expirees_ou_inactives(): void
    {
        $current = $this->login();
        $inactive = $this->login();
        $expired = $this->login();

        $this->user->tokens()->whereKey($this->tokenId($inactive))
            ->update(['last_used_at' => now()->subDays(15)]);
        $this->user->tokens()->whereKey($this->tokenId($expired))
            ->update(['expires_at' => now()->subMinute()]);

        $ids = collect($this->as($current)->getJson('/api/v1/auth/sessions')->json())->pluck('id');

        $this->assertEquals([$this->tokenId($current)], $ids->all());
    }

    public function test_revoquer_une_autre_session_la_coupe(): void
    {
        $phone = $this->login();
        $desk = $this->login();

        $this->as($desk)
            ->deleteJson('/api/v1/auth/sessions/'.$this->tokenId($phone))
            ->assertNoContent();

        $this->as($phone)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->as($desk)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_revoquer_la_session_d_un_autre_compte_repond_404(): void
    {
        $desk = $this->login();
        $other = User::factory()->profil(Profil::Driver)->create();
        $foreign = $other->createToken('api');

        $this->as($desk)
            ->deleteJson('/api/v1/auth/sessions/'.$foreign->accessToken->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_revoquer_la_session_courante_est_refuse(): void
    {
        $desk = $this->login();

        $this->as($desk)
            ->deleteJson('/api/v1/auth/sessions/'.$this->tokenId($desk))
            ->assertStatus(409)
            ->assertJsonPath('code', 'SESSION_IS_CURRENT');

        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_deconnecter_les_autres_appareils_garde_la_session_courante(): void
    {
        $phone = $this->login();
        $tablet = $this->login();
        $desk = $this->login();

        $this->as($desk)->postJson('/api/v1/auth/logout-others')->assertNoContent();

        $this->assertEquals([$this->tokenId($desk)], $this->user->tokens()->pluck('id')->all());
        $this->as($phone)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->as($tablet)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_les_routes_exigent_une_authentification(): void
    {
        $this->getJson('/api/v1/auth/sessions')->assertStatus(401);
        $this->deleteJson('/api/v1/auth/sessions/1')->assertStatus(401);
        $this->postJson('/api/v1/auth/logout-others')->assertStatus(401);
    }

    public function test_un_appareil_enregistre_est_rattache_a_sa_session(): void
    {
        $phone = $this->login();

        $this->as($phone)
            ->postJson('/api/v1/notifications/devices', ['token' => 'fcm-telephone'])
            ->assertNoContent();

        $this->assertDatabaseHas('fcm_tokens', [
            'token' => 'fcm-telephone',
            'personal_access_token_id' => $this->tokenId($phone),
        ]);
    }

    public function test_une_session_revoquee_n_est_plus_notifiee(): void
    {
        $phone = $this->login();
        $desk = $this->login();
        $this->as($phone)->postJson('/api/v1/notifications/devices', ['token' => 'fcm-telephone']);
        $this->as($desk)->postJson('/api/v1/notifications/devices', ['token' => 'fcm-poste']);

        $this->as($desk)->deleteJson('/api/v1/auth/sessions/'.$this->tokenId($phone))->assertNoContent();

        $this->assertDatabaseMissing('fcm_tokens', ['token' => 'fcm-telephone']);
        $this->assertDatabaseHas('fcm_tokens', ['token' => 'fcm-poste']);
    }

    public function test_changer_de_mot_de_passe_coupe_aussi_les_notifications_des_autres_appareils(): void
    {
        $phone = $this->login();
        $desk = $this->login();
        $this->as($phone)->postJson('/api/v1/notifications/devices', ['token' => 'fcm-telephone']);
        $this->as($desk)->postJson('/api/v1/notifications/devices', ['token' => 'fcm-poste']);

        $this->as($desk)->postJson('/api/v1/auth/password', [
            'current_password' => 'bon-mot-de-passe',
            'password' => 'nouveau-mot-de-passe-1',
            'password_confirmation' => 'nouveau-mot-de-passe-1',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('fcm_tokens', ['token' => 'fcm-telephone']);
        $this->assertDatabaseHas('fcm_tokens', ['token' => 'fcm-poste']);
    }

    public function test_un_appareil_repris_par_une_autre_session_suit_la_nouvelle(): void
    {
        // Même téléphone, deux connexions successives : FCM rend le même jeton, la ligne
        // doit suivre la session la plus récente, sinon révoquer l'ancienne couperait
        // les notifications de la nouvelle.
        $first = $this->login();
        $this->as($first)->postJson('/api/v1/notifications/devices', ['token' => 'meme-appareil']);
        $second = $this->login();
        $this->as($second)->postJson('/api/v1/notifications/devices', ['token' => 'meme-appareil']);

        $this->assertDatabaseHas('fcm_tokens', [
            'token' => 'meme-appareil',
            'personal_access_token_id' => $this->tokenId($second),
        ]);
    }
}
