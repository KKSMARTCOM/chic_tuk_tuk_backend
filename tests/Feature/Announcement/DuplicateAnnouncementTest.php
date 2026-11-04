<?php

namespace Tests\Feature\Announcement;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Renvoyer un message déjà envoyé comme un communiqué neuf (spec
 * 2026-11-04-communiques-relance-ajout, « Dupliquer ») : un brouillon qui reprend tout.
 */
class DuplicateAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function api(array $permissions = ['draft-announcements']): self
    {
        $this->actor = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $this->actor->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $this->actor->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function attach(Announcement $announcement, string $name): AnnouncementAttachment
    {
        $path = "announcements/{$announcement->id}/".$name;
        Storage::disk('local')->put($path, 'contenu de '.$name);

        return $announcement->attachments()->create(['original_name' => $name, 'mime_type' => 'application/pdf', 'size' => 12, 'path' => $path]);
    }

    private function duplicate(Announcement $announcement, array $permissions = ['draft-announcements'])
    {
        return $this->api($permissions)->postJson("/api/v1/admin/announcements/{$announcement->id}/duplicate");
    }

    public function test_a_sent_announcement_becomes_a_new_draft(): void
    {
        $person = User::factory()->profil(Profil::Owner)->create();
        $original = Announcement::factory()->sent()->create([
            'title' => 'Session de formation', 'body' => '**Deux jours**', 'target_profiles' => ['owner'], 'target_user_ids' => [$person->id],
        ]);
        AnnouncementRecipient::factory()->for($original)->create();

        $json = $this->duplicate($original)->assertCreated()->json();

        $draft = Announcement::findOrFail($json['announcement']['id']);
        $this->assertNotSame($original->id, $draft->id);
        $this->assertSame('draft', $draft->status->value);
        $this->assertSame(['Session de formation', '**Deux jours**', ['owner'], [$person->id]], [$draft->title, $draft->body, $draft->target_profiles, $draft->target_user_ids]);
        $this->assertSame($this->actor->id, $draft->created_by);
        $this->assertNull($draft->sent_at);
        $this->assertSame(0, $draft->recipients()->count());
        $this->assertSame(0, $json['skipped_attachments']);
        $this->assertSame('sent', $original->fresh()->status->value);
        $this->assertSame(1, $original->recipients()->count());
    }

    public function test_attachments_are_copied_not_shared(): void
    {
        $original = Announcement::factory()->sent()->create();
        $first = $this->attach($original, 'programme.pdf');
        $this->attach($original, 'plan.pdf');

        $id = $this->duplicate($original)->assertCreated()->json('announcement.id');

        $copies = AnnouncementAttachment::where('announcement_id', $id)->get();
        $this->assertSame(['programme.pdf', 'plan.pdf'], $copies->pluck('original_name')->all());
        foreach ($copies as $copy) {
            $this->assertStringStartsWith("announcements/{$id}/", $copy->path);
            Storage::disk('local')->assertExists($copy->path);
        }

        $this->api(['draft-announcements'])->deleteJson("/api/v1/admin/announcements/{$id}")->assertSuccessful();
        Storage::disk('local')->assertExists($first->path);
    }

    public function test_a_missing_file_is_skipped_and_counted(): void
    {
        $original = Announcement::factory()->sent()->create();
        $this->attach($original, 'programme.pdf');
        $gone = $this->attach($original, 'perdu.pdf');
        Storage::disk('local')->delete($gone->path);

        $json = $this->duplicate($original)->assertCreated()->json();

        $this->assertSame(1, $json['skipped_attachments']);
        $this->assertCount(1, $json['announcement']['attachments']);
    }

    public function test_a_withdrawn_announcement_can_be_duplicated_but_not_a_draft(): void
    {
        $this->duplicate(Announcement::factory()->withdrawn()->create())->assertCreated();

        $this->duplicate(Announcement::factory()->create())
            ->assertStatus(409)
            ->assertJsonPath('code', 'ANNOUNCEMENT_IS_DRAFT')
            ->assertJsonPath('message', 'Un brouillon se modifie, il ne se duplique pas.');
    }

    public function test_duplicating_needs_draft_announcements_only(): void
    {
        $original = Announcement::factory()->sent()->create();

        $this->duplicate($original, ['draft-announcements'])->assertCreated();
        $this->duplicate($original, ['send-announcements'])->assertForbidden();
    }

    public function test_duplicating_is_journaled(): void
    {
        $original = Announcement::factory()->sent()->create(['title' => 'Session de formation']);

        $id = $this->duplicate($original)->assertCreated()->json('announcement.id');

        $activity = Activity::where('event', 'announcement.duplicated')->sole();
        $this->assertSame($id, $activity->subject_id);
        $this->assertSame('a dupliqué le communiqué « Session de formation »', $activity->description);
        $this->assertSame($original->id, $activity->properties['original_id']);
    }

    // Relecture du 2026-11-04 : une copie qui échoue sans lever ne laisse pas une pièce sans fichier.
    public function test_a_failed_copy_counts_as_skipped(): void
    {
        $original = Announcement::factory()->sent()->create();
        $this->attach($original, 'programme.pdf');
        $disk = \Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('copy')->andReturnFalse();
        Storage::set('local', $disk);

        $json = $this->duplicate($original)->assertCreated()->json();

        $this->assertSame(1, $json['skipped_attachments']);
        $this->assertCount(0, $json['announcement']['attachments']);
    }
}
