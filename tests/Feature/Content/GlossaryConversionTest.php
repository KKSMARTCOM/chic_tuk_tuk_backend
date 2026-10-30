<?php

namespace Tests\Feature\Content;

use App\Models\FaqEntry;
use App\Models\FaqSection;
use App\Models\GlossaryTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Spec 2026-10-30-glossaire-proprietaire : les questions de la FAQ deviennent des termes. */
class GlossaryConversionTest extends TestCase
{
    use RefreshDatabase;

    private function rerun(): void
    {
        Schema::drop('glossary_terms');
        (require database_path('migrations/2026_10_30_100000_create_glossary_terms.php'))->up();
    }

    public function test_every_seeded_question_becomes_a_term_still_hidden(): void
    {
        $this->assertSame(FaqEntry::count(), GlossaryTerm::count());
        $this->assertSame(0, GlossaryTerm::where('is_visible', true)->count());
    }

    public function test_the_term_is_what_the_question_names_and_no_question_remains(): void
    {
        $this->assertSame(1, GlossaryTerm::where('term', 'Caractéristique')->count());
        $this->assertSame(1, GlossaryTerm::where('term', 'Cumul revenu net')->count());
        $this->assertSame(1, GlossaryTerm::where('term', 'Fin du contrat')->count());
        $this->assertSame(0, GlossaryTerm::where('term', 'like', '%?%')->count());
        $this->assertSame(0, GlossaryTerm::where('term', 'like', 'Que %')->count());
    }

    public function test_the_formula_leaves_the_definition_and_the_example_stays(): void
    {
        $term = GlossaryTerm::where('term', 'Solde dû')->sole();

        $this->assertSame('Solde dû = total recettes − charges prélevées', $term->formula);
        $this->assertStringNotContainsString('Formule', $term->definition);
        $this->assertStringContainsString('Exemple : 105 000 F', $term->definition);
    }

    public function test_visibility_is_kept_and_two_questions_on_one_term_are_both_kept(): void
    {
        $section = FaqSection::orderBy('position')->first();
        FaqEntry::query()->delete();
        FaqEntry::create(['faq_section_id' => $section->id, 'question' => 'Que représente le « Solde » ?', 'answer' => 'A', 'position' => 0, 'is_visible' => true]);
        FaqEntry::create(['faq_section_id' => $section->id, 'question' => 'Comment lire le « solde » ?', 'answer' => 'B', 'position' => 1, 'is_visible' => false]);
        FaqEntry::create(['faq_section_id' => $section->id, 'question' => 'Une question libre ?', 'answer' => '**Formule :** a + b', 'position' => 2]);

        $this->rerun();

        $this->assertTrue(GlossaryTerm::where('term', 'Solde')->sole()->is_visible);
        $this->assertSame('B', GlossaryTerm::where('term', 'solde (2)')->sole()->definition);
        // Réduite à sa formule, la réponse reste la définition : jamais de définition vide.
        $this->assertSame('**Formule :** a + b', GlossaryTerm::where('term', 'Une question libre')->sole()->definition);
    }
}
