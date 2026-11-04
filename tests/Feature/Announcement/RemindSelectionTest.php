<?php

namespace Tests\Feature\Announcement;

use App\Domains\Announcement\Application\Jobs\DeliverAnnouncement;
use App\Domains\Announcement\Application\Mail\AnnouncementMail;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Notification\Application\Notifier;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Relancer qui on veut, avec un mot de rappel (spec 2026-11-04-communiques-relance-ajout) :
 * une session de deux jours, cinq lecteurs, trois venus — les deux autres se relancent.
 */
class RemindSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function api(array $permissions = ['draft-announcements', 'send-announcements']): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function announcement(): Announcement
    {
        return Announcement::factory()->sent()->create(['title' => 'Session de formation', 'sent_at' => now()->subDay()]);
    }

    /** Une ligne de l'envoi d'origine : créée avec lui, il y a un jour. */
    private function recipient(Announcement $announcement, array $attributes = [], ?User $user = null): AnnouncementRecipient
    {
        $factory = AnnouncementRecipient::factory()->for($announcement);
        if ($user) {
            $factory = $factory->for($user);
        }

        return $factory->create($attributes + ['created_at' => $announcement->sent_at]);
    }

    private function remind(Announcement $announcement, array $body = [])
    {
        return $this->api()->postJson("/api/v1/admin/announcements/{$announcement->id}/remind", $body);
    }

    public function test_a_selection_reminds_readers_too(): void
    {
        Mail::fake();
        $announcement = $this->announcement();
        $reader = $this->recipient($announcement, ['read_at' => now()->subHours(20)]);
        $otherReader = $this->recipient($announcement, ['read_at' => now()->subHours(20)]);
        $unread = $this->recipient($announcement);
        $readAt = $reader->read_at;

        $this->remind($announcement, ['recipient_ids' => [$reader->id, $unread->id]])->assertOk();

        $this->assertSame('Rappel : Session de formation', Notification::where('user_id', $reader->user_id)->sole()->title);
        $this->assertSame(1, Notification::where('user_id', $unread->user_id)->count());
        $this->assertSame(0, Notification::where('user_id', $otherReader->user_id)->count());
        $this->assertSame([1, 1, 0], [$reader->fresh()->reminded_count, $unread->fresh()->reminded_count, $otherReader->fresh()->reminded_count]);
        $this->assertTrue($reader->fresh()->read_at->eq($readAt), 'une relance ne touche pas la lecture');
    }

    public function test_the_note_becomes_the_notification_text_and_tops_the_email(): void
    {
        Mail::fake();
        $announcement = $this->announcement();
        $recipient = $this->recipient($announcement, ['read_at' => now()->subHour()]);

        $this->remind($announcement, ['recipient_ids' => [$recipient->id], 'note' => '2e jour demain à 9 h'])
            ->assertOk()
            ->assertJsonPath('last_reminder_note', '2e jour demain à 9 h');

        $this->assertSame('2e jour demain à 9 h', Notification::where('user_id', $recipient->user_id)->sole()->message);
        Mail::assertSent(AnnouncementMail::class, fn (AnnouncementMail $mail) => str_contains($mail->render(), '2e jour demain à 9 h'));
        $this->assertSame('2e jour demain à 9 h', $announcement->fresh()->last_reminder_note);
    }

    public function test_without_a_note_the_text_is_the_title_and_the_old_note_is_cleared(): void
    {
        Mail::fake();
        $announcement = $this->announcement();
        $announcement->update(['last_reminder_note' => 'Ancien mot', 'last_reminded_at' => now()->subHours(3)]);
        $recipient = $this->recipient($announcement);

        $this->remind($announcement)->assertOk()->assertJsonPath('last_reminder_note', null);

        $this->assertSame('Session de formation', Notification::where('user_id', $recipient->user_id)->sole()->message);
    }

    public function test_a_note_is_200_characters_at_most(): void
    {
        $announcement = $this->announcement();
        $recipient = $this->recipient($announcement);

        $this->remind($announcement, ['recipient_ids' => [$recipient->id], 'note' => str_repeat('a', 201)])
            ->assertStatus(422)->assertJsonValidationErrors('note');
    }

    public function test_a_selection_skips_inactive_accounts(): void
    {
        Mail::fake();
        $announcement = $this->announcement();
        $active = $this->recipient($announcement, ['read_at' => now()]);
        $inactive = $this->recipient($announcement, [], User::factory()->create(['is_active' => false]));

        $this->remind($announcement, ['recipient_ids' => [$active->id, $inactive->id]])->assertOk();
        $this->assertSame(1, Notification::count());

        $announcement->update(['last_reminded_at' => now()->subHours(2)]);
        $this->remind($announcement, ['recipient_ids' => [$inactive->id]])
            ->assertStatus(409)->assertJsonPath('code', 'ANNOUNCEMENT_NOTHING_TO_REMIND');
    }

    public function test_a_selection_outside_the_announcement_is_a_422(): void
    {
        $announcement = $this->announcement();
        $this->recipient($announcement);
        $elsewhere = $this->recipient($this->announcement());

        $this->remind($announcement, ['recipient_ids' => [$elsewhere->id]])
            ->assertStatus(422)->assertJsonValidationErrors('recipient_ids');
        $this->remind($announcement, ['recipient_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('recipient_ids');
    }

    public function test_unread_reminder_skips_recipients_added_less_than_an_hour_ago(): void
    {
        Mail::fake();
        $announcement = $this->announcement();
        $old = $this->recipient($announcement, ['created_at' => now()->subHours(2)]);
        $fresh = $this->recipient($announcement, ['created_at' => now()->subMinutes(30)]);

        $this->api()->getJson("/api/v1/admin/announcements/{$announcement->id}")->assertJsonPath('remindable_unread_count', 1);
        $this->remind($announcement)->assertOk();

        $this->assertSame(1, Notification::where('user_id', $old->user_id)->count());
        $this->assertSame(0, Notification::where('user_id', $fresh->user_id)->count());
    }

    public function test_a_replayed_selection_job_delivers_nothing_twice(): void
    {
        Mail::fake();
        $announcement = $this->announcement();
        $announcement->update(['last_reminded_at' => now()]);
        $reader = $this->recipient($announcement, ['read_at' => now()]);

        $job = new DeliverAnnouncement($announcement->id, [$reader->id], true, true);
        $job->handle(app(Notifier::class));
        $job->handle(app(Notifier::class));

        $this->assertSame(1, Notification::where('user_id', $reader->user_id)->count());
        Mail::assertSentCount(1);
        $this->assertSame(1, $reader->fresh()->reminded_count);
    }

    public function test_the_one_hour_guard_holds_for_a_selection(): void
    {
        Mail::fake();
        $announcement = $this->announcement();
        $recipient = $this->recipient($announcement, ['read_at' => now()]);

        $this->remind($announcement, ['recipient_ids' => [$recipient->id]])->assertOk();
        $this->remind($announcement, ['recipient_ids' => [$recipient->id]])
            ->assertStatus(409)->assertJsonPath('code', 'ANNOUNCEMENT_REMINDED_RECENTLY');
    }

    public function test_a_selection_is_journaled_as_such(): void
    {
        Mail::fake();
        $announcement = $this->announcement();
        $recipient = $this->recipient($announcement, ['read_at' => now()]);

        $this->remind($announcement, ['recipient_ids' => [$recipient->id], 'note' => 'Demain'])->assertOk();

        $activity = Activity::where('event', 'announcement.reminded')->sole();
        $this->assertSame('a relancé 1 destinataire(s) choisi(s) du communiqué « Session de formation »', $activity->description);
        $this->assertTrue($activity->properties['selection']);
        $this->assertSame('Demain', $activity->properties['note']);
    }

    public function test_recipient_rows_carry_id_active_and_added_at(): void
    {
        $announcement = $this->announcement();
        $original = $this->recipient($announcement);
        $added = $this->recipient($announcement, ['created_at' => now()]);

        $rows = collect($this->api()->getJson("/api/v1/admin/announcements/{$announcement->id}/recipients")->assertOk()->json('recipients'))
            ->keyBy('id');

        $this->assertTrue($rows[$original->id]['is_active']);
        $this->assertNull($rows[$original->id]['added_at']);
        $this->assertNotNull($rows[$added->id]['added_at']);
    }

    // Relecture du 2026-11-04 : la règle d'une heure ne vaut que pour les AJOUTÉS, pas pour l'envoi d'origine.
    public function test_unread_reminder_right_after_sending_still_works(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->sent()->create(['title' => 'Session de formation', 'sent_at' => now()->subMinutes(10)]);
        $recipient = $this->recipient($announcement);

        $this->api()->getJson("/api/v1/admin/announcements/{$announcement->id}")->assertJsonPath('remindable_unread_count', 1);
        $this->remind($announcement)->assertOk();

        $this->assertSame(1, Notification::where('user_id', $recipient->user_id)->count());
    }

    // Mineur de la relecture du 2026-11-04 : un job de relance en retard, dépassé par une relance
    // plus récente, ne remet rien — ni avec le mot de l'autre.
    public function test_a_late_job_overtaken_by_a_newer_reminder_delivers_nothing(): void
    {
        Mail::fake();
        $announcement = $this->announcement();
        $recipient = $this->recipient($announcement, ['read_at' => now()]);
        $first = now()->subHours(2);
        $announcement->update(['last_reminded_at' => now(), 'last_reminder_note' => 'Mot de la relance B']);

        (new DeliverAnnouncement($announcement->id, [$recipient->id], true, true, $first->toIso8601String()))->handle(app(Notifier::class));

        $this->assertSame(0, Notification::count());
        Mail::assertNothingSent();
    }

    public function test_more_than_500_recipients_get_a_clear_message(): void
    {
        $announcement = $this->announcement();
        $ids = array_map(fn () => (string) \Illuminate\Support\Str::uuid(), range(1, 501));

        $this->remind($announcement, ['recipient_ids' => $ids])
            ->assertStatus(422)
            ->assertJsonPath('errors.recipient_ids.0', 'Une relance vise 500 destinataires au plus : relancez en plusieurs fois.');
    }
}
