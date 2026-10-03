<?php

namespace App\Services\Tresorerie;

use App\Models\FactureVente;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Agence qui encaisse réellement un paiement de vente (ADR 0012) — source unique de la liste
 * proposée par TOUS les écrans d'encaissement ET du contrôle serveur de
 * Ventes\StoreEncaissementVenteController.
 *
 * Règle (décisions du 29/09/2026) : **l'agence d'encaissement est l'agence de l'utilisateur qui
 * encaisse** (`user_sites`) — jamais l'agence de la facture par défaut, aucun passe-droit de rôle :
 *  - affecté à l'agence de la commande : elle est proposée, et présélectionnée ;
 *  - encaisser dans une autre de ses agences exige `factures.encaisser_autre_agence` ; sans elle,
 *    un utilisateur qui n'est pas affecté à l'agence de la commande ne peut pas l'encaisser ;
 *  - un utilisateur affecté à aucune agence (administrateur compris) ne peut pas encaisser ;
 *  - plusieurs agences possibles : agence de la commande si elle en fait partie, sinon l'agence par
 *    défaut de l'utilisateur, modifiable dans la fenêtre de paiement.
 *
 * Les espèces vont ensuite dans la caisse dédiée de l'agent DANS cette agence (CaisseAgentResolver) :
 * une caisse d'une autre agence n'est jamais utilisée.
 */
class AgenceEncaissementResolver
{
    public const PERMISSION = 'factures.encaisser_autre_agence';

    public const MESSAGE_AUCUNE_AGENCE = "Vous n'êtes affecté à aucune agence. Vous ne pouvez pas effectuer cet encaissement.";

    public const MESSAGE_SANS_PERMISSION = "Vous n'êtes pas affecté à l'agence de cette commande et vous n'êtes pas autorisé à encaisser une commande d'une autre agence.";

    public const MESSAGE_NON_AFFECTE = "Vous n'êtes pas affecté à cette agence : vous ne pouvez pas y encaisser.";

    public function __construct(
        private readonly MoyensEncaissementResolver $moyens,
        private readonly CaisseAgentResolver $caisses,
    ) {}

    /**
     * Agences où l'utilisateur peut encaisser cette facture, agence de la commande en tête quand elle
     * y figure. Vide : il ne peut pas l'encaisser (cf. raisonRefus()).
     *
     * @return Collection<int, Site>
     */
    public function options(User $user, FactureVente $facture): Collection
    {
        return $this->optionsParmi($this->sitesAffectes($user, $facture->organization_id), $user->can(self::PERMISSION), $facture->site_id);
    }

    /** Agence présélectionnée : celle de la commande si proposée, sinon l'agence par défaut de l'utilisateur. */
    public function parDefaut(User $user, FactureVente $facture, ?Collection $options = null): ?string
    {
        $options ??= $this->options($user, $facture);

        return $this->defautParmi($options, $facture->site_id, $this->siteParDefaut($user));
    }

    /** Pourquoi l'utilisateur ne peut encaisser cette facture nulle part (null s'il le peut). */
    public function raisonRefus(User $user, FactureVente $facture): ?string
    {
        $affectes = $this->sitesAffectes($user, $facture->organization_id);

        return $this->raisonParmi($affectes, $this->optionsParmi($affectes, $user->can(self::PERMISSION), $facture->site_id));
    }

    /**
     * Agence d'encaissement retenue pour une demande — toujours une agence de l'utilisateur. Sans
     * agence demandée : l'agence présélectionnée (jamais un repli sur l'agence de la facture).
     *
     * @throws ValidationException
     */
    public function resoudre(User $user, FactureVente $facture, ?string $siteDemande): string
    {
        $affectes = $this->sitesAffectes($user, $facture->organization_id);
        $options = $this->optionsParmi($affectes, $user->can(self::PERMISSION), $facture->site_id);

        if ($raison = $this->raisonParmi($affectes, $options)) {
            throw ValidationException::withMessages(['site_encaissement_id' => $raison]);
        }

        $site = ($siteDemande !== null && $siteDemande !== '')
            ? $siteDemande
            : $this->defautParmi($options, $facture->site_id, $this->siteParDefaut($user));

        if (! $affectes->contains('id', $site)) {
            throw ValidationException::withMessages(['site_encaissement_id' => self::MESSAGE_NON_AFFECTE]);
        }

        if (! $options->contains('id', $site)) {
            throw ValidationException::withMessages(['site_encaissement_id' => self::MESSAGE_SANS_PERMISSION]);
        }

        return $site;
    }

