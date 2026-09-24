<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\MotifRetourCommande;
use App\Enums\NatureOperation;
use App\Enums\StatutCommission;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CommandeVente;
use App\Services\AnnulationExceptionnelleService;
use App\Services\Tresorerie\CaisseAgentResolver;
use App\Services\VehiculeCapaciteService;
use App\Support\Ventes\CommandeVenteCommissionStatus;
use Inertia\Inertia;
use Inertia\Response;

class ShowCommandeVenteController extends Controller
{
    private const DATE_DISPLAY_FORMAT = 'd/m/Y';

    public function __construct(private readonly VehiculeCapaciteService $vehiculeCapaciteService) {}

    public function __invoke(CommandeVente $vente): Response
    {
        $this->authorize('view', $vente);

        $commande = $vente;
        $commande->load(['vehicule.proprietaire', 'vehicule.typeVehicule', 'vehicule.equipe.livreurs', 'client', 'site', 'lignes.variante.produit', 'createdBy', 'facture.encaissements.creator', 'commissions', 'activites.user', 'retours.createdBy', 'retours.lignes']);

        $commande->cloturerSiComplete();
        $commande->refresh();

        $user = auth()->user();
        $facture = $commande->facture;

        $vehicule = $commande->vehicule;
        $equipe = $vehicule?->equipe;
        $chauffeur = $equipe?->livreurs->first(fn ($l) => ($l->pivot->role ?? null) === 'chauffeur');
        $convoyeurs = $equipe ? $equipe->livreurs->filter(fn ($l) => ($l->pivot->role ?? null) !== 'chauffeur') : collect();

        $lignes = $commande->lignes->map(fn ($l) => [
            'id' => $l->id,
            'variante_id' => $l->variante_id,
            'produit_nom' => $l->libelle_snapshot ?? $l->variante?->produit?->nom,
            'quantite_demandee' => $l->quantite_demandee,
            'quantite_chargee' => $l->quantite_chargee,
            'quantite_livree' => $l->quantite_livree,
            'quantite_retournee' => (int) $l->quantite_retournee,
            'quantite_retournable' => $l->quantite_retournable,
            'type_ecart' => $l->type_ecart?->value,
            'type_ecart_label' => $l->type_ecart?->label(),
            'commentaire_ecart' => $l->commentaire_ecart,
            'type_ecart_reception' => $l->type_ecart_reception?->value,
            'type_ecart_reception_label' => $l->type_ecart_reception?->label(),
            'commentaire_ecart_reception' => $l->commentaire_ecart_reception,
            'ecart_chargement' => $l->ecart_chargement,
            'ecart_livraison' => $l->ecart_livraison,
            'prix_usine_snapshot' => (float) $l->prix_usine_snapshot,
            'prix_vente_snapshot' => (float) $l->prix_vente_snapshot,
            'prix_origine_snapshot' => $l->prix_origine_snapshot?->value,
            'total_ligne' => (float) $l->total_ligne,
        ]);

        $historiques = AuditLog::where('organization_id', $commande->organization_id)
            ->where('auditable_type', CommandeVente::class)
            ->where('auditable_id', $commande->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event_code' => $log->event_code,
                'event_label' => $log->event_label,
                'actor_name' => $log->actor_name_snapshot ?? 'Système',
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at->format('d/m/Y H:i'),
            ]);

        $retours = $commande->retours->map(fn ($r) => [
            'id' => $r->id,
            'created_at' => $r->created_at->format('d/m/Y H:i'),
            'created_by' => $r->createdBy?->name ?? 'Système',
            'motif' => $r->motif->value,
            'motif_label' => $r->motif->label(),
            'commentaire' => $r->commentaire,
            'quantite_totale' => $r->quantite_totale,
            'montant_retourne' => (float) $r->montant_retourne,
            'retour_total' => $r->retour_total,
            'lignes' => $r->lignes->map(fn ($rl) => [
                'produit_nom' => $rl->libelle_snapshot,
                'quantite' => $rl->quantite_retournee,
                'montant' => (float) $rl->montant_retourne,
            ])->values(),
        ])->values();

        $activites = $commande->activites->map(fn ($a) => [
            'id' => $a->id,
            'action' => $a->action,
            'action_label' => $a->action_label,
            'user_name' => $a->user?->name ?? 'Système',
            'created_at' => $a->created_at->format('d/m/Y H:i'),
            'details' => $a->details,
        ]);

        // Backend commun, expérience UI séparée : même construction de données pour les deux
        // natures, seul le composant Vue rendu diffère (présentation uniquement, cf. distributions.show).
        $component = $commande->nature_operation === NatureOperation::DISTRIBUTION_CLIENT
            ? 'Distributions/Show'
            : 'Ventes/Show';

