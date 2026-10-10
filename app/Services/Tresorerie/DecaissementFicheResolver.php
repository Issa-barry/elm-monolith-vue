<?php

namespace App\Services\Tresorerie;

use App\Models\CompteTresorerie;
use App\Models\PaiementFiche;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Paiement d'une fiche = décaissement réel (ADR 0009) : d'où peut sortir l'argent, et depuis quel
 * support. Délègue à DecaissementSupportResolver (règle commune à tous les décaissements depuis une
 * agence, partagée avec le paiement des factures fournisseurs) et n'ajoute que ce qui est propre à
 * la fiche : son agence de trésorerie.
 *
 * Agence de trésorerie : celle de la fiche ; une fiche sans agence (consultant) est payée depuis
 * le site central de trésorerie de l'organisation (ADR 0017) — jamais depuis l'agence de l'utilisateur, jamais un choix
 * manuel. Sans site central de trésorerie configuré, le paiement est impossible.
 */
class DecaissementFicheResolver
{
    public const MESSAGE_SANS_SITE_CENTRAL = "Aucun site n'est désigné comme trésorerie principale pour cette organisation. Le paiement ne peut pas être effectué.";

    public const MESSAGE_SANS_CAISSE = DecaissementSupportResolver::MESSAGE_SANS_CAISSE;

    public const MESSAGE_MOYEN_INDISPONIBLE = DecaissementSupportResolver::MESSAGE_MOYEN_INDISPONIBLE;

    public function __construct(
        private readonly DecaissementSupportResolver $supports,
        private readonly SiteCentralTresorerieResolver $siteCentral,
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

        $sites = $fiches->mapWithKeys(fn (PaiementFiche $f) => [$f->id => $this->siteTresorerie($f)]);
        $options = $this->supports->optionsPourSites($payeur->organization_id, $sites->filter()->unique()->values(), $payeur);

        return $sites->map(fn (?string $siteId) => [
            'site_id' => $siteId,
            'moyens' => $siteId ? ($options[$siteId]['moyens'] ?? []) : [],
            'especes_disponibles' => $siteId ? ($options[$siteId]['especes_disponibles'] ?? false) : false,
            'solde_especes' => $siteId ? ($options[$siteId]['solde_especes'] ?? null) : null,
            'message' => $siteId ? null : self::MESSAGE_SANS_SITE_CENTRAL,
        ])->all();
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

        return $this->supports->supportPour($fiche->organization_id, $siteId, $payeur, $modePaiement, $compteTresorerieId);
    }

    /** La référence de transaction suit la même règle que l'encaissement (reference_requise du moyen). */
    public function referenceRequise(PaiementFiche $fiche, string $modePaiement, ?string $compteTresorerieId): bool
    {
        return $this->supports->referenceRequise($fiche->organization_id, $this->siteTresorerie($fiche), $modePaiement, $compteTresorerieId);
    }
}
