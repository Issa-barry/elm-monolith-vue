<?php

namespace App\Services\ImportRh;

use App\Models\Employe;
use App\Models\ImportRh;
use App\Models\User;
use App\Services\SiteScopeService;

/**
 * Données d'un import RH envoyées au navigateur. Sans `rh-remunerations.read` (vérifiée à chaque
 * affichage, donc aussi si la permission est retirée après l'import), aucun montant de salaire n'est
 * envoyé : changements « sensibles » vidés, colonne salaire retirée des valeurs lues.
 */
class ImportRhPresenter
{
    public function resume(ImportRh $import): array
    {
        return [
            'id' => $import->id,
            'fichier_original' => $import->fichier_original,
            'statut' => $import->statut->value,
            'statut_label' => $import->statut->label(),
            'auteur' => $import->user?->name,
            'nb_lignes_total' => $import->nb_lignes_total,
            'nb_lignes_maj' => $import->nb_lignes_maj,
            'nb_lignes_inchange' => $import->nb_lignes_inchange,
            'nb_lignes_erreur' => $import->nb_lignes_erreur,
            'nb_employes_mis_a_jour' => $import->nb_employes_mis_a_jour,
            'nb_contrats_crees' => $import->nb_contrats_crees,
            'cree_le' => $import->created_at?->format('d/m/Y H:i'),
            'termine_le' => $import->termine_le?->format('d/m/Y H:i'),
        ];
    }

    public function detail(ImportRh $import, User $viewer): array
    {
        $rapport = $import->rapport ?? [];
        $lignes = $rapport['lignes'] ?? [];
        $visibles = $this->lignesVisibles($lignes, $viewer);

        return [
            ...$this->resume($import),
            'erreur_technique' => $import->erreur_technique,
            'est_pret' => $import->estPret(),
            'colonne_salaire_ignoree' => (bool) ($rapport['colonne_salaire_ignoree'] ?? false),
            'lignes' => array_map(fn (array $l) => $this->ligne($l, $viewer), $visibles),
            'lignes_masquees' => count($lignes) - count($visibles),
        ];
    }

    /** Lignes en erreur, valeurs lues masquées comme à l'écran (fichier d'erreurs). */
    public function lignesEnErreur(ImportRh $import, User $viewer): array
    {
        return array_values(array_filter(
            array_map(fn (array $l) => $this->ligne($l, $viewer), $this->lignesVisibles($import->rapport['lignes'] ?? [], $viewer)),
            fn (array $l) => $l['statut'] === 'erreur',
        ));
    }

    /**
     * Périmètre de consultation : sans vision de toutes les agences, seules les lignes des salariés
     * de ses agences (agence actuelle) sont montrées. Une ligne sans salarié reconnu (matricule
     * introuvable) ne révèle que le matricule saisi : elle reste visible.
     */
    private function lignesVisibles(array $lignes, User $viewer): array
    {
        if ($viewer->voitToutesLesAgences()) {
            return $lignes;
        }

        $sites = app(SiteScopeService::class)->accessibleSiteIds($viewer);
        $siteParEmploye = Employe::withTrashed()
            ->whereIn('id', array_filter(array_column($lignes, 'employe_id')))
            ->pluck('site_id', 'id');

        return array_values(array_filter($lignes, function (array $l) use ($sites, $siteParEmploye) {
            if (empty($l['employe_id'])) {
                return true;
            }

            $site = $siteParEmploye->get($l['employe_id']);

            return $site !== null && $sites->contains($site);
        }));
    }

    private function ligne(array $ligne, User $viewer): array
    {
        $voitSalaires = $viewer->can('rh-remunerations.read');

        $changements = array_map(function (array $c) use ($voitSalaires) {
            if (($c['sensible'] ?? false) && ! $voitSalaires) {
                return [...$c, 'avant' => null, 'apres' => null, 'masque' => true];
            }

            return [...$c, 'masque' => false];
        }, $ligne['changements'] ?? []);

        $valeurs = $ligne['valeurs'] ?? [];
        if (! $voitSalaires) {
            unset($valeurs[ColonnesImportRh::SALAIRE]);
        }

        return [
            'numero_ligne' => $ligne['numero_ligne'],
            'matricule' => $ligne['matricule'],
            'nom' => $ligne['nom'],
            'statut' => $ligne['statut'],
            'erreurs' => $ligne['erreurs'] ?? [],
            'avertissements' => $ligne['avertissements'] ?? [],
            'changements' => $changements,
            'valeurs' => $valeurs,
        ];
    }
}
