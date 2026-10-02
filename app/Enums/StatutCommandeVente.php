<?php

namespace App\Enums;

enum StatutCommandeVente: string
{
    case BROUILLON = 'brouillon';
    case A_CHARGER = 'a_charger';
    case CHARGEMENT_EN_COURS = 'chargement_en_cours';
    case LIVRAISON_EN_COURS = 'livraison_en_cours';
    case LIVREE = 'livree';
    case FACTURATION = 'facturation';
    case CLOTUREE = 'cloturee';
    case ANNULEE = 'annulee';
    /**
     * Retour TOTAL de la marchandise par le livreur, avant tout encaissement (cf.
     * CommandeVenteRetourService) : tout ce qui avait été chargé est revenu, la facture est annulée
     * et le stock réintégré. Distinct d'ANNULEE, qui ne survient jamais après le départ du véhicule.
     */
    case RETOURNEE = 'retournee';
    /**
     * Annulation exceptionnelle d'une commande saisie par erreur (ex : formation faite en
     * production), possible après le chargement, la facturation et l'encaissement — cf.
     * AnnulationExceptionnelleService et docs/adr/0004. Distinct d'ANNULEE (annulation normale,
     * jamais après le départ du véhicule ni après un encaissement) : les régularisations
     * (encaissements contrepassés, stock réintégré, cashback retiré) n'existent que sur ce chemin.
     */
    case ANNULEE_ERREUR_SAISIE = 'annulee_erreur_saisie';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::A_CHARGER => 'À charger',
            self::CHARGEMENT_EN_COURS => 'Chargement en cours',
            self::LIVRAISON_EN_COURS => 'Livraison en cours',
            self::LIVREE => 'Livrée',
            self::FACTURATION => 'À encaisser',
            self::CLOTUREE => 'Clôturée',
            self::ANNULEE => 'Annulée',
            self::RETOURNEE => 'Retournée',
            self::ANNULEE_ERREUR_SAISIE => 'Annulée (erreur de saisie)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BROUILLON => 'secondary',
            self::A_CHARGER => 'warn',
            self::CHARGEMENT_EN_COURS => 'warn',
            self::LIVRAISON_EN_COURS => 'primary',
            self::LIVREE => 'success',
            self::FACTURATION => 'primary',
            self::CLOTUREE => 'success',
            self::ANNULEE => 'danger',
            self::RETOURNEE => 'warn',
            self::ANNULEE_ERREUR_SAISIE => 'danger',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::BROUILLON => 'bg-zinc-400 dark:bg-zinc-500',
            self::A_CHARGER => 'bg-amber-400',
            self::CHARGEMENT_EN_COURS => 'bg-orange-500',
            self::LIVRAISON_EN_COURS => 'bg-blue-500',
            self::LIVREE => 'bg-teal-500',
            self::FACTURATION => 'bg-violet-500',
            self::CLOTUREE => 'bg-emerald-500',
            self::ANNULEE => 'bg-red-400',
            self::RETOURNEE => 'bg-orange-500',
            self::ANNULEE_ERREUR_SAISIE => 'bg-red-500',
        };
    }

    /** Modifiable uniquement en brouillon */
    public function isEditable(): bool
    {
        return $this === self::BROUILLON;
    }

    /** Statuts terminaux — aucune transition possible */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CLOTUREE, self::ANNULEE, self::RETOURNEE, self::ANNULEE_ERREUR_SAISIE]);
    }

    /** Annulable depuis BROUILLON, A_CHARGER ou FACTURATION (commande directe non encaissée) */
    public function isAnnulable(): bool
    {
        return in_array($this, [self::BROUILLON, self::A_CHARGER, self::FACTURATION]);
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
