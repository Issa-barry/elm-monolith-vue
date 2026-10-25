<?php

namespace App\Enums;

/**
 * Anomalies détectées par le contrôle des encaissements (cf. docs/controle-encaissements.md).
 * Les quatre premiers types portent sur une facture ; les deux derniers sur un encaissement, une
 * pièce comptable ou une référence Mobile Money.
 */
enum TypeAnomalieEncaissement: string
{
    /** Surplus expliqué par deux encaissements identiques et rapprochés : doublon technique. */
    case DOUBLE_ENCAISSEMENT = 'double_encaissement';

    /** Encaissé net supérieur au facturé, sans doublon évident. */
    case SURPLUS_ENCAISSE = 'surplus_encaisse';

    /** Deux encaissements identiques et rapprochés, mais la facture n'est pas dépassée. */
    case DOUBLON_SUSPECT = 'doublon_suspect';

    /** Précommande dont l'encaissé dépasse le facturé : remboursement à faire (ADR 0019). */
    case TROP_PERCU_PRECOMMANDE = 'trop_percu_precommande';

    /** Encaissement sans écriture validée, ou écriture validée d'un encaissement disparu. */
    case INCOHERENCE_COMPTABLE = 'incoherence_comptable';

    /** Même référence Mobile Money sur plusieurs encaissements (historique antérieur à l'ADR 0014). */
    case REFERENCE_MOBILE_MONEY_REUTILISEE = 'reference_mobile_money_reutilisee';

    public function label(): string
    {
        return match ($this) {
            self::DOUBLE_ENCAISSEMENT => 'Double encaissement probable',
            self::SURPLUS_ENCAISSE => 'Surplus encaissé',
            self::DOUBLON_SUSPECT => 'Doublon suspect',
            self::TROP_PERCU_PRECOMMANDE => 'Trop-perçu à rembourser',
            self::INCOHERENCE_COMPTABLE => 'Incohérence comptable',
            self::REFERENCE_MOBILE_MONEY_REUTILISEE => 'Référence Mobile Money réutilisée',
        };
    }

    public function gravite(): GraviteAnomalieEncaissement
    {
        return match ($this) {
            self::DOUBLE_ENCAISSEMENT, self::SURPLUS_ENCAISSE, self::INCOHERENCE_COMPTABLE => GraviteAnomalieEncaissement::CRITIQUE,
            self::DOUBLON_SUSPECT, self::TROP_PERCU_PRECOMMANDE => GraviteAnomalieEncaissement::ATTENTION,
            self::REFERENCE_MOBILE_MONEY_REUTILISEE => GraviteAnomalieEncaissement::INFORMATION,
        };
    }

    /** Anomalie certaine (écart constaté) ou simple suspicion à vérifier par une personne. */
    public function estCertaine(): bool
    {
        return in_array($this, [self::DOUBLE_ENCAISSEMENT, self::SURPLUS_ENCAISSE, self::TROP_PERCU_PRECOMMANDE, self::INCOHERENCE_COMPTABLE], true);
    }

    /** Types contrôlés facture par facture (contrôle immédiat après un encaissement). */
    public function porteSurUneFacture(): bool
    {
        return in_array($this, [self::DOUBLE_ENCAISSEMENT, self::SURPLUS_ENCAISSE, self::DOUBLON_SUSPECT, self::TROP_PERCU_PRECOMMANDE], true);
    }

    /** Démarche conseillée, affichée dans la fiche et dans l'alerte. */
    public function demarche(): string
    {
        return match ($this) {
            self::DOUBLE_ENCAISSEMENT => "Vérifiez avec l'agent le montant réellement reçu. Si le doublon est confirmé, prenez l'anomalie en charge puis annulez l'encaissement en trop depuis la fiche.",
            self::SURPLUS_ENCAISSE => "Vérifiez les encaissements et le montant réellement reçu. Un encaissement saisi par erreur s'annule depuis la fiche ; un paiement réel en trop n'a pas de circuit de remboursement hors précommande : à traiter avec la comptabilité.",
            self::DOUBLON_SUSPECT => "Deux encaissements identiques à quelques secondes d'intervalle. Vérifiez qu'il s'agit bien de deux paiements réels ; sinon annulez celui en trop.",
            self::TROP_PERCU_PRECOMMANDE => 'Le client a versé plus que ce qui lui est facturé : remboursez-le depuis la commande (Facturation → Rembourser).',
            self::INCOHERENCE_COMPTABLE => "L'encaissement et sa comptabilisation ne concordent pas. Faites vérifier les pièces par la comptabilité avant toute autre opération.",
            self::REFERENCE_MOBILE_MONEY_REUTILISEE => "Une même référence Mobile Money figure sur plusieurs encaissements. Vérifiez auprès de l'opérateur qu'il s'agit bien de transactions distinctes.",
        };
    }
}
