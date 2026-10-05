<?php

namespace App\Support\Ventes;

use App\Enums\ModeConfirmationAnnulationExceptionnelle;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CompteTresorerie;
use App\Models\Parametre;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentResolver;
use App\Services\Tresorerie\MoyensEncaissementResolver;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use App\Services\Ventes\PrecommandeService;

/**
 * Données de la fiche d'une précommande (ADR 0019) : montants, quantités, actions réellement
 * autorisées (permission ET état — Gate::before du super admin oblige à vérifier l'état ici) et
 * moyens de remboursement de l'agence de la précommande, avec leur solde (ADR 0009). Indicateurs
 * d'affichage : chaque action est recontrôlée côté serveur.
 */
final class PrecommandeEcran
{
    public function __construct(
        private readonly MoyensEncaissementResolver $moyens,
        private readonly CaisseAgentResolver $caisses,
        private readonly TresorerieDisponibiliteService $disponibilite,
    ) {}

    /** @return array<string, mixed>|null */
    public function pour(CommandeVente $commande, User $user): ?array
    {
        if (! $commande->est_precommande) {
            return null;
        }

        $montants = PrecommandeService::montants($commande);
        $annulable = PrecommandeService::estAnnulable($commande);
        $renforcee = PrecommandeService::annulationRenforcee($commande);
        $codeRequis = Parametre::getModeConfirmationAnnulationExceptionnelle($commande->organization_id) === ModeConfirmationAnnulationExceptionnelle::EMAIL_CODE;
        $peutRembourser = $user->can('rembourser', $commande);

        return [
            'livraison' => $commande->vehicule_id !== null,
            'montants' => $montants,
            'lignes' => $commande->lignes->map(fn ($l) => [
                'id' => $l->id,
                'libelle' => $l->libelle_snapshot ?? $l->variante?->produit?->nom,
                'quantite_demandee' => (int) $l->quantite_demandee,
                'quantite_preparee' => $l->quantite_preparee,
            ])->values(),
            'can_lancer_preparation' => $commande->statut === StatutCommandeVente::RESERVEE && $user->can('preparer', $commande),
            'can_valider_preparation' => $commande->statut === StatutCommandeVente::A_PREPARER && $user->can('preparer', $commande),
            'can_valider_retrait' => $commande->statut === StatutCommandeVente::PREPAREE && $commande->vehicule_id === null && $user->can('validerRetrait', $commande),
            'can_confirmer_livraison' => $commande->isLivraisonEnCours() && ! $commande->requiertReceptionExplicite() && $user->can('confirmerLivraison', $commande),
            'can_rembourser' => $montants['trop_percu'] > 0 && $peutRembourser,
            'can_annuler' => $annulable && $user->can('annulerPrecommande', $commande),
            'annulation_renforcee' => $renforcee,
            'annulation_code_requis' => $renforcee && $codeRequis,
            'decaissement' => ($peutRembourser || $annulable) ? $this->decaissement($commande, $user) : null,
        ];
    }

    /**
     * Moyens d'où peut sortir un remboursement, dans l'agence de la précommande : supports actifs
     * (avec solde) et caisse dédiée du payeur (avec solde) — mêmes sources que le paiement d'une fiche.
     *
     * @return array{moyens: list<array<string, mixed>>, especes_disponibles: bool, solde_especes: ?float}
     */
    private function decaissement(CommandeVente $commande, User $user): array
    {
        $moyens = $this->moyens->pourSite($commande->organization_id, $commande->site_id);
        $supports = CompteTresorerie::whereIn('id', array_column($moyens, 'compte_tresorerie_id'))->get()->keyBy('id');
        $caisse = $this->caisses->caisseActive($commande->organization_id, (string) $user->id, $commande->site_id);

        return [
            'moyens' => array_map(function (array $moyen) use ($supports) {
                $support = $supports->get($moyen['compte_tresorerie_id']);
                $moyen['solde_disponible'] = $support ? $this->disponibilite->soldePourSupport($support, now()) : null;

                return $moyen;
            }, $moyens),
            'especes_disponibles' => $caisse !== null,
            'solde_especes' => $caisse ? $this->disponibilite->soldePourSupport($caisse, now()) : null,
        ];
    }
}
