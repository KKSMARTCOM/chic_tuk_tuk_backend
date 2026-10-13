<?php

namespace Tests\Feature\Announcement;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Spec 2026-10-13, §5.4 et §7.2. */
class MyAnnouncementsApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function connecter(Profil $profil = Profil::Driver): array
    {
        $user = User::factory()->profil($profil)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');

        return [$user, $token];
    }

    private function entete(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_opening_records_the_first_read_only_and_clears_the_bell(): void
    {
        [$user, $token] = $this->connecter();
        $announcement = Announcement::factory()->sent()->create(['body' => '**Casque**']);
        $recipient = AnnouncementRecipient::factory()->for($announcement)->create(['user_id' => $user->id]);
        Notification::create(['user_id' => $user->id, 'title' => 'Nouveau communiqué', 'message' => 'x', 'type' => 'info', 'is_read' => false, 'data' => ['announcement_id' => $announcement->id]]);
        $autre = Notification::create(['user_id' => $user->id, 'title' => 'Autre', 'message' => 'x', 'type' => 'info', 'is_read' => false, 'data' => []]);

        $this->entete($token)->getJson("/api/v1/announcements/{$announcement->id}")
            ->assertOk()
            ->assertJsonPath('body_html', "<p><strong>Casque</strong></p>\n");
        $first = $recipient->fresh()->read_at;

        $this->travel(2)->hours();
        $this->entete($token)->getJson("/api/v1/announcements/{$announcement->id}")->assertOk();

        $this->assertEquals($first, $recipient->fresh()->read_at);
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('is_read', true)->count());
        $this->assertFalse($autre->fresh()->is_read);
    }

    public function test_someone_elses_or_a_draft_is_not_found_and_a_withdrawn_one_is_gone(): void
    {
        [$user, $token] = $this->connecter();
        $notMine = Announcement::factory()->sent()->create();
        $draft = Announcement::factory()->create();
        AnnouncementRecipient::factory()->for($draft)->create(['user_id' => $user->id]);
        $withdrawn = Announcement::factory()->withdrawn()->create();
        AnnouncementRecipient::factory()->for($withdrawn)->create(['user_id' => $user->id]);

        $this->entete($token)->getJson("/api/v1/announcements/{$notMine->id}")->assertNotFound();
        $this->entete($token)->getJson("/api/v1/announcements/{$draft->id}")->assertNotFound();
        $this->entete($token)->getJson("/api/v1/announcements/{$withdrawn->id}")
            ->assertStatus(410)->assertJsonPath('code', 'ANNOUNCEMENT_WITHDRAWN');
        $this->entete($token)->getJson('/api/v1/announcements/pas-un-uuid')->assertNotFound();
    }

    public function test_the_list_and_the_unread_count_ignore_withdrawn_ones(): void
    {
        [$user, $token] = $this->connecter(Profil::Owner);
        $unread = Announcement::factory()->sent()->create();
        AnnouncementRecipient::factory()->for($unread)->create(['user_id' => $user->id]);
        $read = Announcement::factory()->sent()->create();
        AnnouncementRecipient::factory()->for($read)->read()->create(['user_id' => $user->id]);
        $withdrawn = Announcement::factory()->withdrawn()->create();
        AnnouncementRecipient::factory()->for($withdrawn)->create(['user_id' => $user->id]);

        $this->entete($token)->getJson('/api/v1/announcements')
            ->assertOk()->assertJsonCount(2, 'announcements');
        $this->entete($token)->getJson('/api/v1/announcements/unread-count')
            ->assertOk()->assertExactJson(['count' => 1, 'only_id' => $unread->id]);
    }

    public function test_a_recipient_downloads_an_attachment(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('announcements/x/abc.pdf', '%PDF-1.4');
        [$user, $token] = $this->connecter(Profil::Client);
        $announcement = Announcement::factory()->sent()->create();
        $file = $announcement->attachments()->create(['original_name' => 'reglement.pdf', 'mime_type' => 'application/pdf', 'size' => 8, 'path' => 'announcements/x/abc.pdf']);
        AnnouncementRecipient::factory()->for($announcement)->create(['user_id' => $user->id]);

        $this->entete($token)->get("/api/v1/announcements/{$announcement->id}/attachments/{$file->id}")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('reglement.pdf');
    }
}
