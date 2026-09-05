<?php

namespace App\Services;

use App\Enums\DeclencheurCommissionLogistique;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\NatureOperation;
use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Parametre;
use App\Models\TransfertLogistique;
use App\Services\Commission\CommissionEnveloppeGenerator;

/**
 * Point d'entrée unique reliant les événements métier réels (chargement validé,
 * facture encaissée, réception effectuée) à la génération des commissions —
 * seule couche qui lit le paramètre organisation (Parametre::getDeclencheurXxx)
 * et décide si l'événement reçu correspond au déclencheur configuré.
 *
 * Le déclencheur ne choisit QUE le moment de naissance de la commission,
 * jamais son statut initial : dans tous les cas elle naît CREEE (cf.
 * CommissionEnveloppeGenerator) et ne devient IMPAYE(E) qu'à la validation de
 * la période de paiement qui la couvre (cf.
 * CommissionAdjustmentService::activerCommissionsCreees()).
 *
 * Ne recalcule jamais rien elle-même : délègue systématiquement à
 * CommissionEnveloppeGenerator, seule source de vérité du calcul (barèmes,
 * parts). Chaque méthode est idempotente par construction, via l'idempotence
 * déjà portée par ce générateur (existence check + contrainte unique BDD sur
 * source_id).
 *
 * Changer le paramètre d'une organisation n'affecte jamais les commissions déjà
 * générées : chaque méthode n'agit que sur l'événement en cours, jamais
 * rétroactivement (cf. CLAUDE.md / spec §7).
 */
class CommissionTriggerService
{
    // ── Vente ─────────────────────────────────────────────────────────────────

    /**
     * Appelé à la validation réelle du chargement (cf.
     * CommandeVenteService::validerChargement()), une fois les quantités
     * réellement chargées connues.
     *
     * Réservé à vente_standard (décision produit du 30/08/2026) : distribution_client ne génère
     * jamais de commission au chargement, quel que soit le déclencheur configuré pour
     * l'organisation — sa commission naît exclusivement à la validation de réception, cf.
     * onReceptionDistributionValidee().
     *
     * Sous CHARGEMENT_VALIDE : génère la commission maintenant, en CREEE, sur
     * la base des quantités chargées.
     *
     * Sous FACTURE_ENCAISSEE : ne fait rien. Aucune ligne de commission
     * n'existe tant que la facture n'est pas payée — cf.
     * onFactureVenteEncaissee().
     */
    public static function onChargementValide(CommandeVente $commande): void
    {
        if ($commande->nature_operation === NatureOperation::DISTRIBUTION_CLIENT) {
            return;
        }

        if (self::declencheurVente($commande->organization_id) !== DeclencheurCommissionVente::CHARGEMENT_VALIDE) {
            return;
        }

        self::genererCommissionVente($commande);
    }

    /**
     * Appelé à chaque transition réelle de facture vers le statut PAYEE (cf.
     * FactureVente::recalculStatut()) — jamais sur un simple clic contrôleur, et
     * jamais sur un encaissement partiel (PARTIEL).
     *
     * Sous FACTURE_ENCAISSEE : génère la commission maintenant, en CREEE. La
     * commande est nécessairement déjà passée par la validation du chargement
     * à ce stade (une facture ne devient encaissable qu'après, cf.
     * CommandeVente::isEncaissable()), donc les quantités chargées sont
     * garanties disponibles pour le calcul.
     *
     * Sous CHARGEMENT_VALIDE : ne fait rien, la commission existe déjà depuis
     * le chargement.
     *
     * Réservé à vente_standard, comme onChargementValide() : l'encaissement d'une facture de
     * distribution ne déclenche jamais sa commission, même sous FACTURE_ENCAISSEE — seule la
     * réception validée le fait (décision produit du 30/08/2026).
     */
    public static function onFactureVenteEncaissee(FactureVente $facture): void
    {
        $commande = $facture->commande;
        if (! $commande) {
            return;
        }

        if ($commande->nature_operation === NatureOperation::DISTRIBUTION_CLIENT) {
            return;
        }

        if (self::declencheurVente($commande->organization_id) !== DeclencheurCommissionVente::FACTURE_ENCAISSEE) {
            return;
        }

        self::genererCommissionVente($commande);
    }

