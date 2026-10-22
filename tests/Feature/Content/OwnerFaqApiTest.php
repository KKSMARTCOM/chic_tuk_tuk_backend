<?php

namespace Tests\Feature\Content;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\FaqEntry;
use App\Models\FaqSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Spec 2026-10-22-faq-proprietaire, §3 et §4.2. */
class OwnerFaqApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FaqSection::query()->delete();
    }

    private function as(Profil $profil = Profil::Owner): self
    {
        $user = User::factory()->profil($profil)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view-own-contracts', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function entry(FaqSection $section, string $question, bool $visible, int $position = 0): void
    {
        FaqEntry::create(['faq_section_id' => $section->id, 'question' => $question, 'answer' => "**{$question}**", 'position' => $position, 'is_visible' => $visible]);
    }

    public function test_the_owner_reads_only_visible_entries_and_non_empty_sections(): void
    {
        $b = FaqSection::create(['title' => 'B', 'position' => 1]);
        $a = FaqSection::create(['title' => 'A', 'position' => 0]);
        $hidden = FaqSection::create(['title' => 'Cachée', 'position' => 2]);
        $this->entry($a, 'A2', true, 1);
        $this->entry($a, 'A1', true, 0);
        $this->entry($a, 'A masquée', false, 2);
        $this->entry($b, 'B1', true);
        $this->entry($hidden, 'H', false);

        $sections = $this->as()->getJson('/api/v1/owner/faq')->assertOk()->json();

        $this->assertSame(['A', 'B'], array_column($sections, 'title'));
        $this->assertSame(['A1', 'A2'], array_column($sections[0]['entries'], 'question'));
        $this->assertStringContainsString('<strong>A1</strong>', $sections[0]['entries'][0]['answer_html']);
    }

    public function test_a_fully_hidden_faq_is_an_empty_list(): void
    {
        $this->entry(FaqSection::create(['title' => 'A', 'position' => 0]), 'Q', false);

        $this->as()->getJson('/api/v1/owner/faq')->assertOk()->assertExactJson([]);
    }

    public function test_the_owner_never_receives_the_markdown_source(): void
    {
        $this->entry(FaqSection::create(['title' => 'A', 'position' => 0]), 'Q', true);

        $entry = $this->as()->getJson('/api/v1/owner/faq')->json('0.entries.0');

        $this->assertSame(['id', 'question', 'answer_html'], array_keys($entry));
    }

    public function test_only_an_owner_reads_it(): void
    {
        $this->as(Profil::Admin)->getJson('/api/v1/owner/faq')->assertForbidden();
        $this->as(Profil::Driver)->getJson('/api/v1/owner/faq')->assertForbidden();
    }
}
