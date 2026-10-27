<?php

namespace Tests\Feature\Notification;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Le filtre et les gestes de la liste (spec 2026-10-27-liste-des-notifications, §3.1). */
class NotificationsManageApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function account(Profil $profil = Profil::Driver): array
    {
        $user = User::factory()->profil($profil)->create(['password' => Hash::make('secret-123')]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-123'])->json('token');

        return [$user, $token];
    }

    private function as(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function notify(User $user, string $title, bool $read = false): Notification
    {
        return Notification::create(['user_id' => $user->id, 'title' => $title, 'message' => 'm', 'type' => 'info', 'is_read' => $read]);
    }

    public function test_the_unread_filter_lists_only_unread_ones(): void
    {
        [$me, $token] = $this->account();
        $this->notify($me, 'non lue');
        $this->notify($me, 'lue', read: true);

        $this->as($token)->getJson('/api/v1/notifications?filter=unread')
            ->assertOk()->assertJsonPath('data.0.title', 'non lue')->assertJsonCount(1, 'data')->assertJsonPath('total', 1);
    }

    public function test_an_unknown_filter_means_all(): void
    {
        [$me, $token] = $this->account();
        $this->notify($me, 'non lue');
        $this->notify($me, 'lue', read: true);

        $this->as($token)->getJson('/api/v1/notifications?filter=bidon')->assertOk()->assertJsonCount(2, 'data');
        $this->as($token)->getJson('/api/v1/notifications?filter[]=unread')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_unread_count_is_the_same_in_both_filters(): void
    {
        [$me, $token] = $this->account();
        $this->notify($me, 'a');
        $this->notify($me, 'b');
        $this->notify($me, 'c', read: true);

        $this->as($token)->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 2);
        $this->as($token)->getJson('/api/v1/notifications?filter=unread')->assertJsonPath('unread_count', 2);
    }

    public function test_a_notification_can_be_marked_unread(): void
    {
        [$me, $token] = $this->account();
        $n = $this->notify($me, 'lue', read: true);

        $this->as($token)->patchJson("/api/v1/notifications/{$n->id}/unread")->assertNoContent();

        $this->assertFalse($n->fresh()->is_read);
        $this->as($token)->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 1);
    }

    public function test_a_notification_can_be_deleted(): void
    {
        [$me, $token] = $this->account();
        $n = $this->notify($me, 'à effacer');

        $this->as($token)->deleteJson("/api/v1/notifications/{$n->id}")->assertNoContent();

        $this->assertNull($n->fresh());
    }

    public function test_deleting_read_ones_keeps_unread_ones(): void
    {
        [$me, $token] = $this->account();
        [$other] = $this->account(Profil::Owner);
        $this->notify($me, 'lue 1', read: true);
        $this->notify($me, 'lue 2', read: true);
        $this->notify($me, 'non lue');
        $foreign = $this->notify($other, 'lue d’un autre', read: true);

        $this->as($token)->deleteJson('/api/v1/notifications/read')->assertOk()->assertExactJson(['deleted' => 2]);

        $this->assertSame(['non lue'], Notification::where('user_id', $me->id)->pluck('title')->all());
        $this->assertNotNull($foreign->fresh());
    }

    public function test_another_accounts_notification_is_a_404(): void
    {
        [$owner] = $this->account(Profil::Owner);
        $n = $this->notify($owner, 'à un autre', read: true);
        [, $token] = $this->account();

        $this->as($token)->patchJson("/api/v1/notifications/{$n->id}/unread")->assertNotFound();
        $this->as($token)->deleteJson("/api/v1/notifications/{$n->id}")->assertNotFound();

        $this->assertTrue($n->fresh()->is_read);
    }

    public function test_a_non_numeric_id_is_a_404(): void
    {
        [, $token] = $this->account();

        $this->as($token)->deleteJson('/api/v1/notifications/abc')->assertNotFound();
        $this->as($token)->patchJson('/api/v1/notifications/abc/unread')->assertNotFound();
    }
}
