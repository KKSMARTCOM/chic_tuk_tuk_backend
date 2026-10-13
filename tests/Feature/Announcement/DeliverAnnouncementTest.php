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
        $announcement = Announcement::factory()->sent()->create();
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
}
