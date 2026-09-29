<?php

namespace App\Services\Tresorerie;

use App\Models\FactureVente;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Agence qui encaisse réellement un paiement de vente (ADR 0012) — source unique de la liste
 * proposée à l'écran ET du contrôle serveur de Ventes\StoreEncaissementVenteController.
 *
 * Règles (décisions du 29/09/2026) :
 *  - l'agence d'encaissement est toujours l'une des agences auxquelles l'utilisateur est affecté
 *    (`user_sites`) — jamais un choix libre, aucun passe-droit de rôle ;
 *  - encaisser dans une autre agence que celle de la commande exige la permission
 *    `factures.encaisser_autre_agence` ;
 *  - l'agence de la commande est proposée par défaut quand l'utilisateur y est affecté.
 *
 * Sans agence demandée, l'encaissement reste reçu par l'agence de la facture, quelle que soit
 * l'affectation de l'utilisateur : c'est le comportement de tous les écrans existants, conservé
 * tel quel pour les encaissements dans la même agence.
 */
class AgenceEncaissementResolver
{
    public const PERMISSION = 'factures.encaisser_autre_agence';

    public const MESSAGE_SANS_PERMISSION = "Vous n'êtes pas autorisé à encaisser une commande d'une autre agence.";

    public const MESSAGE_NON_AFFECTE = "Vous n'êtes pas affecté à cette agence : vous ne pouvez pas y encaisser.";

    /**
     * Agences proposées à l'utilisateur pour encaisser cette facture, agence de la commande en tête
     * quand elle y figure. Sans aucune agence éligible (ex. administrateur rattaché à aucune agence),
     * seule l'agence de la facture est proposée — comportement historique.
     *
     * @return Collection<int, Site>
     */
    public function options(User $user, FactureVente $facture): Collection
    {
        $peutAutreAgence = $user->can(self::PERMISSION);

        $sites = $this->sitesAffectes($user, $facture->organization_id)
            ->filter(fn (Site $site) => $site->id === $facture->site_id || $peutAutreAgence)
            ->sortBy(fn (Site $site) => [$site->id === $facture->site_id ? 0 : 1, $site->nom])
            ->values();

        if ($sites->isEmpty() && $facture->site_id) {
            $siteFacture = Site::where('organization_id', $facture->organization_id)->find($facture->site_id);

            return $siteFacture ? collect([$siteFacture]) : collect();
        }

        return $sites;
    }

    /** Agence présélectionnée : celle de la commande si proposée, sinon l'agence par défaut de l'utilisateur. */
    public function parDefaut(User $user, FactureVente $facture, ?Collection $options = null): ?string
    {
        $options ??= $this->options($user, $facture);
        $ids = $options->pluck('id');

        if ($facture->site_id && $ids->contains($facture->site_id)) {
            return $facture->site_id;
        }

        $parDefaut = $user->sites()->wherePivot('is_default', true)->value('sites.id');

        return $parDefaut && $ids->contains($parDefaut) ? $parDefaut : $ids->first();
    }

    /**
     * Agence d'encaissement retenue pour une demande. Rien de demandé, ou l'agence de la facture :
     * l'agence de la facture. Une autre agence : permission dédiée ET affectation de l'utilisateur.
     *
     * @throws ValidationException
     */
    public function resoudre(User $user, FactureVente $facture, ?string $siteDemande): ?string
    {
        if ($siteDemande === null || $siteDemande === '' || $siteDemande === $facture->site_id) {
            return $facture->site_id;
        }

        if (! $user->can(self::PERMISSION)) {
            throw ValidationException::withMessages(['site_encaissement_id' => self::MESSAGE_SANS_PERMISSION]);
        }

        if (! $this->sitesAffectes($user, $facture->organization_id)->contains('id', $siteDemande)) {
            throw ValidationException::withMessages(['site_encaissement_id' => self::MESSAGE_NON_AFFECTE]);
        }

        return $siteDemande;
    }

    /** @return Collection<int, Site> */
    private function sitesAffectes(User $user, string $organizationId): Collection
    {
        return $user->sites()
            ->where('sites.organization_id', $organizationId)
            ->get(['sites.id', 'sites.nom']);
    }
}
