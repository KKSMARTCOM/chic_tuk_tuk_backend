<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Le glossaire remplace la FAQ propriétaire (spec 2026-10-30-glossaire-proprietaire) : chaque
 * question devient un terme, sa réponse une définition, et la ligne « **Formule :** » rejoint
 * le champ formule. Le statut visible ou masqué est gardé.
 *
 * ⚠️ `faq_sections` et `faq_entries` restent en place : l'image précédente les lit encore en cas de
 * retour arrière. Une migration ultérieure les retirera.
 */
return new class extends Migration
{
    /** Les questions du contenu de départ qui ne nomment pas leur terme entre guillemets. */
    private const TERMS = [
        'Que représentent les lignes de charges (connexion internet, Spotify, manager) ?' => 'Charges (connexion internet, Spotify, manager)',
        'Mes fiches d\'avant octobre 2026 ne suivent pas cette formule : pourquoi ?' => 'Fiches d\'avant octobre 2026',
        'Quand mon contrat prend-il fin ?' => 'Fin du contrat',
    ];

    public function up(): void
    {
        Schema::create('glossary_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('term', 255);
            // Le terme sans casse ni accents : l'unicité et l'ordre alphabétique.
            $table->string('term_key', 255)->unique();
            // Du Markdown, rendu par le backend comme les communiqués.
            $table->text('definition');
            $table->string('formula', 500)->nullable();
            $table->boolean('is_visible')->default(false);
            $table->timestamps();
        });

        $used = [];
        foreach (DB::table('faq_entries')->orderBy('created_at')->orderBy('position')->get() as $entry) {
            $term = $this->termOf($entry->question);
            $key = $this->keyOf($term);
            // Deux questions sur le même terme : aucune n'est perdue, l'admin les fusionnera.
            for ($rank = 2; isset($used[$key]); $rank++) {
                $key = $this->keyOf("{$term} ({$rank})");
                if (! isset($used[$key])) {
                    $term = "{$term} ({$rank})";
                }
            }
            $used[$key] = true;
            [$definition, $formula] = $this->split($entry->answer);

            DB::table('glossary_terms')->insert([
                'id' => (string) Str::uuid(), 'term' => $term, 'term_key' => $key, 'definition' => $definition,
                'formula' => $formula, 'is_visible' => $entry->is_visible, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('glossary_terms');
    }

    private function termOf(string $question): string
    {
        if (isset(self::TERMS[$question])) {
            return self::TERMS[$question];
        }
        if (preg_match('/«\s*(.+?)\s*»/u', $question, $match)) {
            return $match[1];
        }

        return trim(preg_replace('/\s*\?\s*$/u', '', $question));
    }

    private function keyOf(string $term): string
    {
        return mb_strtolower(trim(Str::ascii(str_replace(['’', '‘'], "'", $term))));
    }

    /** @return array{0: string, 1: ?string} la définition sans sa ligne de formule, et la formule */
    private function split(string $answer): array
    {
        if (! preg_match('/^\*\*Formule\s*:\*\*[\s\x{00A0}]*(.+)$/mu', $answer, $match) || mb_strlen(trim($match[1])) > 500) {
            return [$answer, null];
        }
        $definition = trim(preg_replace('/\n{3,}/', "\n\n", str_replace($match[0], '', $answer)));

        // Une réponse réduite à sa formule garde son texte : une définition n'est jamais vide.
        return $definition === '' ? [$answer, null] : [$definition, trim($match[1])];
    }
};
