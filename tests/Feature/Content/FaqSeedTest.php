<?php

namespace Tests\Feature\Content;

use App\Models\FaqEntry;
use App\Models\FaqSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 2026-10-22-faq-proprietaire, §2.3 et annexe A : le glossaire de départ, masqué. */
class FaqSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seed_creates_the_six_sections_in_order(): void
    {
        $this->assertSame(
            ['Le contrat', 'Les jours du mois', "L'argent du mois", 'Les cumuls', 'Les versements', 'Questions générales'],
            FaqSection::orderBy('position')->pluck('title')->all(),
        );
    }

    public function test_every_seeded_question_is_hidden(): void
    {
        $this->assertGreaterThan(20, FaqEntry::count());
        $this->assertSame(0, FaqEntry::where('is_visible', true)->count());
    }

    public function test_the_general_questions_section_is_empty(): void
    {
        $this->assertSame(0, FaqSection::where('title', 'Questions générales')->sole()->entries()->count());
    }

    public function test_the_seed_carries_the_annex_wording(): void
    {
        $entry = FaqEntry::where('question', 'Comment est calculé le « Solde dû » ?')->sole();

        $this->assertStringContainsString('Le mois où le revenu total prévu au contrat est atteint', $entry->answer);
        $this->assertStringContainsString('**Formule :**', $entry->answer);
        $this->assertSame("L'argent du mois", $entry->section->title);
    }

    public function test_no_seeded_text_mentions_the_agency_share(): void
    {
        foreach (FaqEntry::all() as $entry) {
            $text = mb_strtolower($entry->question.' '.$entry->answer);
            $this->assertStringNotContainsString("part de l'agence", $text, $entry->question);
            $this->assertStringNotContainsString("revient à l'agence", $text, $entry->question);
        }
    }

    public function test_running_the_seed_again_adds_nothing(): void
    {
        $count = FaqEntry::count();

        (require database_path('migrations/2026_10_22_200100_seed_owner_faq.php'))->up();

        $this->assertSame($count, FaqEntry::count());
        $this->assertSame(6, FaqSection::count());
    }
}
