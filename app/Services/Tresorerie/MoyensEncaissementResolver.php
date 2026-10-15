<?php

namespace App\Services\Tresorerie;

use App\Enums\ModePaiement;
use App\Enums\OperateurMobileMoney;
use App\Enums\TypeSupportTresorerie;
use App\Models\CompteTresorerie;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Moyens de paiement (hors espèces) réellement disponibles dans une agence — source unique des listes
 * affichées par PaymentCard ET des contrôles serveur (décision du 24/09/2026, cf. docs/encaissements.md).
 * Un moyen n'existe que s'il existe un support de trésorerie ACTIF pour le porter : aucun opérateur
 * n'est proposé « par défaut ». Chaque Mobile Money est un compte à part, porté par son propre support
 * (`operateur_mobile_money`) ; un support Mobile Money sans opérateur renseigné n'est jamais proposé
 * (on ne saurait pas quel wallet il représente). Un support Banque ouvre le virement et le chèque.
 *
 * Deux listes, à ne jamais confondre :
 *  - ENCAISSER (`*PourEncaisseur`, ADR 0027) : le Mobile Money ne vient que des comptes Mobile Money
 *    dédiés et actifs de l'utilisateur qui encaisse, dans l'agence d'encaissement — jamais d'un compte
 *    de l'agence, et sans repli sur lui ; virement et chèque, de la banque de l'agence ;
 *  - PAYER depuis l'agence (parSite(), pourSite(), supportPour() — DecaissementSupportResolver,
 *    remboursements de précommande) : les supports de l'AGENCE, Mobile Money compris.
 *
 * Les espèces ne font partie d'aucune des deux : elles suivent la caisse dédiée de l'auteur, selon une
 * règle propre à l'utilisateur (CaisseAgentResolver).
 */
class MoyensEncaissementResolver
{
    public const MESSAGE_SANS_COMPTE_MOBILE_MONEY = "Vous n'avez pas de compte Mobile Money actif pour cet opérateur dans cette agence : impossible d'encaisser en Mobile Money. Contactez votre responsable pour qu'il vous en crée un.";

    /**
     * Moyens de l'AGENCE, pour payer depuis l'agence (décaissements).
     *
     * @param  iterable<string|null>  $siteIds
     * @return array<string, list<array<string, mixed>>> moyens par site
     */
    public function parSite(string $organizationId, iterable $siteIds): array
    {
        $siteIds = $this->sitesDemandes($siteIds);
        if ($siteIds === []) {
            return [];
        }

        return $this->supportsAgence($organizationId, $siteIds, avecMobileMoney: true)
            ->groupBy('site_id')
            ->map(fn (Collection $supports) => $this->moyensPourSupports($supports))
            ->all();
    }

    /**
     * Moyens de l'AGENCE pour un site (décaissements).
     *
     * @return list<array<string, mixed>>
     */
    public function pourSite(string $organizationId, ?string $siteId): array
    {
        return $siteId ? ($this->parSite($organizationId, [$siteId])[$siteId] ?? []) : [];
    }

    /**
     * Support de l'AGENCE qui peut porter ce mode de paiement (décaissements), ou null — le contrôle
     * serveur rejoue exactement la liste proposée, jamais une règle parallèle.
     */
    public function supportPour(string $organizationId, ?string $siteId, ?string $compteTresorerieId, string $modePaiement): ?CompteTresorerie
    {
        if (! $siteId || ! $compteTresorerieId) {
            return null;
        }

        return $this->trouver($this->pourSite($organizationId, $siteId), $compteTresorerieId, $modePaiement);
    }

