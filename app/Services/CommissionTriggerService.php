<?php

namespace App\Services;

use App\Enums\BaseCalculLogistique;
use App\Enums\CommissionActivationStatut;
use App\Enums\DeclencheurCommissionLogistique;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\NatureOperation;
use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionLogistique;
use App\Models\CommissionProcessus;
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
 * CommissionEnveloppeGenerator / CommissionLogistiqueService) et ne devient
 * IMPAYE(E) qu'à la validation de la période de paiement qui la couvre (cf.
 * CommissionAdjustmentService::activerCommissionsCreees()).
 *
 * Ne recalcule jamais rien elle-même : délègue systématiquement à
 * CommissionEnveloppeGenerator / CommissionLogistiqueService, seules sources de
 * vérité du calcul (barèmes, parts). Chaque méthode est idempotente par
 * construction, via l'idempotence déjà portée par ces générateurs (existence
 * check + contrainte unique BDD sur source_id / transfert_logistique_id).
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

        if (self::estMigreVersMoteurGenerique($transfert->organization_id)) {
            CommissionEnveloppeGenerator::genererPourTransfertLogistique(
                $transfert,
                'quantite_chargee',
                declencheurUserId: auth()->id(),
            );

            return;
        }

        CommissionLogistiqueService::genererDepuisChargement($transfert);
    }

    /**
     * Appelé à la validation admin réelle de la réception (« accord ») — les deux
     * points d'entrée existants : ReceptionValidationAdminController::store()
     * (backoffice web, montant par pack saisi par l'admin) et
     * Api\Backoffice\Logistique\ValidationAdminController::handleAccord() (montant
     * automatique 200 FG/pack, cf. CommissionLogistiqueService::genererAutomatique()).
     *
     * Sous RECEPTION_EFFECTUEE : génère la commission maintenant, sur la base de la
     * quantité réellement reçue — $montantParPack si fourni (saisie admin), sinon
     * le montant automatique historique.
     *
     * Sous CHARGEMENT_VALIDE : ne fait rien, la commission existe déjà depuis le
     * départ du transfert — retourne null.
     */
    public static function onTransfertReceptionEffectuee(TransfertLogistique $transfert, ?float $montantParPack = null): ?CommissionLogistique
    {
        if (self::declencheurLogistique($transfert->organization_id) !== DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE) {
            return null;
        }

        // Moteur générique : le montant est désormais résolu par CommissionRegle (Paramètres >
        // Commissions > Transferts logistiques), une saisie manuelle par transfert n'a plus de
        // sens et est ignorée — cf. ReceptionValidationAdminController, dont le champ devient
        // conditionnel à cette même migration.
        if (self::estMigreVersMoteurGenerique($transfert->organization_id)) {
            CommissionEnveloppeGenerator::genererPourTransfertLogistique(
                $transfert,
                'quantite_recue',
                declencheurUserId: auth()->id(),
            );

            return null;
        }

        if ($montantParPack !== null) {
            $transfert->loadMissing('lignes');
            $quantiteRecue = (int) $transfert->lignes->sum('quantite_recue');

            return CommissionLogistiqueService::genererPourTransfert(
                $transfert,
                BaseCalculLogistique::PAR_PACK->value,
                $montantParPack,
                $quantiteRecue > 0 ? $quantiteRecue : 0,
            );
        }

        return CommissionLogistiqueService::genererAutomatique($transfert);
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

    /**
     * Bascule par organisation, jamais globale : une organisation passe au moteur générique dès
     * qu'elle enregistre sa première règle dans Paramètres > Commissions > Transferts logistiques
     * (le processus passe alors ACTIF, cf. Settings\CommissionRegleController — même mécanisme que
     * pour la vente). Tant qu'aucune règle n'a été configurée, l'ancien moteur
     * (CommissionLogistiqueService) reste seul appelé — aucune migration ni recalcul de l'historique
     * déjà généré, qui reste consultable et payable tel quel indéfiniment.
     */
    public static function estMigreVersMoteurGenerique(string $organizationId): bool
    {
        return CommissionProcessus::where('organization_id', $organizationId)
            ->where('code', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
            ->where('statut', CommissionActivationStatut::ACTIF->value)
            ->exists();
    }
}
