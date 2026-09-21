<?php

namespace Tests\Feature\Notification;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\FcmToken;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Les notifications de l'API v1 : appareils, cloche, préférences.
 *
 * ⚠️ Ces routes ne portent ni `abilities:` ni `permission:`, et c'est délibéré : les
 * QUATRE espaces — agent, client, admin, propriétaire — installent la même application
 * et reçoivent des notifications. Un garde par profil les réserverait à l'un d'eux.
 * La portée est assurée par `Auth::id()`, comme pour le profil.
 */
class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function connecter(Profil $profil = Profil::Driver): array
    {
        $user = User::factory()->profil($profil)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    /**
     * Pose le jeton porteur — et OUBLIE le garde au préalable.
     *
     * ⚠️ `Auth::forgetGuards()` n'est pas une précaution de style. Le garde de Sanctum
     * mémorise l'utilisateur qu'il a résolu, et l'instance survit d'une requête de test
     * à l'autre dans une même méthode : sans cet oubli, la DEUXIÈME requête d'un test
     * s'exécute encore sous le PREMIER compte, quel que soit le jeton envoyé.
     *
     * Le symptôme est sournois parce qu'il fabrique des faux positifs : un test de
     * portée « un compte ne touche pas les données d'un autre » passe alors sans rien
     * prouver, puisque les deux requêtes viennent en réalité du même compte. Constaté le
     * 2026-09-21 sur la réattribution d'un appareil — le jeton restait au premier
     * propriétaire, et deux tests échouaient pour une raison qui n'existait pas en
     * production, où chaque requête part d'un conteneur neuf.
     */
    private function entete(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // ----- Appareils ---------------------------------------------------------

    public function test_un_appareil_s_enregistre(): void
    {
        [$user, $token] = $this->connecter();

        $this->entete($token)
            ->postJson('/api/v1/notifications/devices', ['token' => 'jeton-fcm-abc'])
            ->assertNoContent();

        $this->assertDatabaseHas('fcm_tokens', ['token' => 'jeton-fcm-abc', 'user_id' => $user->id]);
    }

    public function test_reenregistrer_le_meme_appareil_ne_cree_pas_de_doublon(): void
    {
        // Le cas normal : FCM redonne le même jeton à chaque ouverture de l'application.
        [$user, $token] = $this->connecter();

        foreach ([1, 2, 3] as $_) {
            $this->entete($token)->postJson('/api/v1/notifications/devices', ['token' => 'jeton-fcm-abc']);
        }

        $this->assertSame(1, FcmToken::where('token', 'jeton-fcm-abc')->count());
    }

    public function test_un_appareil_change_de_proprietaire_a_la_reconnexion(): void
    {
        // Deux comptes se succèdent sur le même téléphone. Sans réattribution, l'ancien
        // propriétaire continuerait de recevoir les notifications destinées au nouveau.
        [$premier, $jetonA] = $this->connecter(Profil::Driver);
        [$second, $jetonB] = $this->connecter(Profil::Owner);

        $this->entete($jetonA)->postJson('/api/v1/notifications/devices', ['token' => 'meme-tel']);
        $this->entete($jetonB)->postJson('/api/v1/notifications/devices', ['token' => 'meme-tel']);

        $this->assertSame(1, FcmToken::where('token', 'meme-tel')->count());
        $this->assertSame($second->id, FcmToken::where('token', 'meme-tel')->first()->user_id);
    }

    public function test_un_appareil_se_retire_a_la_deconnexion(): void
    {
        [$user, $token] = $this->connecter();
        $this->entete($token)->postJson('/api/v1/notifications/devices', ['token' => 'a-retirer']);

        $this->entete($token)
            ->deleteJson('/api/v1/notifications/devices', ['token' => 'a-retirer'])
            ->assertNoContent();

        $this->assertDatabaseMissing('fcm_tokens', ['token' => 'a-retirer']);
    }

    public function test_on_ne_retire_que_ses_propres_appareils(): void
    {
        // Sans ce contrôle, n'importe quel compte authentifié pourrait faire taire le
        // téléphone d'un autre en devinant — ou en ayant vu passer — son jeton.
        [$victime, $jetonVictime] = $this->connecter(Profil::Driver);
        [, $jetonTiers] = $this->connecter(Profil::Owner);

        $this->entete($jetonVictime)->postJson('/api/v1/notifications/devices', ['token' => 'tel-victime']);
        $this->entete($jetonTiers)->deleteJson('/api/v1/notifications/devices', ['token' => 'tel-victime']);

        $this->assertDatabaseHas('fcm_tokens', ['token' => 'tel-victime', 'user_id' => $victime->id]);
    }

    public function test_les_appareils_exigent_une_authentification(): void
    {
        $this->postJson('/api/v1/notifications/devices', ['token' => 'x'])->assertUnauthorized();
    }

    // ----- La cloche ---------------------------------------------------------

    private function notifier(User $user, string $titre, bool $lue = false): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'title' => $titre,
            'message' => 'corps',
            'type' => 'info',
            'is_read' => $lue,
        ]);
    }

    public function test_la_liste_ne_montre_que_ses_propres_notifications(): void
    {
        [$moi, $token] = $this->connecter(Profil::Driver);
        [$autre] = $this->connecter(Profil::Owner);

        $this->notifier($moi, 'à moi');
        $this->notifier($autre, 'à un autre');

        $reponse = $this->entete($token)->getJson('/api/v1/notifications')->assertOk();

        $this->assertSame(['à moi'], array_column($reponse->json('data'), 'title'));
    }

    public function test_la_liste_porte_le_compte_des_non_lues(): void
    {
        [$moi, $token] = $this->connecter();
        $this->notifier($moi, 'une', lue: false);
        $this->notifier($moi, 'deux', lue: false);
        $this->notifier($moi, 'trois', lue: true);

        $this->entete($token)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2);
    }

    public function test_les_plus_recentes_d_abord(): void
    {
        [$moi, $token] = $this->connecter();
        $ancienne = $this->notifier($moi, 'ancienne');
        $ancienne->update(['created_at' => now()->subDays(3)]);
        $this->notifier($moi, 'récente');

        $titres = array_column($this->entete($token)->getJson('/api/v1/notifications')->json('data'), 'title');

        $this->assertSame(['récente', 'ancienne'], $titres);
    }

    public function test_une_notification_se_marque_lue(): void
    {
        [$moi, $token] = $this->connecter();
        $n = $this->notifier($moi, 'une');

        $this->entete($token)->patchJson("/api/v1/notifications/{$n->id}/read")->assertNoContent();

        $this->assertTrue($n->fresh()->is_read);
    }

    public function test_on_ne_marque_pas_lue_la_notification_d_un_autre(): void
    {
        [, $token] = $this->connecter(Profil::Driver);
        [$autre] = $this->connecter(Profil::Owner);
        $n = $this->notifier($autre, 'à un autre');

        $this->entete($token)->patchJson("/api/v1/notifications/{$n->id}/read")->assertNotFound();

        $this->assertFalse($n->fresh()->is_read);
    }

    public function test_tout_marquer_lu_ne_touche_que_les_siennes(): void
    {
        [$moi, $token] = $this->connecter(Profil::Driver);
        [$autre] = $this->connecter(Profil::Owner);
        $mienne = $this->notifier($moi, 'à moi');
        $sienne = $this->notifier($autre, 'à un autre');

        $this->entete($token)->patchJson('/api/v1/notifications/read-all')->assertNoContent();

        $this->assertTrue($mienne->fresh()->is_read);
        $this->assertFalse($sienne->fresh()->is_read);
    }

    // ----- Préférences -------------------------------------------------------

    public function test_les_preferences_par_defaut_valent_accord(): void
    {
        // ⚠️ Décisif : `notification_preferences` vaut `{}` pour tous les comptes
        // existants. L'écran doit montrer des interrupteurs ACTIVÉS, faute de quoi
        // l'utilisateur croira avoir refusé des notifications qu'il reçoit.
        [, $token] = $this->connecter();

        $this->entete($token)->getJson('/api/v1/notifications/preferences')
            ->assertOk()
            ->assertJson(['push_notifications' => true, 'email_notifications' => true]);
    }

    public function test_les_preferences_se_modifient(): void
    {
        [$user, $token] = $this->connecter();

        $this->entete($token)
            ->patchJson('/api/v1/notifications/preferences', ['push_notifications' => false])
            ->assertOk()
            ->assertJson(['push_notifications' => false, 'email_notifications' => true]);

        $this->assertFalse($user->fresh()->notification_preferences['push_notifications']);
    }

    public function test_une_preference_omise_n_ecrase_pas_l_autre(): void
    {
        // PATCH, pas PUT : l'écran des préférences envoie l'interrupteur qui a bougé.
        [$user, $token] = $this->connecter();
        $this->entete($token)->patchJson('/api/v1/notifications/preferences', ['email_notifications' => false]);

        $this->entete($token)->patchJson('/api/v1/notifications/preferences', ['push_notifications' => false]);

        $preferences = $user->fresh()->notification_preferences;
        $this->assertFalse($preferences['email_notifications'], 'la préférence e-mail a été écrasée');
        $this->assertFalse($preferences['push_notifications']);
    }

    public function test_tous_les_profils_accedent_aux_notifications(): void
    {
        // Le rappel du 2026-09-21 : c'est TOUTE l'application qui est installée et
        // notifiée, pas le seul espace agent.
        foreach ([Profil::Driver, Profil::Client, Profil::Admin, Profil::Owner] as $profil) {
            [, $token] = $this->connecter($profil);

            $reponse = $this->entete($token)->getJson('/api/v1/notifications');

            $this->assertSame(200, $reponse->status(), "le profil {$profil->value} s'est vu refuser ses notifications");
        }
    }
}