    /**
     * Données de la fenêtre de paiement pour chaque facture d'un écran (listes, fiche, recherche) :
     * agences proposées avec leurs moyens et la disponibilité des espèces, agence présélectionnée,
     * agence de la commande et, s'il ne peut encaisser nulle part, la raison. Requêtes groupées : une
     * seule résolution des moyens et des caisses pour tout l'écran.
     *
     * @param  iterable<FactureVente>  $factures
     * @return array<string, array{agences: list<array<string, mixed>>, agence_defaut: ?string, agence_commande: ?array{id: string, nom: string}, message: ?string}>
     */
    public function pourEcran(User $user, iterable $factures): array
    {
        $factures = collect($factures)->filter()->values();
        if ($factures->isEmpty()) {
            return [];
        }

        $organizationId = $factures->first()->organization_id;
        $affectes = $this->sitesAffectes($user, $organizationId);
        $peutAutreAgence = $user->can(self::PERMISSION);
        $defaut = $this->siteParDefaut($user);
        $moyensParSite = $this->moyens->parSite($organizationId, $affectes->pluck('id'), avecComptesCommuns: true);
        $caisses = $this->caisses->sitesAvecCaisseActive($organizationId, (string) $user->id);
        $nomsCommande = Site::where('organization_id', $organizationId)
            ->whereIn('id', $factures->pluck('site_id')->filter()->unique()->values())
            ->pluck('nom', 'id');

        return $factures->mapWithKeys(function (FactureVente $facture) use ($affectes, $peutAutreAgence, $defaut, $moyensParSite, $caisses, $nomsCommande) {
            $options = $this->optionsParmi($affectes, $peutAutreAgence, $facture->site_id);

            return [$facture->id => [
                'agences' => $options->map(fn (Site $site) => [
                    'site_id' => $site->id,
                    'nom' => $site->nom,
                    'moyens' => $moyensParSite[$site->id] ?? [],
                    'peut_encaisser_especes' => in_array($site->id, $caisses, true),
                ])->values()->all(),
                'agence_defaut' => $this->defautParmi($options, $facture->site_id, $defaut),
                'agence_commande' => $facture->site_id
                    ? ['id' => $facture->site_id, 'nom' => (string) ($nomsCommande[$facture->site_id] ?? '')]
                    : null,
                'message' => $this->raisonParmi($affectes, $options),
            ]];
        })->all();
    }

    // ── Règles pures (partagées par l'écran et le contrôle serveur) ──────────

    /**
     * @param  Collection<int, Site>  $affectes
     * @return Collection<int, Site>
     */
    private function optionsParmi(Collection $affectes, bool $peutAutreAgence, ?string $siteFacture): Collection
    {
        return $affectes
            ->filter(fn (Site $site) => $site->id === $siteFacture || $peutAutreAgence)
            ->sortBy(fn (Site $site) => ($site->id === $siteFacture ? '0' : '1').mb_strtolower((string) $site->nom))
            ->values();
    }

    /** @param  Collection<int, Site>  $options */
    private function defautParmi(Collection $options, ?string $siteFacture, ?string $siteParDefaut): ?string
    {
        $ids = $options->pluck('id');

        if ($siteFacture && $ids->contains($siteFacture)) {
            return $siteFacture;
        }

        return $siteParDefaut && $ids->contains($siteParDefaut) ? $siteParDefaut : $ids->first();
    }

    /**
     * @param  Collection<int, Site>  $affectes
     * @param  Collection<int, Site>  $options
     */
    private function raisonParmi(Collection $affectes, Collection $options): ?string
    {
        return match (true) {
            $affectes->isEmpty() => self::MESSAGE_AUCUNE_AGENCE,
            $options->isEmpty() => self::MESSAGE_SANS_PERMISSION,
            default => null,
        };
    }

    /** @return Collection<int, Site> */
    private function sitesAffectes(User $user, string $organizationId): Collection
    {
        return $user->sites()
            ->where('sites.organization_id', $organizationId)
            ->get(['sites.id', 'sites.nom']);
    }

    private function siteParDefaut(User $user): ?string
    {
        return $user->sites()->wherePivot('is_default', true)->value('sites.id');
    }
}
