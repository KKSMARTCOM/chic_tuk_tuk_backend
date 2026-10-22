<?php

namespace Tests\Feature\Content;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\FaqEntry;
use App\Models\FaqSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Spec 2026-10-22-faq-proprietaire, §3 et §4.1. */
class AdminFaqApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Repartir d'une FAQ vide : le glossaire de départ est testé à part.
        FaqSection::query()->delete();
    }

    private function as(array $permissions = ['manage-business-settings']): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function section(string $title, int $position, int $entries = 0): FaqSection
    {
        $section = FaqSection::create(['title' => $title, 'position' => $position]);
        foreach (range(0, $entries - 1) as $i) {
            if ($entries > 0) {
                FaqEntry::create(['faq_section_id' => $section->id, 'question' => "{$title} Q{$i}", 'answer' => 'R', 'position' => $i]);
            }
        }

        return $section;
    }

    public function test_only_the_administrator_manages_the_faq(): void
    {
        $section = $this->section('A', 0, 1);
        $user = $this->as(['view-payments']);

        $user->getJson('/api/v1/admin/faq')->assertForbidden();
        $user->postJson('/api/v1/admin/faq/sections', ['title' => 'X'])->assertForbidden();
        $user->patchJson("/api/v1/admin/faq/sections/{$section->id}", ['title' => 'X'])->assertForbidden();
        $user->deleteJson("/api/v1/admin/faq/sections/{$section->id}")->assertForbidden();
        $user->postJson('/api/v1/admin/faq/entries', [])->assertForbidden();
    }

    public function test_create_rename_and_delete_a_section(): void
    {
        $admin = $this->as();
        $id = $admin->postJson('/api/v1/admin/faq/sections', ['title' => 'Le contrat'])->assertOk()->json('sections.0.id');
        $admin->patchJson("/api/v1/admin/faq/sections/{$id}", ['title' => 'Votre contrat'])->assertOk()->assertJsonPath('sections.0.title', 'Votre contrat');
        $admin->postJson('/api/v1/admin/faq/entries', ['faq_section_id' => $id, 'question' => 'Q ?', 'answer' => 'R', 'is_visible' => true])->assertOk();

        $admin->deleteJson("/api/v1/admin/faq/sections/{$id}")->assertOk()->assertJsonCount(0, 'sections');
        $this->assertSame(0, FaqEntry::count());
    }

    public function test_titles_are_unique_and_required(): void
    {
        $this->section('Le contrat', 0);
        $admin = $this->as();

        $admin->postJson('/api/v1/admin/faq/sections', ['title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');
        $admin->postJson('/api/v1/admin/faq/sections', ['title' => 'Le contrat'])->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_moving_at_the_edges_changes_nothing(): void
    {
        $a = $this->section('A', 0);
        $this->section('B', 1);
        $admin = $this->as();

        $admin->postJson("/api/v1/admin/faq/sections/{$a->id}/move", ['direction' => 'up'])->assertOk()->assertJsonPath('sections.0.title', 'A');
        $admin->postJson("/api/v1/admin/faq/sections/{$a->id}/move", ['direction' => 'down'])->assertOk()->assertJsonPath('sections.0.title', 'B');
        $admin->postJson("/api/v1/admin/faq/sections/{$a->id}/move", ['direction' => 'down'])->assertOk()->assertJsonPath('sections.1.title', 'A');
    }

    public function test_positions_stay_contiguous_after_a_deletion(): void
    {
        $section = $this->section('A', 0, 3);
        $middle = $section->entries()->where('position', 1)->sole();

        $this->as()->deleteJson("/api/v1/admin/faq/entries/{$middle->id}")->assertOk();

        $this->assertSame([0, 1], $section->entries()->pluck('position')->all());
    }

    public function test_moving_an_entry_to_another_section_puts_it_last(): void
    {
        $a = $this->section('A', 0, 3);
        $b = $this->section('B', 1, 2);
        $first = $a->entries()->where('position', 0)->sole();

        $this->as()->patchJson("/api/v1/admin/faq/entries/{$first->id}", ['faq_section_id' => $b->id])->assertOk();

        $this->assertSame(2, $first->fresh()->position);
        $this->assertSame($b->id, $first->fresh()->faq_section_id);
        $this->assertSame([0, 1], $a->entries()->pluck('position')->all());
    }

    public function test_the_answer_html_is_safe(): void
    {
        $section = $this->section('A', 0);
        $html = $this->as()->postJson('/api/v1/admin/faq/entries', [
            'faq_section_id' => $section->id, 'question' => 'Q ?',
            'answer' => "**Gras**\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))\n\n<img src=x onerror=alert(1)>",
        ])->assertOk()->json('sections.0.entries.0.answer_html');

        $this->assertStringContainsString('<strong>Gras</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_the_journal_traces_without_copying_the_answer(): void
    {
        $section = $this->section('A', 0);
        $admin = $this->as();
        $id = $admin->postJson('/api/v1/admin/faq/entries', ['faq_section_id' => $section->id, 'question' => 'Q ?', 'answer' => 'Réponse secrète'])
            ->json('sections.0.entries.0.id');
        $admin->patchJson("/api/v1/admin/faq/entries/{$id}", ['answer' => 'Autre réponse secrète'])->assertOk();
        $admin->patchJson("/api/v1/admin/faq/entries/{$id}", ['is_visible' => true])->assertOk();

        $events = Activity::query()->where('event', 'like', 'settings.faq_%')->orderBy('id')->get();
        $this->assertSame(['settings.faq_entry_created', 'settings.faq_entry_updated', 'settings.faq_entry_shown'], $events->pluck('event')->all());
        $this->assertStringContainsString('réponse modifiée', $events[1]->description);
        foreach ($events as $event) {
            $this->assertStringNotContainsString('secrète', json_encode([$event->description, $event->properties]));
        }
    }

    public function test_the_same_section_in_capitals_is_not_a_move(): void
    {
        $section = $this->section('A', 0, 3);
        $first = $section->entries()->where('position', 0)->sole();

        $this->as()->patchJson("/api/v1/admin/faq/entries/{$first->id}", ['faq_section_id' => strtoupper($section->id), 'question' => 'Nouvelle ?'])->assertOk();

        $this->assertSame(0, $first->fresh()->position);
    }

    public function test_moving_locks_the_siblings(): void
    {
        $a = $this->section('A', 0);
        $this->section('B', 1);
        $locked = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$locked) {
            if (str_contains($query->sql, '"faq_sections"') && str_contains(strtolower($query->sql), 'for update')) {
                $locked = true;
            }
        });

        $this->as()->postJson("/api/v1/admin/faq/sections/{$a->id}/move", ['direction' => 'down'])->assertOk();

        $this->assertTrue($locked);
    }
}
