<?php

namespace Tests\Feature\Announcement;

use App\Domains\Announcement\Application\Jobs\DeliverAnnouncement;
use App\Domains\Announcement\Application\Mail\AnnouncementMail;
use App\Domains\Notification\Application\Notifier;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Spec 2026-10-13, §5.2. */
class DeliverAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_recipient_gets_the_bell_and_one_email(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->sent()->create(['title' => 'Casque obligatoire']);
        $recipient = AnnouncementRecipient::factory()->for($announcement)->create();

        (new DeliverAnnouncement($announcement->id, [$recipient->id]))->handle(app(Notifier::class));

        $notification = Notification::where('user_id', $recipient->user_id)->sole();
        $this->assertSame('Nouveau communiqué', $notification->title);
        $this->assertSame('Casque obligatoire', $notification->message);
        $this->assertSame("/announcements/{$announcement->id}", $notification->data['url']);
        $this->assertSame($announcement->id, $notification->data['announcement_id']);

        Mail::assertSent(AnnouncementMail::class, fn (AnnouncementMail $mail) => $mail->hasTo($recipient->user->email));
        $this->assertNotNull($recipient->fresh()->emailed_at);
    }

    public function test_no_email_without_address_or_when_refused(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->sent()->create();
        $noAddress = AnnouncementRecipient::factory()->for($announcement)
            ->for(User::factory()->state(['email' => null]))->create();
        $optedOut = AnnouncementRecipient::factory()->for($announcement)
            ->for(User::factory()->state(['notification_preferences' => ['email_notifications' => false]]))->create();

        DeliverAnnouncement::dispatchFor($announcement, [$noAddress->id, $optedOut->id], false);

        Mail::assertNothingSent();
        $this->assertSame('no_address', $noAddress->fresh()->email_skipped);
        $this->assertSame('opted_out', $optedOut->fresh()->email_skipped);
        $this->assertSame(2, Notification::count(), 'la cloche est toujours écrite');
    }

    public function test_a_reminder_skips_those_who_read_it(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->sent()->create(['last_reminded_at' => now()]);
        $read = AnnouncementRecipient::factory()->for($announcement)->read()->create();
        $unread = AnnouncementRecipient::factory()->for($announcement)->create();

        DeliverAnnouncement::dispatchFor($announcement, [$read->id, $unread->id], true);

        $this->assertSame(0, Notification::where('user_id', $read->user_id)->count());
        $this->assertSame('Rappel : communiqué non lu', Notification::where('user_id', $unread->user_id)->sole()->title);
        Mail::assertSent(AnnouncementMail::class, fn (AnnouncementMail $mail) => $mail->reminder && $mail->envelope()->subject === 'Rappel : '.$announcement->title);
    }

    public function test_a_withdrawn_announcement_is_not_delivered(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->withdrawn()->create();
        $recipient = AnnouncementRecipient::factory()->for($announcement)->create();

        DeliverAnnouncement::dispatchFor($announcement, [$recipient->id], false);

        $this->assertSame(0, Notification::count());
        Mail::assertNothingSent();
    }

    /** Défaut reporté du 2026-10-13, corrigé le 2026-10-14 : le paquet en cours finissait sa remise. */
    public function test_a_withdrawal_during_a_delivery_stops_the_rest_of_the_batch(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->sent()->create();
        $first = AnnouncementRecipient::factory()->for($announcement)->create();
        $second = AnnouncementRecipient::factory()->for($announcement)->create();
        // L'admin retire le communiqué pendant que le premier destinataire est servi.
        Notification::created(function () use ($announcement) {
            Announcement::whereKey($announcement->id)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        });

        (new DeliverAnnouncement($announcement->id, [$first->id, $second->id]))->handle(app(Notifier::class));

        // L'ordre de remise n'est pas fixé : un seul des deux est servi, l'autre plus du tout.
        $this->assertSame(1, Notification::count(), 'le second destinataire ne reçoit plus rien');
        $this->assertSame(1, collect([$first->fresh(), $second->fresh()])->whereNotNull('notified_at')->count());
    }

    /** Défaut reporté du 2026-10-13, corrigé le 2026-10-14 : un compte désactivé recevait encore. */
    public function test_an_account_disabled_after_sending_receives_nothing(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->sent()->create(['last_reminded_at' => now()]);
        $disabled = AnnouncementRecipient::factory()->for($announcement)->create();
        $disabled->user->update(['is_active' => false]);

        DeliverAnnouncement::dispatchFor($announcement, [$disabled->id], false);
        DeliverAnnouncement::dispatchFor($announcement, [$disabled->id], true);

        $this->assertSame(0, Notification::count());
        Mail::assertNothingSent();
    }

    public function test_a_deleted_account_does_not_stop_the_others(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->sent()->create();
        $gone = AnnouncementRecipient::factory()->for($announcement)->create();
        $other = AnnouncementRecipient::factory()->for($announcement)->create();
        $ids = [$gone->id, $other->id];
        $gone->user->delete(); // cascade : la ligne disparaît avec le compte

        DeliverAnnouncement::dispatchFor($announcement, $ids, false);

        $this->assertSame(1, Notification::where('user_id', $other->user_id)->count());
    }

    public function test_large_attachments_are_linked_not_attached(): void
    {
        Storage::fake('local');
        $announcement = Announcement::factory()->sent()->create();
        $announcement->attachments()->create(['original_name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 6_000_000, 'path' => 'announcements/x/a.pdf']);
        $announcement->attachments()->create(['original_name' => 'b.pdf', 'mime_type' => 'application/pdf', 'size' => 6_000_000, 'path' => 'announcements/x/b.pdf']);

        $mail = new AnnouncementMail($announcement->fresh('attachments'));

        $this->assertFalse($mail->attachesFiles());
        $this->assertSame([], $mail->attachments());
        $mail->assertSeeInHtml('Pièces jointes à consulter sur la plateforme');
    }

    /**
     * Relecture finale du 2026-10-13 : un job tué par le délai du worker est rejoué. Le
     * rejouer ne doit RIEN renvoyer à qui l'a déjà reçu.
     */
    public function test_replaying_a_delivery_sends_nothing_twice(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->sent()->create();
        $recipient = AnnouncementRecipient::factory()->for($announcement)->create();

        $job = new DeliverAnnouncement($announcement->id, [$recipient->id]);
        $job->handle(app(Notifier::class));
        $job->handle(app(Notifier::class));

        $this->assertSame(1, Notification::where('user_id', $recipient->user_id)->count());
        Mail::assertSent(AnnouncementMail::class, 1);
    }

    public function test_replaying_a_reminder_sends_it_once_and_counts_it_once(): void
    {
        Mail::fake();
        $announcement = Announcement::factory()->sent()->create(['last_reminded_at' => now()]);
        $recipient = AnnouncementRecipient::factory()->for($announcement)->create(['emailed_at' => now()->subDay()]);

        $job = new DeliverAnnouncement($announcement->id, [$recipient->id], true);
        $job->handle(app(Notifier::class));
        $job->handle(app(Notifier::class));

        $this->assertSame(1, Notification::where('user_id', $recipient->user_id)->count());
        Mail::assertSent(AnnouncementMail::class, 1);
        $this->assertSame(1, $recipient->fresh()->reminded_count);
    }

    public function test_a_failed_email_is_recorded_and_not_retried_blindly(): void
    {
        Storage::fake('local');
        $announcement = Announcement::factory()->sent()->create();
        // Le fichier n'existe pas sur le disque : la construction de l'e-mail échoue.
        $announcement->attachments()->create(['original_name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'path' => 'announcements/x/absent.pdf']);
        $recipient = AnnouncementRecipient::factory()->for($announcement)->create();

        DeliverAnnouncement::dispatchFor($announcement, [$recipient->id], false);

        $this->assertNull($recipient->fresh()->emailed_at);
        $this->assertSame('failed', $recipient->fresh()->email_skipped);
        $this->assertSame(1, Notification::where('user_id', $recipient->user_id)->count(), 'la cloche est partie');
    }
}
