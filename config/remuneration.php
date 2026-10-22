<?php

/*
| Les fiches de rémunération des propriétaires (spec 2026-09-30).
*/
return [
    // Le premier mois qui produit des fiches. Les paiements des mois antérieurs restent
    // hors du système : ils ne compteront jamais en « recouvré » (spec §4.2).
    'first_month' => env('REMUNERATION_FIRST_MONTH', '2026-10'),

    // Un PDF validé vit un an sur le serveur, puis s'efface ; la fiche reste en base.
    'pdf_retention_days' => (int) env('REMUNERATION_PDF_RETENTION_DAYS', 365),

    // Le propriétaire a déjà reçu sa fiche par e-mail : trois téléchargements suffisent.
    'owner_download_limit' => (int) env('REMUNERATION_OWNER_DOWNLOAD_LIMIT', 3),

    // ⚠️ Éteint en production le temps de la reconstitution des fiches (spec 2026-10-02,
    // §3.2) : le propriétaire ne voit aucune fiche, et aucune ne lui part. Lu par
    // `RemunerationStatement::visibleToOwners()`, jamais directement.
    'owner_visible' => env('REMUNERATION_OWNER_VISIBLE', true),

    // Le jour de la livraison des versements (spec 2026-10-22-versement-proprietaire, §3.2) :
    // un versement daté d'avant ne notifie pas — c'est la régularisation de l'historique.
    // ⚠️ Absent, AUCUN versement ne notifie.
    'disbursement_notify_from' => env('REMUNERATION_DISBURSEMENT_NOTIFY_FROM'),

    'city' => 'Cotonou',

    // Les mentions légales du pied de page, reprises de la fiche d'origine.
    'company' => [
        'KOKA MOBILITY SARL',
        'Société à Responsabilité Limitée - CAPITAL : 1 000 000 FCFA',
        'Importation de motocycles et accessoires, Transports, Tourisme et Voyages, Services',
        // La fiche d'origine passe la ville à la ligne : on la suit.
        'Siège : Ilot : 3101, Quartier : Agla Hlazounto,',
        'COTONOU, BÉNIN',
        'Tél : +229 0196051569',
        'IFU 3202598323524 - N° RCCM RB/COT/25 B 41498',
    ],
];
