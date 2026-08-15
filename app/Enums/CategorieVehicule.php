<?php

namespace App\Enums;

/**
 * Propriété d'un véhicule de flotte gérée (toute ligne de `vehicules`) — indépendante de
 * l'usage (`livraison_vente`/`livraison_logistique`) et indépendante d'un véhicule externe
 * (`ClientVehicle`, jamais flotte gérée, cf. analyse du modèle véhicules/partenaires) :
 *  - INTERNE : le véhicule appartient à l'organisation elle-même (propriétaire par défaut,
 *    cf. Proprietaire::interneParDefautId()).
 *  - PARTENAIRE : le véhicule appartient à un propriétaire tiers réel, mais reste entièrement
 *    intégré et géré par l'organisation (équipe, capacités, dépenses, commission propriétaire
 *    éventuelle) — ne signifie donc jamais "hors flotte".
 *
 * Source de vérité unique pour cette distinction : ne jamais la redériver ailleurs depuis
 * `proprietaire_id` (cf. Vehicule::$categorie, remplace l'ancienne déduction implicite).
 */
enum CategorieVehicule: string
{
    case INTERNE = 'interne';
    case PARTENAIRE = 'partenaire';

    public function label(): string
    {
        return match ($this) {
            self::INTERNE => 'Interne',
            self::PARTENAIRE => 'Partenaire',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }

    /**
     * Cohérence catégorie ↔ propriétaire — centralisée ici pour que formulaire, import et
     * conversion de proposition appliquent exactement la même règle :
     *  - INTERNE ne doit jamais être rattaché à un véritable propriétaire tiers ;
     *  - PARTENAIRE exige un véritable propriétaire tiers (jamais le propriétaire interne
     *    par défaut, jamais aucun propriétaire).
     *
     * $proprietaireEstTiers : true si un propriétaire autre que l'interne par défaut est/sera
     * rattaché au véhicule — calculé par l'appelant (VehiculeController compare à
     * Proprietaire::interneParDefautId(), ImportFlotteParser utilise directement le fait
     * qu'une colonne proprietaire_* ait été saisie).
     */
    public function coherentAvecProprietaireTiers(bool $proprietaireEstTiers): bool
    {
        return match ($this) {
            self::INTERNE => ! $proprietaireEstTiers,
            self::PARTENAIRE => $proprietaireEstTiers,
        };
    }
}
