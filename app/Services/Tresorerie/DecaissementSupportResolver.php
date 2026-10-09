<?php

namespace App\Services\Tresorerie;

use App\Enums\ModePaiement;
use App\Models\CompteTresorerie;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Décaissement réel depuis une AGENCE (ADR 0009) : d'où peut sortir l'argent, et depuis quel
 * support. Partagé par le paiement des fiches de commission (DecaissementFicheResolver) et le
 * paiement des factures fournisseurs (ADR 0022) — une seule règle, jamais une copie :
 * - moyens hors espèces = MoyensEncaissementResolver de l'agence ;
 * - espèces = caisse dédiée active DU PAYEUR sur cette agence (CaisseAgentResolver).
 */
class DecaissementSupportResolver
{
    public const MESSAGE_SANS_CAISSE = "Vous ne disposez pas d'une caisse active sur ce site : impossible de payer en espèces. Contactez votre responsable pour qu'il vous en crée une.";

    public const MESSAGE_MOYEN_INDISPONIBLE = "Ce moyen de paiement n'est pas disponible dans l'agence de cette fiche : aucun support de trésorerie actif ne peut effectuer ce paiement.";

    public function __construct(
        private readonly MoyensEncaissementResolver $moyens,
        private readonly CaisseAgentResolver $caisses,
        private readonly TresorerieDisponibiliteService $disponibilite,
    ) {}

    /**
     * Données du dialogue de paiement (PaymentCard, sens décaissement) pour plusieurs agences :
     * moyens hors espèces (+ solde) et caisse du payeur (+ solde). Une requête par liste.
     *
     * @param  iterable<string>  $siteIds
     * @return array<string, array{moyens: list<array<string, mixed>>, especes_disponibles: bool, solde_especes: ?float}> par site id
     */
    public function optionsPourSites(string $organizationId, iterable $siteIds, User $payeur): array
    {
        $siteIds = collect($siteIds)->filter()->unique()->values();
        if ($siteIds->isEmpty()) {
            return [];
        }

        $moyensParSite = collect($this->moyens->parSite($organizationId, $siteIds))->map(
            fn (array $moyens) => $this->avecSoldes($moyens)
        );

        return $siteIds->mapWithKeys(function (string $siteId) use ($organizationId, $payeur, $moyensParSite) {
            $caisse = $this->caisses->caisseActive($organizationId, $payeur->id, $siteId);

            return [$siteId => [
                'moyens' => $moyensParSite->get($siteId, []),
                'especes_disponibles' => $caisse !== null,
                'solde_especes' => $caisse ? $this->disponibilite->soldePourSupport($caisse, now()) : null,
            ]];
        })->all();
    }

    /**
     * Support débité, ou exception de validation — contrôle serveur, indépendant de ce que
     * l'écran a proposé.
     *
     * @throws ValidationException
     */
    public function supportPour(string $organizationId, string $siteId, User $payeur, string $modePaiement, ?string $compteTresorerieId): CompteTresorerie
    {
        if ($modePaiement === ModePaiement::ESPECES->value) {
            $caisse = $this->caisses->caisseActive($organizationId, $payeur->id, $siteId);
            if (! $caisse) {
                throw ValidationException::withMessages(['mode_paiement' => self::MESSAGE_SANS_CAISSE]);
            }

            return $caisse;
        }

        $support = $this->moyens->supportPour($organizationId, $siteId, $compteTresorerieId, $modePaiement);
        if (! $support) {
            throw ValidationException::withMessages(['compte_tresorerie_id' => self::MESSAGE_MOYEN_INDISPONIBLE]);
        }

        return $support;
    }

    /** La référence de transaction suit la même règle que l'encaissement (reference_requise du moyen). */
    public function referenceRequise(string $organizationId, ?string $siteId, string $modePaiement, ?string $compteTresorerieId): bool
    {
        if ($modePaiement === ModePaiement::ESPECES->value) {
            return false;
        }

        return collect($this->moyens->pourSite($organizationId, $siteId))
            ->contains(fn (array $m) => $m['compte_tresorerie_id'] === $compteTresorerieId
                && $m['mode_paiement'] === $modePaiement
                && $m['reference_requise']);
    }

    /**
     * @param  list<array<string, mixed>>  $moyens
     * @return list<array<string, mixed>>
     */
    private function avecSoldes(array $moyens): array
    {
        $supports = CompteTresorerie::whereIn('id', array_column($moyens, 'compte_tresorerie_id'))->get()->keyBy('id');
        $date = Carbon::now();

        return array_map(function (array $moyen) use ($supports, $date) {
            $support = $supports->get($moyen['compte_tresorerie_id']);
            $moyen['solde_disponible'] = $support ? $this->disponibilite->soldePourSupport($support, $date) : null;

            return $moyen;
        }, $moyens);
    }
}