    /**
     * Appelé à chaque transition réelle de facture DEPUIS PAYEE vers un autre statut
     * (encaissement supprimé — cf. EncaissementVenteController::destroy(), ou tout autre
     * chemin traversant FactureVente::recalculStatut()) — symétrique de
     * onFactureVenteEncaissee().
     *
     * Sous FACTURE_ENCAISSEE : le fait générateur de la commission (facture payée) vient
     * de disparaître — invalide (statut ANNULEE) toutes les parts/enveloppes de cette
     * commande qui ne sont pas déjà payées. Une part déjà PAYE n'est jamais reprise
     * (historique de paiement conservé tel quel, même principe que
     * CommandeVenteService::annulerCommissionsAssociees() pour l'annulation de commande).
     *
     * Sous CHARGEMENT_VALIDE : ne fait rien, la commission n'a jamais été liée à
     * l'encaissement — la retirer serait une régression inverse (perte d'une commission
     * légitime pour un motif sans rapport).
     */
    public static function onFactureVenteEncaissementRetire(FactureVente $facture): void
    {
        $commande = $facture->commande;
        if (! $commande) {
            return;
        }

        if ($commande->nature_operation === NatureOperation::DISTRIBUTION_CLIENT) {
            return;
        }

        if (self::declencheurVente($commande->organization_id) !== DeclencheurCommissionVente::FACTURE_ENCAISSEE) {
            return;
        }

        foreach ($commande->commissions as $commission) {
            $commission->parts()
                ->whereNotIn('statut', [StatutCommission::PAYE->value, StatutCommission::ANNULEE->value])
                ->update(['statut' => StatutCommission::ANNULEE->value]);

            if ($commission->statut !== StatutCommission::PAYE) {
                $commission->update(['statut' => StatutCommission::ANNULEE->value]);
            }
        }
    }

    /**
     * Appelé à la validation réelle de la réception d'une distribution (cf.
     * CommandeVenteService::validerReceptionDistribution()) — UNIQUE déclencheur de commission
     * pour distribution_client (décision produit du 30/08/2026), jamais conditionné au paramètre
     * organisation Parametre::getDeclencheurCommissionVente() qui ne régit plus que
     * vente_standard : la réception est la seule confirmation que la mission de distribution a
     * réellement eu lieu, contrairement au chargement (simple départ du véhicule) ou à
     * l'encaissement (simple paiement, indépendant de la livraison effective). Génère sur la base
     * des quantités réellement reçues (quantite_livree), jamais chargées — cf.
     * CommissionEnveloppeGenerator::contexteDepuisCommandeVente().
     */
    public static function onReceptionDistributionValidee(CommandeVente $commande): void
    {
        CommissionEnveloppeGenerator::genererPourCommandeVente(
            $commande,
            declencheurUserId: auth()->id(),
        );
    }

    /**
     * Appelé à la création réelle d'une vente directe (cf.
     * CommandeVenteService::creerFactureDirecte()), inconditionnel comme
     * onReceptionDistributionValidee() — jamais conditionné à
     * Parametre::getDeclencheurCommissionVente(), qui suppose une étape « chargement »
     * inexistante sur ce chemin (pas de véhicule, décrément de stock immédiat). C'est le SEUL
     * événement disponible pour une vente directe : sans lui, un Grossiste en Enlèvement (seul
     * cas actuel où une vente directe peut générer une commission, cf.
     * CommissionEnveloppeGenerator::genererPourCommandeVente()) ne déclencherait jamais sa
     * commission consultant sous le déclencheur CHARGEMENT_VALIDE.
     *
     * Sans effet pour toute vente directe non-Grossiste (Externe) : commission_eligible_snapshot
     * est déjà false et le client n'est pas GROSSISTE, donc genererPourCommandeVente() retourne
     * immédiatement — aucun changement de comportement pour l'existant.
     */
    public static function onVenteDirecteFacturee(CommandeVente $commande): void
    {
        CommissionEnveloppeGenerator::genererPourCommandeVente(
            $commande,
            declencheurUserId: auth()->id(),
        );
    }

