<?php

namespace Tests\Feature\Announcement;

use App\Domains\Announcement\Application\Actions\AddAnnouncementAttachment;
use App\Domains\Announcement\Application\Actions\DeleteAnnouncementDraft;
use App\Domains\Announcement\Application\Actions\SaveAnnouncementDraft;
use App\Domains\Announcement\Application\Data\SaveAnnouncementData;
use App\Models\Announcement;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Spec 2026-10-13, §4 : le brouillon se modifie, l'envoyé est figé. */
class AnnouncementDraftTest extends TestCase
{
    use RefreshDatabase;

    private function data(string $title = 'Consigne'): SaveAnnouncementData
    {
        return new SaveAnnouncementData($title, 'Texte', ['driver'], []);
    }

    public function test_a_draft_is_created_then_updated(): void
    {
        $author = User::factory()->create();

        $draft = app(SaveAnnouncementDraft::class)(null, $this->data(), $author);
        $draft = app(SaveAnnouncementDraft::class)($draft, $this->data('Consigne modifiée'), $author);

        $this->assertSame('Consigne modifiée', $draft->fresh()->title);
        $this->assertSame($author->id, $draft->created_by);
        $this->assertSame($author->id, $draft->updated_by);
    }

    public function test_a_sent_announcement_cannot_be_changed_nor_deleted(): void
    {
        $sent = Announcement::factory()->sent()->create();

        try {
            app(SaveAnnouncementDraft::class)($sent, $this->data('Autre'), User::factory()->create());
            $this->fail('La modification aurait dû être refusée.');
        } catch (ApiException $e) {
            $this->assertSame('ANNOUNCEMENT_NOT_DRAFT', $e->errorCode);
        }

        $this->expectException(ApiException::class);
        app(DeleteAnnouncementDraft::class)($sent);
    }

    public function test_attachments_are_stored_privately_and_limited_to_five(): void
    {
        Storage::fake('local');
        $draft = Announcement::factory()->create();

        $attachment = app(AddAnnouncementAttachment::class)($draft, UploadedFile::fake()->create('reglement.pdf', 200, 'application/pdf'));

        $this->assertSame('reglement.pdf', $attachment->original_name);
        $this->assertStringStartsWith("announcements/{$draft->id}/", $attachment->path);
        $this->assertStringNotContainsString('reglement', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);

        foreach (range(2, 5) as $i) {
            app(AddAnnouncementAttachment::class)($draft, UploadedFile::fake()->create("f{$i}.pdf", 10, 'application/pdf'));
        }

        $this->expectException(ValidationException::class);
        app(AddAnnouncementAttachment::class)($draft, UploadedFile::fake()->create('f6.pdf', 10, 'application/pdf'));
    }

    public function test_deleting_a_draft_removes_its_files(): void
    {
        Storage::fake('local');
        $draft = Announcement::factory()->create();
        $attachment = app(AddAnnouncementAttachment::class)($draft, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        app(DeleteAnnouncementDraft::class)($draft);

        Storage::disk('local')->assertMissing($attachment->path);
        $this->assertDatabaseMissing('announcements', ['id' => $draft->id]);
    }
}
