<?php

namespace Tests\Feature\Announcement;

use App\Domains\Announcement\Application\Mail\AnnouncementMail;
use App\Domains\Identity\Domain\Enums\Profil;
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
 * Ajouter des destinataires à un communiqué envoyé (spec 2026-11-04-communiques-relance-ajout) :
 * un propriétaire créé après l'envoi doit recevoir le même communiqué.
 */
class AddAnnouncementRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private User $sender;

    private function admin(array $permissions = ['draft-announcements', 'send-announcements']): User
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function as(User $user): self
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Un communiqué envoyé hier aux propriétaires d'alors. */
    private function sentToOwners(array $attributes = []): Announcement
    {
        $this->sender = $this->admin();
        $announcement = Announcement::factory()->sent()->create($attributes + [
            'title' => 'Session de formation', 'target_profiles' => ['owner'], 'target_user_ids' => [],
            'sent_by' => $this->sender->id, 'sent_at' => now()->subDay(),
        ]);
        foreach (User::where('profil', 'owner')->get() as $owner) {
            AnnouncementRecipient::factory()->for($announcement)->for($owner)->create(['profil' => 'owner', 'created_at' => now()->subDay()]);
        }

        return $announcement;
    }

    public function test_adding_a_person_delivers_the_original_send(): void
    {
        Mail::fake();
        User::factory()->profil(Profil::Owner)->count(2)->create();
        $announcement = $this->sentToOwners(['target_profiles' => []]);
        $newcomer = User::factory()->profil(Profil::Owner)->create();

        $this->as($this->sender)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", ['user_ids' => [$newcomer->id]])
            ->assertOk()
            ->assertJsonPath('recipients_count', 3);

        $row = AnnouncementRecipient::where('announcement_id', $announcement->id)->where('user_id', $newcomer->id)->sole();
        $this->assertSame('owner', $row->profil);
        $this->assertSame('Nouveau communiqué', Notification::where('user_id', $newcomer->id)->sole()->title);
        Mail::assertSent(AnnouncementMail::class, fn (AnnouncementMail $mail) => $mail->hasTo($newcomer->email) && ! $mail->reminder);
        $this->assertContains($newcomer->id, $announcement->fresh()->target_user_ids);

        $rows = collect($this->as($this->sender)->getJson("/api/v1/admin/announcements/{$announcement->id}/recipients")->json('recipients'))->keyBy('user_id');
        $this->assertNotNull($rows[$newcomer->id]['added_at']);
    }

    public function test_new_from_targeting_adds_only_the_newcomers(): void
    {
        Mail::fake();
        User::factory()->profil(Profil::Owner)->count(2)->create();
        $announcement = $this->sentToOwners();
        $newcomer = User::factory()->profil(Profil::Owner)->create(['email' => null]);

        $this->as($this->sender)->getJson("/api/v1/admin/announcements/{$announcement->id}/recipients/new")
            ->assertOk()->assertExactJson(['total' => 1, 'with_email' => 0]);

        $this->as($this->sender)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", ['new_from_targeting' => true])
            ->assertOk()->assertJsonPath('recipients_count', 3);

        $this->assertTrue(AnnouncementRecipient::where('announcement_id', $announcement->id)->where('user_id', $newcomer->id)->exists());
        $this->assertSame([], $announcement->fresh()->target_user_ids);
    }

    public function test_the_original_sender_is_never_added(): void
    {
        $announcement = $this->sentToOwners(['target_profiles' => ['admin']]);
        $other = $this->admin();

        $this->as($other)->getJson("/api/v1/admin/announcements/{$announcement->id}/recipients/new")
            ->assertOk()->assertJsonPath('total', 1);
        $this->as($other)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", ['new_from_targeting' => true])->assertOk();

        $added = AnnouncementRecipient::where('announcement_id', $announcement->id)->pluck('user_id')->all();
        $this->assertNotContains($this->sender->id, $added);
        $this->assertContains($other->id, $added);
    }

    public function test_existing_and_inactive_accounts_are_ignored(): void
    {
        Mail::fake();
        $existing = User::factory()->profil(Profil::Owner)->create();
        $announcement = $this->sentToOwners();
        $newcomer = User::factory()->profil(Profil::Owner)->create();
        $inactive = User::factory()->profil(Profil::Owner)->create(['is_active' => false]);

        $this->as($this->sender)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", ['user_ids' => [$existing->id, $newcomer->id, $inactive->id]])
            ->assertOk()->assertJsonPath('recipients_count', 2);

        $this->as($this->sender)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", ['user_ids' => [$existing->id]])
            ->assertStatus(422)->assertJsonPath('errors.user_ids.0', 'Personne à ajouter : les comptes choisis sont déjà destinataires, désactivés, ou l’expéditeur.');
        $this->as($this->sender)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", [])
            ->assertStatus(422)->assertJsonValidationErrors('user_ids');
    }

    public function test_only_a_sent_announcement_takes_recipients(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->profil(Profil::Owner)->create();

        foreach ([Announcement::factory()->create(), Announcement::factory()->withdrawn()->create()] as $announcement) {
            $this->as($admin)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", ['user_ids' => [$owner->id]])
                ->assertStatus(409)->assertJsonPath('code', 'ANNOUNCEMENT_NOT_SENT');
        }
    }

    public function test_adding_needs_send_announcements(): void
    {
        $announcement = $this->sentToOwners();
        $drafter = $this->admin(['draft-announcements']);

        $this->as($drafter)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", ['new_from_targeting' => true])->assertForbidden();
        $this->as($drafter)->getJson("/api/v1/admin/announcements/{$announcement->id}/recipients/new")->assertForbidden();
    }

    public function test_adding_is_journaled(): void
    {
        Mail::fake();
        $announcement = $this->sentToOwners();
        User::factory()->profil(Profil::Owner)->create();

        $this->as($this->sender)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", ['new_from_targeting' => true])->assertOk();

        $this->assertSame('a ajouté 1 destinataire(s) au communiqué « Session de formation »',
            Activity::where('event', 'announcement.recipients_added')->sole()->description);
    }

    // Relecture du 2026-11-04 : la recherche signale un destinataire même hors de la page affichée.
    public function test_the_search_flags_existing_recipients_of_the_announcement(): void
    {
        $existing = User::factory()->profil(Profil::Owner)->create(['name' => 'Awa Destinataire']);
        $announcement = $this->sentToOwners();
        User::factory()->profil(Profil::Owner)->create(['name' => 'Awa Nouvelle']);

        $rows = collect($this->as($this->sender)->getJson("/api/v1/admin/announcements/recipients/search?q=awa&announcement_id={$announcement->id}")
            ->assertOk()->json())->keyBy('name');

        $this->assertTrue($rows['Awa Destinataire']['is_recipient']);
        $this->assertFalse($rows['Awa Nouvelle']['is_recipient']);
        $this->assertSame($existing->id, $rows['Awa Destinataire']['id']);
    }

    public function test_the_refusal_does_not_claim_everyone_is_already_a_recipient(): void
    {
        $announcement = $this->sentToOwners();

        $this->as($this->sender)->postJson("/api/v1/admin/announcements/{$announcement->id}/recipients", ['user_ids' => [$this->sender->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.user_ids.0', 'Personne à ajouter : les comptes choisis sont déjà destinataires, désactivés, ou l’expéditeur.');
    }
}
