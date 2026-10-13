<?php

namespace Tests\Feature\Announcement;

use App\Domains\Announcement\Application\Actions\RemindAnnouncement;
use App\Domains\Announcement\Application\Actions\SendAnnouncement;
use App\Domains\Announcement\Application\Actions\WithdrawAnnouncement;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Notification;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Spec 2026-10-13, §4, §5.2 et §5.3. */
class SendAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_sending_freezes_the_recipients_and_delivers(): void
    {
        $sender = User::factory()->profil(Profil::Admin)->create();
        User::factory()->profil(Profil::Driver)->count(2)->create();
        $draft = Announcement::factory()->create(['target_profiles' => ['driver']]);

        $sent = app(SendAnnouncement::class)($draft, $sender);

        $this->assertSame(AnnouncementStatus::Sent, $sent->status);
        $this->assertSame($sender->id, $sent->sent_by);
        $this->assertSame(2, $sent->recipients()->count());
        $this->assertSame(2, Notification::where('title', 'Nouveau communiqué')->count());

        User::factory()->profil(Profil::Driver)->create();
        $this->assertSame(2, $sent->recipients()->count(), 'un compte créé après l\'envoi ne le reçoit pas');
    }

    public function test_sending_twice_is_refused(): void
    {
        User::factory()->profil(Profil::Driver)->create();
        $draft = Announcement::factory()->create(['target_profiles' => ['driver']]);
        app(SendAnnouncement::class)($draft, User::factory()->create());

        try {
            app(SendAnnouncement::class)($draft, User::factory()->create());
            $this->fail('Le second envoi aurait dû être refusé.');
        } catch (ApiException $e) {
            $this->assertSame('ANNOUNCEMENT_NOT_DRAFT', $e->errorCode);
        }
        $this->assertSame(1, Notification::count());
    }

    public function test_sending_without_body_or_recipient_is_refused(): void
    {
        $empty = Announcement::factory()->create(['body' => '  ', 'target_profiles' => ['driver']]);
        User::factory()->profil(Profil::Driver)->create();

        try {
            app(SendAnnouncement::class)($empty, User::factory()->create());
            $this->fail('Un texte vide aurait dû être refusé.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }

        $nobody = Announcement::factory()->create(['target_profiles' => ['owner']]);
        $this->expectException(ValidationException::class);
        app(SendAnnouncement::class)($nobody, User::factory()->create());
    }

    public function test_a_reminder_targets_unread_only_and_waits_an_hour(): void
    {
        $announcement = Announcement::factory()->sent()->create();
        $read = AnnouncementRecipient::factory()->for($announcement)->read()->create();
        $unread = AnnouncementRecipient::factory()->for($announcement)->create();

        $this->assertSame(1, app(RemindAnnouncement::class)($announcement));
        $this->assertSame(1, $unread->fresh()->reminded_count);
        $this->assertSame(0, $read->fresh()->reminded_count);
        $this->assertSame(0, Notification::where('user_id', $read->user_id)->count());

        try {
            app(RemindAnnouncement::class)($announcement->fresh());
            $this->fail('Une seconde relance dans l\'heure aurait dû être refusée.');
        } catch (ApiException $e) {
            $this->assertSame('ANNOUNCEMENT_REMINDED_RECENTLY', $e->errorCode);
        }

        $this->travel(61)->minutes();
        $this->assertSame(1, app(RemindAnnouncement::class)($announcement->fresh()));
    }

    public function test_a_reminder_with_everyone_read_is_refused(): void
    {
        $announcement = Announcement::factory()->sent()->create();
        AnnouncementRecipient::factory()->for($announcement)->read()->create();

        try {
            app(RemindAnnouncement::class)($announcement);
            $this->fail('La relance aurait dû être refusée.');
        } catch (ApiException $e) {
            $this->assertSame('ANNOUNCEMENT_ALL_READ', $e->errorCode);
        }
    }

    public function test_withdrawing_is_final_and_logged(): void
    {
        $announcement = Announcement::factory()->sent()->create();
        $by = User::factory()->create();

        $withdrawn = app(WithdrawAnnouncement::class)($announcement, $by);

        $this->assertSame(AnnouncementStatus::Withdrawn, $withdrawn->status);
        $this->assertSame($by->id, $withdrawn->withdrawn_by);

        $this->expectException(ApiException::class);
        app(WithdrawAnnouncement::class)($withdrawn, $by);
    }
}
