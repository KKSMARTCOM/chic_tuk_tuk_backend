<?php

namespace Tests\Feature\Announcement;

use App\Domains\Announcement\Application\Actions\ResolveAnnouncementRecipients;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 2026-10-13, §5.1. */
class ResolveAnnouncementRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profiles_and_people_are_merged_without_duplicates(): void
    {
        $driverA = User::factory()->profil(Profil::Driver)->create();
        $driverB = User::factory()->profil(Profil::Driver)->create();
        $owner = User::factory()->profil(Profil::Owner)->create();
        User::factory()->profil(Profil::Client)->create();

        $users = app(ResolveAnnouncementRecipients::class)(['driver'], [$owner->id, $driverA->id], null);

        $this->assertEqualsCanonicalizing([$driverA->id, $driverB->id, $owner->id], $users->pluck('id')->all());
    }

    public function test_inactive_accounts_and_the_sender_are_left_out(): void
    {
        $sender = User::factory()->profil(Profil::Admin)->create();
        $active = User::factory()->profil(Profil::Admin)->create();
        $inactive = User::factory()->profil(Profil::Admin)->inactive()->create();

        $users = app(ResolveAnnouncementRecipients::class)(['admin'], [$inactive->id], $sender);

        $this->assertSame([$active->id], $users->pluck('id')->all());
    }

    public function test_unknown_profiles_are_ignored(): void
    {
        User::factory()->profil(Profil::Driver)->create();

        $this->assertCount(0, app(ResolveAnnouncementRecipients::class)(['superuser'], [], null));
    }
}
