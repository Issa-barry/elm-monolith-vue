<?php

namespace App\Services;

use App\Enums\BaseCalculLogistique;
use App\Enums\DeclencheurCommissionLogistique;
use App\Enums\DeclencheurCommissionVente;
use App\Models\CommandeVente;
use App\Models\CommissionLogistique;
use App\Models\FactureVente;
use App\Models\Parametre;
use App\Models\TransfertLogistique;

/**
 * Point d'entrée unique reliant les événements métier réels (chargement validé,
 * facture encaissée, réception effectuée) à la génération des commissions —
 * seule couche qui lit le paramètre organisation (Parametre::getDeclencheurXxx)
 * et décide si l'événement reçu correspond au déclencheur configuré.
 *
 * Le déclencheur ne choisit QUE le moment de naissance de la commission,
 * jamais son statut initial : dans tous les cas elle naît CREEE (cf.
 * CommissionGenerator / CommissionLogistiqueService) et ne devient IMPAYE(E)
 * qu'à la validation de la période de paiement qui la couvre (cf.
 * CommissionAdjustmentService::activerCommissionsCreees()).
 *
 * Ne recalcule jamais rien elle-même : délègue systématiquement à
 * CommissionGenerator / CommissionLogistiqueService, seules sources de vérité du
 * calcul (CommissionCalculator, barèmes, parts). Chaque méthode est idempotente
 * par construction, via l'idempotence déjà portée par ces générateurs (existence
 * check + contrainte unique BDD sur commande_vente_id / transfert_logistique_id).
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
     * Sous CHARGEMENT_VALIDE : génère la commission maintenant, en CREEE, sur
     * la base des quantités chargées.
     *
     * Sous FACTURE_ENCAISSEE : ne fait rien. Aucune ligne de commission
     * n'existe tant que la facture n'est pas payée — cf.
     * onFactureVenteEncaissee().
     */
    public static function onChargementValide(CommandeVente $commande): void
    {
        if (self::declencheurVente($commande->organization_id) !== DeclencheurCommissionVente::CHARGEMENT_VALIDE) {
            return;
        }

        CommissionGenerator::generateForCommandeIfMissing($commande);
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
     */
    public static function onFactureVenteEncaissee(FactureVente $facture): void
    {
        $commande = $facture->commande;
        if (! $commande) {
            return;
        }

        if (self::declencheurVente($commande->organization_id) !== DeclencheurCommissionVente::FACTURE_ENCAISSEE) {
            return;
        }

        CommissionGenerator::generateForCommandeIfMissing($commande);
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
}
