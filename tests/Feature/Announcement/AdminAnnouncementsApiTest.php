<?php

namespace Tests\Feature\Announcement;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Spec 2026-10-13, §7.1. */
class AdminAnnouncementsApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_a_draft_is_written_previewed_and_sent(): void
    {
        Mail::fake();
        User::factory()->profil(Profil::Driver)->count(3)->create();
        $owner = User::factory()->profil(Profil::Owner)->create(['email' => null]);
        $token = $this->login(['draft-announcements', 'send-announcements']);

        $id = $this->asBearer($token)->postJson('/api/v1/admin/announcements', [
            'title' => 'Casque obligatoire', 'body' => '**Dès lundi.**',
            'target_profiles' => ['driver'], 'target_user_ids' => [$owner->id],
        ])->assertCreated()->assertJsonPath('status', 'draft')->json('id');

        $this->asBearer($token)->postJson('/api/v1/admin/announcements/recipients/preview', [
            'target_profiles' => ['driver'], 'target_user_ids' => [$owner->id],
        ])->assertOk()->assertJson(['total' => 4, 'with_email' => 3]);

        $this->asBearer($token)->postJson("/api/v1/admin/announcements/{$id}/send")
            ->assertOk()
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('recipients_count', 4)
            ->assertJsonPath('read_count', 0)
            ->assertJsonPath('body_html', "<p><strong>Dès lundi.</strong></p>\n");

        $this->assertSame(1, Activity::where('event', 'announcement.sent')->count());

        $this->asBearer($token)->putJson("/api/v1/admin/announcements/{$id}", [
            'title' => 'Autre', 'body' => 'x', 'target_profiles' => [], 'target_user_ids' => [],
        ])->assertStatus(409)->assertJsonPath('code', 'ANNOUNCEMENT_NOT_DRAFT');
    }

    public function test_the_tracking_lists_readers_and_can_filter_unread(): void
    {
        $announcement = Announcement::factory()->sent()->create();
        AnnouncementRecipient::factory()->for($announcement)->read()->create();
        AnnouncementRecipient::factory()->for($announcement)->count(2)->create();

        $this->asBearer($this->login(['draft-announcements']))
            ->getJson("/api/v1/admin/announcements/{$announcement->id}/recipients?filter[read]=unread")
            ->assertOk()
            ->assertJsonCount(2, 'recipients')
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_drafting_alone_cannot_send_remind_or_withdraw(): void
    {
        $draft = Announcement::factory()->create();
        $sent = Announcement::factory()->sent()->create();
        $token = $this->login(['draft-announcements']);

        $this->asBearer($token)->postJson("/api/v1/admin/announcements/{$draft->id}/send")->assertForbidden();
        $this->asBearer($token)->postJson("/api/v1/admin/announcements/{$sent->id}/remind")->assertForbidden();
        $this->asBearer($token)->postJson("/api/v1/admin/announcements/{$sent->id}/withdraw")->assertForbidden();
    }

    public function test_without_permission_nothing_is_readable(): void
    {
        $this->asBearer($this->login([]))->getJson('/api/v1/admin/announcements')->assertForbidden();
    }

    public function test_attachments_are_checked_on_their_content(): void
    {
        Storage::fake('local');
        $draft = Announcement::factory()->create();
        $token = $this->login(['draft-announcements']);

        // ⚠️ Un VRAI fichier : un faux de `UploadedFile::fake()` tire son type de l'extension,
        // et ne prouverait pas que la règle lit le contenu.
        $php = tempnam(sys_get_temp_dir(), 'ann');
        file_put_contents($php, "<?php echo 1;\n");
        $this->asBearer($token)->post("/api/v1/admin/announcements/{$draft->id}/attachments", [
            'file' => new UploadedFile($php, 'reglement.pdf', null, null, true),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->asBearer($token)->post("/api/v1/admin/announcements/{$draft->id}/attachments", [
            'file' => UploadedFile::fake()->create('gros.pdf', 10241, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->asBearer($token)->post("/api/v1/admin/announcements/{$draft->id}/attachments", [
            'file' => UploadedFile::fake()->create('reglement.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('attachments.0.original_name', 'reglement.pdf');
    }

    public function test_the_user_search_finds_active_accounts(): void
    {
        User::factory()->profil(Profil::Owner)->create(['name' => 'Firmin AMOULE']);
        User::factory()->profil(Profil::Owner)->inactive()->create(['name' => 'Firmin ANCIEN']);

        $this->asBearer($this->login(['draft-announcements']))
            ->getJson('/api/v1/admin/announcements/recipients/search?q=firmin')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Firmin AMOULE');
    }

    public function test_deleting_a_draft_is_logged(): void
    {
        $draft = Announcement::factory()->create(['title' => 'Essai']);

        $this->asBearer($this->login(['draft-announcements']))
            ->deleteJson("/api/v1/admin/announcements/{$draft->id}")->assertNoContent();

        $this->assertStringContainsString('Essai', Activity::where('event', 'announcement.deleted')->sole()->description);
    }
}
