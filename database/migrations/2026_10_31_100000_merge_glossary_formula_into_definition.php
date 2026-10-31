<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La formule n'est plus un champ : elle revient dans la définition, décidé le 2026-10-31. Elle
 * reprend sa place, avant le premier paragraphe « Exemple », à défaut en fin de définition.
 *
 * ⚠️ La colonne `formula` reste, vide : l'image précédente la lit encore en cas de retour arrière.
 * Elle partira avec `faq_sections` et `faq_entries`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('glossary_terms')->whereNotNull('formula')->get() as $term) {
            $line = "**Formule :** {$term->formula}";
            $definition = preg_match('/^Exemple\b/mu', $term->definition, $match, PREG_OFFSET_CAPTURE)
                ? substr_replace($term->definition, "{$line}\n\n", $match[0][1], 0)
                : rtrim($term->definition)."\n\n{$line}";

            DB::table('glossary_terms')->where('id', $term->id)->update(['definition' => $definition, 'formula' => null]);
        }
    }

    public function down(): void
    {
        // La définition appartient à l'admin dès qu'il peut la modifier : on ne la redécoupe pas.
    }
};