        return Inertia::render($component, [
            'historiques' => $historiques,
            'activites' => $activites,
            'retours' => $retours,
            'motifs_retour' => MotifRetourCommande::options(),
            'commande' => [
                'id' => $commande->id,
                'reference' => $commande->reference,
                'statut' => $commande->statut?->value,
                'statut_label' => $commande->statut_label,
                'statut_affichage' => $commande->statutAffichage(),
                'statut_color' => $commande->statut?->color(),
                'total_commande' => (float) $commande->total_commande,
                'mode_tarification_snapshot' => $commande->mode_tarification_snapshot?->value,
                'mode_tarification_label' => $commande->mode_tarification_snapshot?->label(),
                'commission_eligible_snapshot' => (bool) $commande->commission_eligible_snapshot,
                'nature_operation' => $commande->nature_operation?->value,
                'nature_operation_label' => $commande->nature_operation?->label(),
                'mode_remise_grossiste' => $commande->mode_remise_grossiste?->value,
                'mode_remise_grossiste_label' => $commande->mode_remise_grossiste?->label(),
                'vehicule_nom' => $commande->vehicule?->nom_vehicule,
                'vehicule_detail' => $vehicule ? [
                    'nom' => $vehicule->nom_vehicule,
                    'immatriculation' => $vehicule->immatriculation,
                    'type' => $vehicule->typeVehicule?->nom,
                    'capacites' => $this->vehiculeCapaciteService->capacitesParCategorieAvecNoms($vehicule),
                    'proprietaire_nom' => $vehicule->proprietaire
                        ? trim($vehicule->proprietaire->prenom.' '.$vehicule->proprietaire->nom)
                        : null,
                    'proprietaire_telephone' => $vehicule->proprietaire?->telephone,
                    'proprietaire_code_phone_pays' => $vehicule->proprietaire?->code_phone_pays,
                ] : null,
                'livreur_nom' => $chauffeur?->libelleAffichage(),
                'livreur_telephone' => $chauffeur?->telephone,
                'equipe_detail' => $equipe ? [
                    'nom' => $vehicule->nom_vehicule,
                    'taux_commission_proprietaire' => $equipe->taux_commission_proprietaire !== null
                        ? (float) $equipe->taux_commission_proprietaire
                        : null,
                    'chauffeur' => $chauffeur ? [
                        'nom' => $chauffeur->libelleAffichage(),
                        'telephone' => $chauffeur->telephone,
                    ] : null,
                    'convoyeurs' => $convoyeurs->map(fn ($l) => [
                        'nom' => $l->libelleAffichage(),
                        'telephone' => $l->telephone,
                    ])->values(),
                ] : null,
                'client_nom' => $commande->client?->nom_complet,
                'client_detail' => $commande->client ? [
                    'nom' => $commande->client->nom_complet,
                    'telephone' => $commande->client->telephone,
                    'code_phone_pays' => $commande->client->code_phone_pays,
                    'ville' => $commande->client->ville,
                    'adresse' => $commande->client->adresse,
                    'cashback_eligible' => (bool) $commande->client->cashback_eligible,
                ] : null,
                'site_nom' => $commande->site?->nom,
                'motif_annulation' => $commande->motif_annulation,
                'annulee_at' => $commande->annulee_at?->toISOString(),
                'a_charger_at' => $commande->a_charger_at?->format(self::DATE_DISPLAY_FORMAT),
                'chargement_demarre_at' => $commande->chargement_demarre_at?->format(self::DATE_DISPLAY_FORMAT),
                'chargement_valide_at' => $commande->chargement_valide_at?->format(self::DATE_DISPLAY_FORMAT),
                'livree_at' => $commande->livree_at?->format(self::DATE_DISPLAY_FORMAT),
                'reception_validee_at' => $commande->reception_validee_at?->format(self::DATE_DISPLAY_FORMAT),
                'closed_at' => $commande->closed_at?->format(self::DATE_DISPLAY_FORMAT),
                'is_brouillon' => $commande->isBrouillon(),
                'is_a_charger' => $commande->isACharger(),
                'is_chargement_en_cours' => $commande->isChargementEnCours(),
                'is_livraison_en_cours' => $commande->isLivraisonEnCours(),
                'is_livree' => $commande->isLivree(),
                'is_facturation' => $commande->isFacturation(),
                'is_cloturee' => $commande->isCloturee(),
                'is_annulee' => $commande->isAnnulee(),
                'is_retournee' => $commande->isRetournee(),
                'is_annulee_erreur_saisie' => $commande->isAnnuleeErreurSaisie(),
                'annulation_exceptionnelle' => $commande->isAnnuleeErreurSaisie() && $commande->annulationExceptionnelle
                    ? [
                        'par' => $commande->annulationExceptionnelle->user?->name,
                        'le' => $commande->annulationExceptionnelle->confirmee_at?->format(self::DATE_DISPLAY_FORMAT),
                        'motif' => $commande->annulationExceptionnelle->motif,
                        'montant_encaisse' => (float) $commande->annulationExceptionnelle->montant_encaisse,
                        'code_envoye_a' => $commande->annulationExceptionnelle->code_envoye_a,
                    ]
                    : null,
                // Même garde-fou que can_valider_reception ci-dessous : Gate::before bypasse
                // modifierContenu() (donc isEditable()) pour super_admin, ce flag doit rester
                // explicite pour ne pas afficher "Modifier" passé le brouillon.
                'can_modifier' => $commande->isEditable() && $user->can('modifierContenu', $commande),
                'can_confirmer' => $commande->isBrouillon() && $user->can('confirmer', $commande),
                'can_demarrer_chargement' => $commande->isACharger() && $user->can('demarrerChargement', $commande),
                'can_valider_chargement' => $commande->isChargementEnCours() && $user->can('validerChargement', $commande),
                // Condition métier explicite en plus de $user->can() : le Gate::before
                // (AuthServiceProvider) bypasse toutes les Policies pour super_admin, donc
                // $user->can('validerReception', ...) seul ignorerait requiertReceptionExplicite()
                // et afficherait ce bouton à un super_admin même sur une vente standard.
                'can_valider_reception' => $commande->isLivraisonEnCours()
                    && $commande->requiertReceptionExplicite()
                    && $user->can('validerReception', $commande),
                // Condition métier explicite (isRetournable()) en plus de $user->can(), pour la même
                // raison que can_valider_reception : le Gate::before de super_admin bypasse la
                // Policy — sans elle ce bouton s'afficherait à un super_admin hors livraison.
                'can_enregistrer_retour' => $commande->isRetournable()
                    && $user->can('enregistrerRetour', $commande),
                'can_annuler' => $commande->statut->isAnnulable()
                    && (! $facture || (float) $facture->montant_encaisse === 0.0)
                    && $user->can('annuler', $commande),
                // Condition d'état explicite (raisonStatutNonEligible()) en plus de la permission, même
                // raison que can_enregistrer_retour : le Gate::before du super admin bypasse la Policy.
                // Les garde-fous fins (commission traitée, caisse versée…) sont affichés dans le
                // récapitulatif du dialogue, pas ici.
                'can_annuler_exceptionnel' => AnnulationExceptionnelleService::raisonStatutNonEligible($commande) === null
                    && $user->can('annulerExceptionnel', $commande),
                // Permission dédiée `factures.encaisser` depuis le 13/09/2026, indépendante de
                // ventes.update — même séparation que demarrerChargement/validerChargement/
                // validerReception dans CommandeVentePolicy (cf. docs/grossiste.md). L'org est
                // déjà garantie par le authorize('view', $vente) plus haut dans ce contrôleur.
                'can_encaisser' => $facture && ! $facture->isAnnulee()
                    && (float) $facture->montant_restant > 0
                    && $commande->isEncaissable()
                    && $user->can('factures.encaisser'),
                // Espèces : possibles seulement avec une caisse dédiée active de l'utilisateur sur le
                // site de la facture. Indicateur d'affichage (PaymentCard) — la garantie réelle reste
                // CaisseAgentResolver::garantirCaissePourEspeces(), côté serveur à l'enregistrement.
                'peut_encaisser_especes' => (bool) ($facture?->site_id
                    && app(CaisseAgentResolver::class)->caisseActive($facture->organization_id, (string) $user->id, $facture->site_id)),
                'created_at' => $commande->created_at?->format(self::DATE_DISPLAY_FORMAT),
                'created_by' => $commande->createdBy?->name,
                'lignes' => $lignes,
            ],
            'facture' => $facture ? [
                'id' => $facture->id,
                'reference' => $facture->reference,
                'montant_net' => (float) $facture->montant_net,
                'montant_encaisse' => (float) $facture->montant_encaisse,
                'montant_restant' => (float) $facture->montant_restant,
                'statut' => $facture->statut_facture?->value,
                'statut_label' => $facture->statut_label,
                'encaissements' => $facture->encaissements->map(fn ($e) => [
                    'id' => $e->id,
                    'montant' => (float) $e->montant,
                    'date_encaissement' => $e->date_encaissement?->format(self::DATE_DISPLAY_FORMAT),
                    'heure' => $e->created_at?->format('H:i'),
                    'mode_paiement' => $e->mode_paiement?->value,
                    'mode_paiement_label' => $e->mode_paiement?->label(),
                    'operateur_mobile_money_label' => $e->operateur_mobile_money?->label(),
                    'reference_paiement' => $e->reference_paiement,
                    'note' => $e->note,
                    'created_by' => $e->creator?->name,
                ])->values(),
            ] : null,
            'commission_statut' => $this->getCommissionStatutGlobal($commande),
            'commission_generation_statut' => CommandeVenteCommissionStatus::getCommissionGenerationStatut($commande),
        ]);
    }

    private function getCommissionStatutGlobal(CommandeVente $commande): ?array
    {
        $commissions = $commande->commissions;
        if ($commissions->isEmpty()) {
            return null;
        }

        if ($commissions->every(fn ($c) => $c->statut === StatutCommission::CREEE)) {
            return ['value' => 'creee', 'label' => 'Créée'];
        }
        if ($commissions->every(fn ($c) => $c->statut === StatutCommission::PAYE)) {
            return ['value' => 'paye', 'label' => 'Payée'];
        }
        if ($commissions->some(fn ($c) => $c->statut === StatutCommission::PAYE || $c->statut === StatutCommission::PARTIEL)) {
            return ['value' => 'partiel', 'label' => 'Partiellement payée'];
        }

        return ['value' => 'impaye', 'label' => 'Impayée'];
    }
}
