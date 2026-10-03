<?php

namespace App\Services\Tresorerie;

use App\Enums\ModePaiement;
use App\Models\CompteTresorerie;
use App\Models\PaiementFiche;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Paiement d'une fiche = décaissement réel (ADR 0009) : d'où peut sortir l'argent, et depuis quel
 * support. Rejoue exactement les listes de l'encaissement, jamais une règle parallèle :
 * - moyens hors espèces = MoyensEncaissementResolver sur l'agence de trésorerie de la fiche ;
 * - espèces = caisse dédiée active DU PAYEUR sur cette agence (CaisseAgentResolver).
 *
 * Agence de trésorerie : celle de la fiche ; une fiche sans agence (consultant) est payée depuis
 * le site central de trésorerie de l'organisation (ADR 0017) — jamais depuis l'agence de l'utilisateur, jamais un choix
 * manuel. Sans site central de trésorerie configuré, le paiement est impossible.
 */
class DecaissementFicheResolver
{
    public const MESSAGE_SANS_SITE_CENTRAL = "Aucun site n'est désigné comme trésorerie principale pour cette organisation. Le paiement ne peut pas être effectué.";

    public const MESSAGE_SANS_CAISSE = "Vous ne disposez pas d'une caisse active sur ce site : impossible de payer en espèces. Contactez votre responsable pour qu'il vous en crée une.";

    public const MESSAGE_MOYEN_INDISPONIBLE = "Ce moyen de paiement n'est pas disponible dans l'agence de cette fiche : aucun support de trésorerie actif ne peut effectuer ce paiement.";

    public function __construct(
        private readonly MoyensEncaissementResolver $moyens,
        private readonly CaisseAgentResolver $caisses,
        private readonly SiteCentralTresorerieResolver $siteCentral,
        private readonly TresorerieDisponibiliteService $disponibilite,
    ) {}

    /** Agence d'où sort l'argent, ou null si la fiche n'a pas d'agence et qu'aucun site central de trésorerie n'existe. */
    public function siteTresorerie(PaiementFiche $fiche): ?string
    {
        return $fiche->site_id ?? $this->siteCentral->centralOuNull($fiche->organization_id)?->id;
    }

    /**
     * Données du dialogue de paiement pour plusieurs fiches : moyens hors espèces (+ solde) par
     * agence, caisse du payeur (+ solde) par agence. Une requête par liste, jamais par ligne.
     *
     * @param  Collection<int, PaiementFiche>  $fiches
     * @return array<string, array{site_id: ?string, moyens: list<array<string, mixed>>, especes_disponibles: bool, solde_especes: ?float, message: ?string}> par fiche id
     */
    public function optionsPourFiches(Collection $fiches, User $payeur): array
    {
        if ($fiches->isEmpty()) {
            return [];
        }

        $orgId = $payeur->organization_id;
        $sites = $fiches->mapWithKeys(fn (PaiementFiche $f) => [$f->id => $this->siteTresorerie($f)]);
        $siteIds = $sites->filter()->unique()->values();

        $moyensParSite = collect($this->moyens->parSite($orgId, $siteIds))->map(
            fn (array $moyens) => $this->avecSoldes($moyens)
        );
        $caisseParSite = $siteIds->mapWithKeys(fn (string $siteId) => [
            $siteId => $this->caisses->caisseActive($orgId, $payeur->id, $siteId),
        ]);

        return $sites->map(function (?string $siteId) use ($moyensParSite, $caisseParSite) {
            $caisse = $siteId ? $caisseParSite->get($siteId) : null;

            return [
                'site_id' => $siteId,
                'moyens' => $siteId ? $moyensParSite->get($siteId, []) : [],
                'especes_disponibles' => $caisse !== null,
                'solde_especes' => $caisse ? $this->disponibilite->soldePourSupport($caisse, now()) : null,
                'message' => $siteId ? null : self::MESSAGE_SANS_SITE_CENTRAL,
            ];
        })->all();
    }

    /**
     * Support débité pour ce paiement, ou exception de validation — contrôle serveur, indépendant
     * de ce que l'écran a proposé.
     *
     * @throws ValidationException
     */
    public function supportPour(PaiementFiche $fiche, User $payeur, string $modePaiement, ?string $compteTresorerieId): CompteTresorerie
    {
        $siteId = $this->siteTresorerie($fiche);
        if (! $siteId) {
            throw ValidationException::withMessages(['compte_tresorerie_id' => self::MESSAGE_SANS_SITE_CENTRAL]);
        }

        if ($modePaiement === ModePaiement::ESPECES->value) {
            $caisse = $this->caisses->caisseActive($fiche->organization_id, $payeur->id, $siteId);
            if (! $caisse) {
                throw ValidationException::withMessages(['mode_paiement' => self::MESSAGE_SANS_CAISSE]);
            }

            return $caisse;
        }

        $support = $this->moyens->supportPour($fiche->organization_id, $siteId, $compteTresorerieId, $modePaiement);
        if (! $support) {
            throw ValidationException::withMessages(['compte_tresorerie_id' => self::MESSAGE_MOYEN_INDISPONIBLE]);
        }

        return $support;
    }

    /** La référence de transaction suit la même règle que l'encaissement (reference_requise du moyen). */
    public function referenceRequise(PaiementFiche $fiche, string $modePaiement, ?string $compteTresorerieId): bool
    {
        if ($modePaiement === ModePaiement::ESPECES->value) {
            return false;
        }

        return collect($this->moyens->pourSite($fiche->organization_id, $this->siteTresorerie($fiche)))
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
