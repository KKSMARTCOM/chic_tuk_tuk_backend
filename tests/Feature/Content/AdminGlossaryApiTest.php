<?php

namespace Tests\Feature\Content;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\GlossaryTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Spec 2026-10-30-glossaire-proprietaire. */
class AdminGlossaryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Repartir d'un glossaire vide : la conversion de la FAQ est testée à part.
        GlossaryTerm::query()->delete();
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

    public function test_only_the_administrator_manages_the_glossary(): void
    {
        $term = GlossaryTerm::create(['term' => 'Solde dû', 'definition' => 'D']);
        $user = $this->as(['view-payments']);

        $user->getJson('/api/v1/admin/glossary')->assertForbidden();
        $user->postJson('/api/v1/admin/glossary/terms', ['term' => 'X', 'definition' => 'D'])->assertForbidden();
        $user->patchJson("/api/v1/admin/glossary/terms/{$term->id}", ['term' => 'X'])->assertForbidden();
        $user->deleteJson("/api/v1/admin/glossary/terms/{$term->id}")->assertForbidden();
    }

    public function test_terms_come_back_in_alphabetical_order_whatever_the_accents(): void
    {
        $admin = $this->as();
        foreach (['Solde dû', 'Échéance', 'cumul', 'Total'] as $term) {
            $admin->postJson('/api/v1/admin/glossary/terms', ['term' => $term, 'definition' => 'D'])->assertOk();
        }

        $this->assertSame(['cumul', 'Échéance', 'Solde dû', 'Total'], array_column($admin->getJson('/api/v1/admin/glossary')->json('terms'), 'term'));
    }

    public function test_a_term_carries_its_definition_its_formula_and_starts_hidden(): void
    {
        $this->as()->postJson('/api/v1/admin/glossary/terms', ['term' => ' Solde dû ', 'definition' => '**Ce qui revient.**', 'formula' => ' a − b '])
            ->assertOk()
            ->assertJsonPath('terms.0.term', 'Solde dû')
            ->assertJsonPath('terms.0.formula', 'a − b')
            ->assertJsonPath('terms.0.is_visible', false)
            ->assertJsonPath('terms.0.definition', '**Ce qui revient.**')
            ->assertJsonPath('terms.0.definition_html', fn ($html) => str_contains($html, '<strong>'));

    }

    public function test_a_term_is_unique_without_case_nor_accents(): void
    {
        $admin = $this->as();
        $id = $admin->postJson('/api/v1/admin/glossary/terms', ['term' => 'Solde dû', 'definition' => 'D'])->json('terms.0.id');

        $admin->postJson('/api/v1/admin/glossary/terms', ['term' => 'SOLDE DU', 'definition' => 'D'])
            ->assertUnprocessable()->assertJsonValidationErrors(['term' => 'Ce terme existe déjà.']);
        // Garder son propre nom n'est pas un doublon.
        $admin->patchJson("/api/v1/admin/glossary/terms/{$id}", ['term' => 'Solde Dû'])->assertOk();
    }

    public function test_term_and_definition_are_required_the_formula_is_not(): void
    {
        $admin = $this->as();

        $admin->postJson('/api/v1/admin/glossary/terms', [])->assertUnprocessable()->assertJsonValidationErrors(['term', 'definition']);
        $admin->postJson('/api/v1/admin/glossary/terms', ['term' => 'A', 'definition' => 'D', 'formula' => ''])->assertOk()->assertJsonPath('terms.0.formula', null);
    }

    public function test_show_all_changes_the_visibility_alone_and_the_journal_says_so(): void
    {
        $term = GlossaryTerm::create(['term' => 'Solde dû', 'definition' => 'Secret', 'formula' => 'a − b']);

        $this->as()->patchJson("/api/v1/admin/glossary/terms/{$term->id}", ['is_visible' => true])->assertOk()->assertJsonPath('terms.0.is_visible', true);

        $this->assertSame('a − b', $term->fresh()->formula);
        $entry = Activity::where('event', 'settings.glossary_term_shown')->sole();
        $this->assertStringContainsString('Solde dû', $entry->description);
        $this->assertSame(0, Activity::where('event', 'settings.glossary_term_updated')->count());
    }

    public function test_editing_is_traced_without_copying_the_definition_and_deleting_removes_the_term(): void
    {
        $term = GlossaryTerm::create(['term' => 'Solde dû', 'definition' => 'D']);
        $admin = $this->as();

        $admin->patchJson("/api/v1/admin/glossary/terms/{$term->id}", ['definition' => 'Texte confidentiel'])->assertOk();
        $this->assertStringNotContainsString('confidentiel', Activity::where('event', 'settings.glossary_term_updated')->sole()->description);

        $admin->deleteJson("/api/v1/admin/glossary/terms/{$term->id}")->assertOk()->assertJsonCount(0, 'terms');
        $this->assertSame(1, Activity::where('event', 'settings.glossary_term_deleted')->count());
    }
}
