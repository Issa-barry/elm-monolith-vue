<?php

namespace App\Services\Tresorerie\Obligations;

/**
 * Un type d'obligation contribuant au besoin de financement d'une agence
 * (commissions livreurs, propriétaires, salaires, et demain "Site"/
 * "Consultant"...). Enregistré dans ObligationsAgenceService::CONTRIBUTORS —
 * ajouter un nouveau type de commission ne doit JAMAIS passer par une
 * condition supplémentaire dans le service, seulement par une nouvelle classe
 * implémentant cette interface.
 *
 * Un contributeur ne doit rattacher un montant à une agence que s'il sait,
 * explicitement, que CETTE agence est responsable du paiement (cf.
 * SalaireObligationContributor : le site vient de l'employé lui-même, jamais
 * d'une agence par défaut). Un type de commission dont personne n'est
 * explicitement responsable ne doit pas implémenter cette interface tant que
 * cette responsabilité n'est pas configurée quelque part dans le métier.
 */
interface ObligationContributor
{
    /**
     * Clés de colonnes que ce contributeur écrit dans $besoin (ex: ['livreurs_p1', 'livreurs_p2']).
     * Chaque clé aura automatiquement un pendant "{cle}_du" (montant théorique total).
     *
     * @return list<string>
     */
    public function colonnes(): array;

    /**
     * Échéance de règlement de chaque colonne : "quinzaine_1" (réglée avec la
     * 1re quinzaine, ex: livreurs_p1) ou "fin_de_mois" (réglée avec la 2e
     * quinzaine/fin de mois, ex: livreurs_p2, propriétaires, salaires). Permet
     * à FinancementAgenceService de calculer un total par échéance sans jamais
     * connaître le nom d'une colonne particulière.
     *
     * @return array<string, 'quinzaine_1'|'fin_de_mois'>
     */
    public function echeancesParColonne(): array;

    /**
     * Alimente $besoin (keyed par site_id, ou le sentinel "sans agence" du
     * service appelant) avec les montants restants et théoriques de ce
     * contributeur pour le mois donné.
     *
     * @param  array<string, array<string, float>>  $besoin
     */
    public function collecter(string $organizationId, int $annee, int $mois, array &$besoin): void;

    /**
     * Détail par bénéficiaire (drill-down), pour une agence donnée (null = "sans agence") —
     * une entrée par colonne déclarée dans colonnes().
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function detail(string $organizationId, int $annee, int $mois, ?string $siteId): array;
}
