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
 * Les espèces n'en font pas partie : elles vont toujours dans la caisse dédiée de l'auteur, selon
 * une règle propre à l'utilisateur (CaisseAgentResolver), pas à l'agence.
 */
class MoyensEncaissementResolver
{
    /**
     * @param  iterable<string|null>  $siteIds
     * @return array<string, list<array<string, mixed>>> moyens par site
     */
    public function parSite(string $organizationId, iterable $siteIds): array
    {
        $siteIds = collect($siteIds)->filter()->unique()->values();
        if ($siteIds->isEmpty()) {
            return [];
        }

        return $this->supportsEligibles($organizationId, $siteIds->all())
            ->groupBy('site_id')
            ->map(fn (Collection $supports) => $this->moyensPourSupports($supports))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function pourSite(string $organizationId, ?string $siteId): array
    {
        return $siteId ? ($this->parSite($organizationId, [$siteId])[$siteId] ?? []) : [];
    }

    /**
     * Support qui peut recevoir ce mode de paiement dans cette agence, ou null — le contrôle serveur
     * rejoue exactement la liste proposée, jamais une règle parallèle.
     */
    public function supportPour(string $organizationId, ?string $siteId, ?string $compteTresorerieId, string $modePaiement): ?CompteTresorerie
    {
        if (! $siteId || ! $compteTresorerieId) {
            return null;
        }

        $disponible = collect($this->pourSite($organizationId, $siteId))->contains(
            fn (array $moyen) => $moyen['compte_tresorerie_id'] === $compteTresorerieId && $moyen['mode_paiement'] === $modePaiement
        );

        return $disponible ? CompteTresorerie::find($compteTresorerieId) : null;
    }

    /**
     * @param  list<string>  $siteIds
     * @return Collection<int, CompteTresorerie>
     */
    private function supportsEligibles(string $organizationId, array $siteIds): Collection
    {
        return CompteTresorerie::forOrg($organizationId)
            ->agence()
            ->actifs()
            ->whereIn('site_id', $siteIds)
            ->where(fn ($q) => $q
                ->where('type', TypeSupportTresorerie::BANQUE->value)
                ->orWhere(fn ($mm) => $mm
                    ->where('type', TypeSupportTresorerie::MOBILE_MONEY->value)
                    ->whereIn('operateur_mobile_money', array_map(fn (OperateurMobileMoney $o) => $o->value, OperateurMobileMoney::avecWallet()))))
            ->orderBy('libelle')
            ->get(['id', 'site_id', 'type', 'operateur_mobile_money', 'libelle']);
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
                // Deux wallets du même opérateur dans l'agence : le libellé du support les distingue.
                $label = $supportsOperateur->count() > 1
                    ? "{$operateur->label()} — {$support->libelle}"
                    : $operateur->label();
                $moyens[] = $this->moyen($support, ModePaiement::MOBILE_MONEY, $label, true, $operateur);
            }
        }

        foreach ($banques as $support) {
            $moyens[] = $this->moyen($support, ModePaiement::VIREMENT, "Virement bancaire — {$support->libelle}", true);
        }
        foreach ($banques as $support) {
            $moyens[] = $this->moyen($support, ModePaiement::CHEQUE, "Chèque — {$support->libelle}", false);
        }

        return $moyens;
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
        ];
    }
}
