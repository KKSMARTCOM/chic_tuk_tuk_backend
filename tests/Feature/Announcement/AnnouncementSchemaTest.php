<?php

namespace Tests\Feature\Announcement;

use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 2026-10-13, §3. */
class AnnouncementSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_keeps_its_targeting(): void
    {
        $announcement = Announcement::factory()->create([
            'target_profiles' => ['driver', 'owner'],
            'target_user_ids' => [User::factory()->create()->id],
        ]);

        $fresh = $announcement->fresh();
        $this->assertSame(AnnouncementStatus::Draft, $fresh->status);
        $this->assertSame(['driver', 'owner'], $fresh->target_profiles);
        $this->assertCount(1, $fresh->target_user_ids);
    }

    public function test_a_user_is_a_recipient_only_once(): void
    {
        $recipient = AnnouncementRecipient::factory()->create();

        $this->expectException(QueryException::class);
        AnnouncementRecipient::factory()->create([
            'announcement_id' => $recipient->announcement_id,
            'user_id' => $recipient->user_id,
        ]);
    }

    public function test_deleting_an_announcement_deletes_its_recipients(): void
    {
        $recipient = AnnouncementRecipient::factory()->create();

        $recipient->announcement->delete();

        $this->assertDatabaseMissing('announcement_recipients', ['id' => $recipient->id]);
    }
}