    /**
     * Moteur unique de génération de commission de vente.
     *
     * CommissionEnveloppeGenerator::genererPourCommandeVente() ouvre sa PROPRE
     * transaction isolée et n'échoue jamais de façon à faire annuler l'appelant
     * (elle catch et trace toute erreur dans commission_generation_attempts sans
     * jamais relancer) — un appel imbriqué dans la transaction de
     * chargement/encaissement est donc sans risque pour celle-ci, y compris sous
     * les tests (RefreshDatabase ne permet pas d'observer un DB::afterCommit()
     * dans le même test, seule une exécution synchrone imbriquée reste
     * testable). Toujours invoqué en tout dernier, une fois toutes les écritures
     * métier de l'opération déclenchante faites.
     *
     * auth()->id() capture l'utilisateur réellement à l'origine de l'événement
     * métier (chargement validé / facture encaissée) — jamais transmis avant
     * 2026-08-25, ce qui laissait `commission_generation_attempts.created_by`
     * systématiquement NULL et rendait impossible d'alerter "la personne qui a
     * encaissé" en cas de commission manquante (cf. CommissionManquanteNotification).
     */
    private static function genererCommissionVente(CommandeVente $commande): void
    {
        CommissionEnveloppeGenerator::genererPourCommandeVente(
            $commande,
            declencheurUserId: auth()->id(),
        );
    }

    // ── Logistique ────────────────────────────────────────────────────────────

    /**
     * Appelé à la transition réelle CHARGEMENT → TRANSIT du transfert (« chargement
     * validé », cf. TransfertLogistiqueService::avancerStatut()).
     *
     * Sous CHARGEMENT_VALIDE : génère la commission maintenant, sur la base de la
     * quantité chargée (quantite_recue n'existe pas encore à ce stade). Le
     * montant est figé à cet instant : un écart constaté plus tard à la
     * réception ne déclenche jamais de recalcul rétroactif (cf. spec §7).
     *
     * Sous RECEPTION_EFFECTUEE : ne fait rien, la génération attend la validation
     * admin de la réception (cf. onTransfertReceptionEffectuee()).
     */
    public static function onTransfertChargementValide(TransfertLogistique $transfert): void
    {
        if (self::declencheurLogistique($transfert->organization_id) !== DeclencheurCommissionLogistique::CHARGEMENT_VALIDE) {
            return;
        }

        CommissionEnveloppeGenerator::genererPourTransfertLogistique(
            $transfert,
            'quantite_chargee',
            declencheurUserId: auth()->id(),
        );
    }

    /**
     * Appelé à la validation admin réelle de la réception (« accord ») — les deux
     * points d'entrée existants : ReceptionValidationAdminController::store() (backoffice web) et
     * Api\Backoffice\Logistique\ValidationAdminController::handleAccord() (API mobile).
     *
     * Sous RECEPTION_EFFECTUEE : génère la commission maintenant, sur la base de la quantité
     * réellement reçue, montant résolu par CommissionRegle (Paramètres > Commissions >
     * Transferts logistiques).
     *
     * Sous CHARGEMENT_VALIDE : ne fait rien, la commission existe déjà depuis le
     * départ du transfert.
     *
     * Décision produit du 03/09/2026 : le moteur générique (CommissionEnveloppeGenerator) est
     * désormais le SEUL moteur de commission logistique — l'ancien CommissionLogistiqueService et
     * la bascule par organisation (estMigreVersMoteurGenerique(), retirée) sont abandonnés après
     * vérification en production qu'aucun solde `commission_logistique_parts` n'existait plus. La
     * saisie manuelle d'un montant par pack n'a donc plus de sens et n'est plus acceptée par ce
     * point d'entrée (cf. ReceptionValidationAdminController).
     */
    public static function onTransfertReceptionEffectuee(TransfertLogistique $transfert): void
    {
        if (self::declencheurLogistique($transfert->organization_id) !== DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE) {
            return;
        }

        CommissionEnveloppeGenerator::genererPourTransfertLogistique(
            $transfert,
            'quantite_recue',
            declencheurUserId: auth()->id(),
        );
    }

    // ── Lecture politique organisation ───────────────────────────────────────

    private static function declencheurVente(string $organizationId): DeclencheurCommissionVente
    {
        return Parametre::getDeclencheurCommissionVente($organizationId);
    }

    private static function declencheurLogistique(string $organizationId): DeclencheurCommissionLogistique
    {
        return Parametre::getDeclencheurCommissionLogistique($organizationId);
    }
}
