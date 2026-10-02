<?php

namespace App\Services\Tresorerie;

use App\Enums\ModePaiement;
use App\Enums\OperateurMobileMoney;
use App\Enums\TypeSupportTresorerie;
use App\Models\CompteTresorerie;
use Illuminate\Support\Collection;

/**
 * Moyens d'encaissement (hors espèces) réellement disponibles dans une agence — source unique de
 * la liste affichée par PaymentCard ET du contrôle serveur de Ventes\StoreEncaissementVenteController
 * (décision du 24/09/2026, cf. docs/encaissements.md).
 *
 * Un moyen n'existe que s'il existe un support de trésorerie ACTIF de l'agence (jamais une caisse
 * dédiée) pour le recevoir : aucun opérateur n'est proposé « par défaut ». Chaque Mobile Money est un
 * compte à part, porté par son propre support (`operateur_mobile_money`) ; un support Mobile Money
 * sans opérateur renseigné n'est jamais proposé (on ne saurait pas quel wallet il représente). Un
 * support Banque ouvre le virement et le chèque.
 *
 * Comptes communs (ADR 0016) : avec `$avecComptesCommuns`, un compte commun est aussi proposé dans
 * chacune de ses agences utilisatrices, à côté de leurs propres comptes — un moyen par compte, jamais
 * un routage automatique : l'agent choisit le compte sur lequel le client a réellement payé (numéro
 * affiché). Réservé à l'encaissement : payer depuis un compte commun n'est pas encore ouvert, les
 * décaissements (paiement de fiche) ne voient que les comptes propres à l'agence.
 *
 * Les espèces n'en font pas partie : elles vont toujours dans la caisse dédiée de l'auteur, selon
 * une règle propre à l'utilisateur (CaisseAgentResolver), pas à l'agence.
 */
