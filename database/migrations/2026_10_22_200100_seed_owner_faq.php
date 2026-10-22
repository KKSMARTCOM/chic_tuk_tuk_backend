<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Le glossaire de départ de la FAQ propriétaire (spec 2026-10-22-faq-proprietaire, annexe A),
 * recopié mot pour mot, en Markdown, TOUT MASQUÉ : l'admin le relit avant de le montrer. Une
 * migration et non un seeder, pour qu'il ne passe qu'une fois ; elle ne fait rien si la FAQ
 * a déjà une rubrique.
 */
return new class extends Migration
{
    private const CONTENT = [
        ['Le contrat', [
            ['Que représente la ligne « Caractéristique » ?', 'C\'est l\'immatriculation de votre véhicule. Chaque fiche concerne un seul véhicule.'],
            ['Que représente le « Type de contrat » ?', 'C\'est la durée du contrat de votre véhicule, celui sur lequel l\'agent travaille, en mois (par exemple 24 mois). Elle fixe le montant journalier que l\'agent verse et le total des charges du contrat.'],
            ['Que représente le « Statut jours de pause » ?', 'Ce sont les jours ouvrés de pause déjà pris depuis le début du contrat, par tous les agents qui ont conduit votre véhicule, sur le nombre de jours de pause prévus au contrat.

- Droit : 2 jours par mois de contrat. Un contrat de 24 mois donne 48 jours.
- Exemple : « 05/48 » veut dire 5 jours pris sur 48.'],
            ['Que représente la « Date de démarrage » ?', 'C\'est le jour où votre véhicule a commencé à travailler avec un agent sous contrat.'],
            ['Que représente le « Nombre de mois effectué » ?', 'C\'est le nombre de mois où votre véhicule a travaillé, sur la durée du contrat. Un mois compte s\'il a au moins un jour de travail (un jour ouvré qui n\'est ni en pause ni en immobilisation). Un mois entièrement à l\'arrêt ne compte pas : le contrat se prolonge d\'autant.

- Exemple : « 11 | 24 » veut dire 11 mois travaillés sur 24.'],
        ]],
        ['Les jours du mois', [
            ['Que représentent les « Jours ouvrés (Total) » ?', 'Ce sont les jours du lundi au vendredi du mois, pendant la durée du contrat. Les jours fériés ne sont pas retirés.'],
            ['Que représentent les « Jours de pause » ?', 'Ce sont les jours ouvrés du mois où l\'agent était en pause. Ils sont retirés des jours ouvrés : l\'agent ne verse rien ces jours-là.'],
            ['Que représentent les « Jours d\'immobilisation » ?', 'Ce sont les jours ouvrés où votre véhicule était arrêté pour une autre raison qu\'une pause de l\'agent, par exemple en attendant un nouvel agent. Ils sont retirés des jours ouvrés.'],
            ['Que représentent les « Paiements en instance » ?', 'Ce sont les jours travaillés du mois dont l\'argent n\'était pas encore reçu à la date de la fiche. Ils sont retirés des jours comptabilisés de ce mois. Quand l\'argent arrive, il est compté dans une fiche suivante, comme paiement recouvré. Vous ne le perdez pas.'],
            ['Que représentent les « Paiements recouvrés » ?', 'Ce sont des paiements de mois précédents dont la fiche était déjà établie, et dont l\'argent a été reçu depuis. Ils s\'ajoutent aux jours comptabilisés du mois.'],
            ['Que représentent les « Jours comptabilisés » ?', 'Ce sont les jours dont l\'argent a vraiment été reçu et qui vous sont comptés ce mois-ci.

**Formule :** Jours comptabilisés = jours ouvrés − jours de pause − jours d\'immobilisation − paiements en instance + paiements recouvrés

Exemple : 22 − 2 − 0 − 1 + 2 = 21 jours.'],
            ['Mes fiches d\'avant octobre 2026 ne suivent pas cette formule : pourquoi ?', 'Jusqu\'en septembre 2026, la fiche comptait les jours sur le calendrier seul, et les paiements recouvrés y figuraient en francs, sur une ligne à part. Depuis octobre 2026, un jour comptabilisé est un jour dont l\'argent a été reçu. Le solde dû de vos anciennes fiches ne change pas.'],
        ]],
        ['L\'argent du mois', [
            ['Que représente le « Montant journalier généré » ?', 'C\'est ce que l\'agent verse pour un jour de travail, une fois la taxe retirée. Il est fixé par la durée du contrat.'],
            ['Comment est calculé le « Total recettes » ?', 'C\'est ce que votre véhicule a rapporté ce mois-ci.

**Formule :** Total recettes = jours comptabilisés × montant journalier

Exemple : 21 × 5 000 F = 105 000 F.'],
            ['Que représentent les lignes de charges (connexion internet, Spotify, manager) ?', 'Ce sont les frais du véhicule, prélevés chaque mois où le véhicule travaille :

- Connexion internet illimitée : le Pocket Wifi offert aux passagers ;
- Spotify Premium partagé : la musique à bord ;
- Rémunération manager : le suivi du véhicule et de l\'agent.

Leur montant mensuel est fixé dans votre contrat. Ils sont prélevés sur le total recettes. Un mois où le véhicule ne travaille pas, aucune nouvelle charge n\'est due. Une charge s\'arrête quand son total sur la durée du contrat est atteint. Une charge qui n\'a pas été prélevée en entier, par exemple parce que les recettes du mois ne suffisaient pas, reste à prélever sur les mois suivants.'],
            ['Comment est calculé le « Solde dû » ?', 'C\'est ce qui vous revient pour le mois.

**Formule :** Solde dû = total recettes − charges prélevées

Exemple : 105 000 F − 15 000 F de charges = 90 000 F.

Le mois où le revenu total prévu au contrat est atteint, seule la part jusqu\'à ce total entre dans votre solde.'],
        ]],
        ['Les cumuls', [
            ['Que représente le « Cumul Revenu mensuel » ?', 'C\'est le total des recettes de votre véhicule depuis le début du contrat, ce mois compris.'],
            ['Que représente le « Cumul Charge mensuelle » ?', 'C\'est le total des charges prélevées depuis le début du contrat, ce mois compris.'],
            ['Que représente le « Cumul Revenu net mensuel » ?', 'C\'est ce qui vous est revenu depuis le début du contrat.

**Formule :** Cumul revenu net = cumul revenu − cumul charges'],
            ['Quand mon contrat prend-il fin ?', 'Votre contrat ne s\'arrête pas à une date. Il prend fin quand deux totaux sont atteints : le revenu total prévu au contrat, et le total des charges du contrat. Vous êtes prévenu quand le contrat prend fin.'],
        ]],
        ['Les versements', [
            ['Que représente le « Cumul revenu net » de l\'onglet Paiements ?', 'C\'est la somme des soldes dus de toutes vos fiches validées. C\'est ce que l\'agence vous doit depuis le début du contrat, avant les versements.'],
            ['Que représente le « Revenu versé » ?', 'C\'est la somme de tous les versements que l\'agence vous a faits pour ce véhicule.'],
            ['Que représente le « Revenu dû » ?', 'C\'est ce qui vous reste à recevoir.

**Formule :** Revenu dû = cumul revenu net − revenu versé'],
        ]],
        ['Questions générales', [
        ]],
    ];

    public function up(): void
    {
        if (DB::table('faq_sections')->exists()) {
            return;
        }

        $now = now();
        foreach (self::CONTENT as $sectionPosition => [$title, $entries]) {
            $sectionId = (string) Str::uuid();
            DB::table('faq_sections')->insert(['id' => $sectionId, 'title' => $title, 'position' => $sectionPosition, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($entries as $position => [$question, $answer]) {
                DB::table('faq_entries')->insert([
                    'id' => (string) Str::uuid(), 'faq_section_id' => $sectionId, 'question' => $question, 'answer' => $answer,
                    'position' => $position, 'is_visible' => false, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Le contenu appartient à l'admin dès qu'il l'a relu : on ne l'efface pas.
    }
};