    /**
     * Moyens proposés à un utilisateur pour ENCAISSER dans ces agences (ADR 0027) : ses propres
     * comptes Mobile Money actifs, et les banques de l'agence (virement, chèque).
     *
     * @param  iterable<string|null>  $siteIds
     * @return array<string, list<array<string, mixed>>> moyens par site
     */
    public function parSitePourEncaisseur(User $encaisseur, string $organizationId, iterable $siteIds): array
    {
        $siteIds = $this->sitesDemandes($siteIds);
        if ($siteIds === []) {
            return [];
        }

        return $this->supportsAgence($organizationId, $siteIds, avecMobileMoney: false)
            ->concat($this->comptesMobileMoneyDe($encaisseur, $organizationId, $siteIds))
            ->groupBy('site_id')
            ->map(fn (Collection $supports) => $this->moyensPourSupports($supports))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function pourSitePourEncaisseur(User $encaisseur, string $organizationId, ?string $siteId): array
    {
        return $siteId ? ($this->parSitePourEncaisseur($encaisseur, $organizationId, [$siteId])[$siteId] ?? []) : [];
    }

    /**
     * Support qui reçoit un encaissement de cet utilisateur dans cette agence, ou null : pour le Mobile
     * Money, uniquement un de SES comptes actifs — un compte de l'agence n'est jamais accepté.
     */
    public function supportPourEncaisseur(User $encaisseur, string $organizationId, ?string $siteId, ?string $compteTresorerieId, string $modePaiement): ?CompteTresorerie
    {
        if (! $siteId || ! $compteTresorerieId) {
            return null;
        }

        return $this->trouver($this->pourSitePourEncaisseur($encaisseur, $organizationId, $siteId), $compteTresorerieId, $modePaiement);
    }

    /** @param  list<array<string, mixed>>  $moyens */
    private function trouver(array $moyens, string $compteTresorerieId, string $modePaiement): ?CompteTresorerie
    {
        $disponible = collect($moyens)->contains(
            fn (array $moyen) => $moyen['compte_tresorerie_id'] === $compteTresorerieId && $moyen['mode_paiement'] === $modePaiement
        );

        return $disponible ? CompteTresorerie::find($compteTresorerieId) : null;
    }

    /**
     * @param  iterable<string|null>  $siteIds
     * @return list<string>
     */
    private function sitesDemandes(iterable $siteIds): array
    {
        return collect($siteIds)->filter()->unique()->values()->all();
    }

    /**
     * Supports actifs de l'agence (jamais un support dédié à un agent) : banques, et comptes Mobile
     * Money d'opérateur connu si demandé.
     *
     * @param  list<string>  $siteIds
     * @return Collection<int, CompteTresorerie>
     */
    private function supportsAgence(string $organizationId, array $siteIds, bool $avecMobileMoney): Collection
    {
        return CompteTresorerie::forOrg($organizationId)
            ->agence()
            ->actifs()
            ->whereIn('site_id', $siteIds)
            ->where(fn ($q) => $q
                ->where('type', TypeSupportTresorerie::BANQUE->value)
                ->when($avecMobileMoney, fn ($q) => $q->orWhere(fn ($mm) => $mm
                    ->where('type', TypeSupportTresorerie::MOBILE_MONEY->value)
                    ->whereIn('operateur_mobile_money', $this->operateursAvecWallet()))))
            ->orderBy('libelle')
            ->get(['id', 'site_id', 'type', 'operateur_mobile_money', 'libelle', 'numero']);
    }

    /**
     * Comptes Mobile Money dédiés et actifs de cet utilisateur dans ces agences (un par opérateur).
     *
     * @param  list<string>  $siteIds
     * @return Collection<int, CompteTresorerie>
     */
    private function comptesMobileMoneyDe(User $encaisseur, string $organizationId, array $siteIds): Collection
    {
        return CompteTresorerie::forOrg($organizationId)
            ->mobileMoneyDedies()
            ->actifs()
            ->where('agent_id', $encaisseur->id)
            ->whereIn('site_id', $siteIds)
            ->whereIn('operateur_mobile_money', $this->operateursAvecWallet())
            ->orderBy('libelle')
            ->get(['id', 'site_id', 'type', 'operateur_mobile_money', 'libelle', 'numero']);
    }

    /** @return list<string> */
    private function operateursAvecWallet(): array
    {
        return array_map(fn (OperateurMobileMoney $o) => $o->value, OperateurMobileMoney::avecWallet());
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
            // Numéro du compte (numéro marchand, ou portefeuille de l'agent) : il le compare au reçu du client.
            'numero' => $support->numero,
        ];
    }
}
