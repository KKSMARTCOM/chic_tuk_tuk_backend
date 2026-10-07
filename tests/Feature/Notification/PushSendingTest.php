<?php

namespace Tests\Feature\Notification;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Notification\Application\PushSender;
use App\Models\FcmToken;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

/**
 * L'envoi d'une notification : à qui, et ce qu'il en reste.
 *
 * Deux défauts avérés sont verrouillés ici, trouvés en lisant le code du Blade le
 * 2026-09-21 :
 *
 *  1. `notification_preferences` n'était lu par PERSONNE. L'écran de réglages écrivait
 *     `push_notifications: false`, et `sendToDrivers()` envoyait quand même. La case à
 *     cocher était décorative.
 *  2. La table `notifications` n'était JAMAIS écrite. Aucune ligne de code n'y insérait
 *     quoi que ce soit, alors que la cloche de l'en-tête la lit : elle affichait donc
 *     « aucune notification » depuis sa création en janvier.
 */
class PushSendingTest extends TestCase
{
    use RefreshDatabase;

    private function agent(array $preferences = []): User
    {
        $user = User::factory()->profil(Profil::Driver)->create([
            'notification_preferences' => $preferences,
        ]);

        FcmToken::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token' => 'jeton-'.$user->id,
        ]);

        return $user;
    }

    /** Remplace le messaging Firebase et retient les cibles réellement visées. */
    private function espionnerFirebase(): object
    {
        $envois = new class
        {
            /** @var list<string> */
            public array $cibles = [];
        };

        // ⚠️ On remplace le CONTRAT `Kreait\Firebase\Contract\Messaging`, que le paquet
        // lie dans le conteneur, et non la classe concrète `Kreait\Firebase\Messaging`,
        // qui est `final` et que Mockery refuse de doubler. Le service doit donc résoudre
        // ce contrat depuis le conteneur, et non appeler la façade statiquement — sans
        // quoi aucun test ne peut observer ce qui est envoyé.
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')->andReturnUsing(function ($message) use ($envois) {
            $rendu = $message->jsonSerialize();
            $envois->cibles[] = $rendu['token'] ?? 'inconnue';

            return [];
        });

        $this->app->instance(Messaging::class, $messaging);

        return $envois;
    }

    public function test_une_preference_push_a_false_empeche_l_envoi(): void
    {
        $envois = $this->espionnerFirebase();
        $this->agent(['push_notifications' => false]);

        app(PushSender::class)->sendToDrivers('Titre', 'Corps');

        $this->assertSame([], $envois->cibles, 'un agent qui a refusé les push en a reçu un');
    }

    public function test_une_preference_absente_vaut_accord(): void
    {
        // ⚠️ Décisif. `notification_preferences` vaut `{}` pour TOUS les comptes existants :
        // personne n'a jamais ouvert l'écran de réglages. Traiter l'absence comme un refus
        // couperait les notifications de toute la flotte d'un coup.
        $envois = $this->espionnerFirebase();
        $user = $this->agent([]);

        app(PushSender::class)->sendToDrivers('Titre', 'Corps');

        $this->assertSame(['jeton-'.$user->id], $envois->cibles);
    }

    public function test_une_preference_push_a_true_laisse_passer(): void
    {
        $envois = $this->espionnerFirebase();
        $user = $this->agent(['push_notifications' => true]);

        app(PushSender::class)->sendToDrivers('Titre', 'Corps');

        $this->assertSame(['jeton-'.$user->id], $envois->cibles);
    }

    public function test_chaque_envoi_laisse_une_notification_en_base(): void
    {
        $this->espionnerFirebase();
        $user = $this->agent([]);

        app(PushSender::class)->sendToDrivers(
            'Nouvelle réservation disponible',
            'Trajet : Cotonou → Calavi',
            ['url' => '/driver/bookings/available'],
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Nouvelle réservation disponible',
            'message' => 'Trajet : Cotonou → Calavi',
            'is_read' => false,
        ]);
    }

    public function test_la_notification_est_ecrite_meme_sans_appareil_enregistre(): void
    {
        // Un agent sans jeton FCM — jamais connecté sur mobile, ou permission refusée —
        // doit quand même retrouver l'information dans sa cloche en ouvrant l'application.
        $this->espionnerFirebase();
        $user = User::factory()->profil(Profil::Driver)->create(['notification_preferences' => []]);

        app(PushSender::class)->sendToDrivers('Titre', 'Corps');

        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'title' => 'Titre']);
    }

    public function test_un_refus_de_push_n_efface_pas_la_trace_en_base(): void
    {
        // La préférence porte sur le PUSH, pas sur le fait d'être informé : refuser les
        // notifications système ne doit pas vider la cloche de l'application.
        $this->espionnerFirebase();
        $user = $this->agent(['push_notifications' => false]);

        app(PushSender::class)->sendToDrivers('Titre', 'Corps');

        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'title' => 'Titre']);
    }

    // ----- Quand Firebase refuse (2026-10-07) ---------------------------------------------
    //
    // Défaut trouvé en production : la clé du compte de service était refusée par Google
    // (`invalid_grant`), CHAQUE envoi échouait, et l'API supprimait le jeton de l'appareil à
    // chaque échec, sans rien journaliser. Tout appareil abonné disparaissait à sa première
    // notification, et rien ne disait pourquoi.

    /** Fait échouer chaque envoi avec cette erreur. */
    private function firebaseRefuse(\Throwable $error): void
    {
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')->andThrow($error);
        $this->app->instance(Messaging::class, $messaging);
    }

    public function test_un_refus_d_authentification_garde_le_jeton_et_se_journalise(): void
    {
        $this->firebaseRefuse(new \Kreait\Firebase\Exception\Messaging\InvalidMessage('invalid_grant'));
        Log::spy();
        $user = $this->agent([]);

        app(PushSender::class)->sendToDrivers('Titre', 'Corps');

        // Un refus de Firebase ne supprime pas un appareil valide.
        $this->assertDatabaseHas('fcm_tokens', ['user_id' => $user->id]);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'Push refusé')
            && ($context['erreur'] ?? null) === 'invalid_grant' && ($context['user_id'] ?? null) === $user->id);
    }

    public function test_firebase_injoignable_garde_le_jeton(): void
    {
        $this->firebaseRefuse(new \Kreait\Firebase\Exception\Messaging\ServerUnavailable('Service Unavailable'));
        $user = $this->agent([]);

        app(PushSender::class)->sendToDrivers('Titre', 'Corps');

        $this->assertDatabaseHas('fcm_tokens', ['user_id' => $user->id]);
    }

    public function test_un_jeton_inconnu_de_firebase_est_retire(): void
    {
        $this->firebaseRefuse(\Kreait\Firebase\Exception\Messaging\NotFound::becauseTokenNotFound('jeton'));
        $user = $this->agent([]);

        app(PushSender::class)->sendToDrivers('Titre', 'Corps');

        $this->assertDatabaseMissing('fcm_tokens', ['user_id' => $user->id]);
    }

    public function test_un_jeton_invalide_est_retire(): void
    {
        $this->firebaseRefuse(new \Kreait\Firebase\Exception\Messaging\InvalidMessage('The registration token is not a valid FCM registration token'));
        $user = $this->agent([]);

        app(PushSender::class)->sendToDrivers('Titre', 'Corps');

        $this->assertDatabaseMissing('fcm_tokens', ['user_id' => $user->id]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