class MoyensEncaissementResolver
{
    /**
     * @param  iterable<string|null>  $siteIds
     * @return array<string, list<array<string, mixed>>> moyens par site
     */
    public function parSite(string $organizationId, iterable $siteIds, bool $avecComptesCommuns = false): array
    {
        $siteIds = collect($siteIds)->filter()->unique()->values();
        if ($siteIds->isEmpty()) {
            return [];
        }

        $supports = $this->supportsEligibles($organizationId, $siteIds->all(), $avecComptesCommuns);

        return $siteIds
            ->mapWithKeys(fn (string $siteId) => [$siteId => $supports->filter(
                fn (CompteTresorerie $s) => $s->commun
                    ? $s->agencesUtilisatrices->contains('id', $siteId)
                    : $s->site_id === $siteId
            )])
            ->filter(fn (Collection $supportsSite) => $supportsSite->isNotEmpty())
            ->map(fn (Collection $supportsSite) => $this->moyensPourSupports($supportsSite))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function pourSite(string $organizationId, ?string $siteId, bool $avecComptesCommuns = false): array
    {
        return $siteId ? ($this->parSite($organizationId, [$siteId], $avecComptesCommuns)[$siteId] ?? []) : [];
    }

    /**
     * Support qui peut recevoir ce mode de paiement dans cette agence, ou null — le contrôle serveur
     * rejoue exactement la liste proposée, jamais une règle parallèle.
     */
    public function supportPour(string $organizationId, ?string $siteId, ?string $compteTresorerieId, string $modePaiement, bool $avecComptesCommuns = false): ?CompteTresorerie
    {
        if (! $siteId || ! $compteTresorerieId) {
            return null;
        }

        $disponible = collect($this->pourSite($organizationId, $siteId, $avecComptesCommuns))->contains(
            fn (array $moyen) => $moyen['compte_tresorerie_id'] === $compteTresorerieId && $moyen['mode_paiement'] === $modePaiement
        );

        return $disponible ? CompteTresorerie::find($compteTresorerieId) : null;
    }

    /**
     * Supports d'agence actifs pouvant recevoir un paiement : ceux des agences demandées et, pour
     * l'encaissement, les comptes communs dont l'une d'elles est utilisatrice. Sans
     * `$avecComptesCommuns`, un compte commun n'est jamais proposé, même dans son agence détentrice.
     *
     * @param  list<string>  $siteIds
     * @return Collection<int, CompteTresorerie>
     */
    private function supportsEligibles(string $organizationId, array $siteIds, bool $avecComptesCommuns): Collection
    {
        return CompteTresorerie::forOrg($organizationId)
            ->agence()
            ->actifs()
            ->where(fn ($q) => $q
                ->where(fn ($propres) => $propres->where('commun', false)->whereIn('site_id', $siteIds))
                ->when($avecComptesCommuns, fn ($q2) => $q2->orWhere(fn ($communs) => $communs
                    ->where('commun', true)
                    ->whereHas('agencesUtilisatrices', fn ($a) => $a->whereIn('sites.id', $siteIds)))))
            ->where(fn ($q) => $q
                ->where('type', TypeSupportTresorerie::BANQUE->value)
                ->orWhere(fn ($mm) => $mm
                    ->where('type', TypeSupportTresorerie::MOBILE_MONEY->value)
                    ->whereIn('operateur_mobile_money', array_map(fn (OperateurMobileMoney $o) => $o->value, OperateurMobileMoney::avecWallet()))))
            ->with(['site:id,nom', 'agencesUtilisatrices:id'])
            ->orderBy('libelle')
            ->get(['id', 'site_id', 'type', 'operateur_mobile_money', 'libelle', 'numero', 'commun']);
    }

    /**
     * Ordre stable : Mobile Money (ordre de l'enum), puis virement, puis chèque.
     *
     * @param  Collection<int, CompteTresorerie>  $supports
     * @return list<array<string, mixed>>
     */
    private function moyensPourSupports(Collection $supports): array
    {
        $mobileMoney = $supports->where('type', TypeSupportTresorerie::MOBILE_MONEY);
        $banques = $supports->where('type', TypeSupportTresorerie::BANQUE);
        $parOperateur = $mobileMoney->groupBy(fn (CompteTresorerie $s) => $s->operateur_mobile_money->value);

        $moyens = [];

        foreach (OperateurMobileMoney::avecWallet() as $operateur) {
            $supportsOperateur = $parOperateur->get($operateur->value, collect());
            foreach ($supportsOperateur as $support) {
                // Plusieurs comptes du même opérateur dans l'agence : le nom du compte les distingue ;
                // un compte commun dit toujours qu'il l'est, et qui le détient.
                $label = match (true) {
                    (bool) $support->commun => "{$operateur->label()} — compte commun ({$support->site?->nom})",
                    $supportsOperateur->count() > 1 => "{$operateur->label()} — {$support->libelle}",
                    default => $operateur->label(),
                };
                $moyens[] = $this->moyen($support, ModePaiement::MOBILE_MONEY, $label, true, $operateur);
            }
        }

        foreach ($banques as $support) {
            $moyens[] = $this->moyen($support, ModePaiement::VIREMENT, "Virement bancaire — {$this->nomBanque($support)}", true);
        }
        foreach ($banques as $support) {
            $moyens[] = $this->moyen($support, ModePaiement::CHEQUE, "Chèque — {$this->nomBanque($support)}", false);
        }

        return $moyens;
    }

    private function nomBanque(CompteTresorerie $support): string
    {
        return $support->commun ? "{$support->libelle} (compte commun, {$support->site?->nom})" : $support->libelle;
    }

    /** @return array<string, mixed> */
    private function moyen(CompteTresorerie $support, ModePaiement $mode, string $label, bool $referenceRequise, ?OperateurMobileMoney $operateur = null): array
    {
        return [
            'key' => "{$mode->value}:{$support->id}",
            'label' => $label,
            'mode_paiement' => $mode->value,
            'operateur_mobile_money' => $operateur?->value,
            'compte_tresorerie_id' => $support->id,
            'reference_requise' => $referenceRequise,
            // Numéro du compte (numéro marchand) : l'agent le compare au reçu du client.
            'numero' => $support->numero,
            // Agence qui détiendra l'argent : celle du compte, pas forcément celle qui encaisse.
            'site_detenteur_id' => $support->site_id,
            'site_detenteur_nom' => $support->site?->nom,
        ];
    }
}
