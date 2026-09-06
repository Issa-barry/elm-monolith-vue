<?php

namespace App\Services\Commission;

use App\Enums\ClientType;
use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Parametre;
use App\Models\Vehicule;
use InvalidArgumentException;

/**
 * Valeurs par défaut d'un CommissionProcessus par code — factorise le mapping libellé/déclencheur/
 * stratégie d'ancrage jusqu'ici dupliqué entre CommissionEnveloppeGenerator, EquipeLivraisonController
 * et Settings\CommissionRegleController. Aucune notion d'« activation » séparée : toute organisation
 * utilise le même moteur dès sa création — le processus est provisionné à la volée s'il n'existe pas
 * encore, jamais un pré-requis silencieusement bloquant (cf. CommissionEnveloppeGenerator::executerAvecTentative()).
 */
class CommissionProcessusDefaults
{
    /**
     * Libellé affichable par code — source unique consommée par `pour()` (provisionnement) et
     * `libelle()` (affichage pur, ex. colonne "Processus" des listes Ventes/Distributions), pour
     * qu'un renommage ne puisse jamais diverger entre les deux usages.
     */
    private const LIBELLES = [
        CommissionProcessus::CODE_VENTE => 'Vente',
        CommissionProcessus::CODE_DISTRIBUTION_CLIENT => 'Distribution client',
        CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT => 'Transfert logistique',
        CommissionProcessus::CODE_TRANSFERT_GROSSISTE => 'Transfert grossiste',
    ];

    /**
     * Libellé pur, sans dépendance à une organisation — utilisé par l'affichage (ex. colonne
     * "Processus" d'une liste de commandes), jamais par la résolution de déclencheur/ancrage qui,
     * elle, reste dans `pour()`.
     */
    public static function libelle(string $code): string
    {
        return self::LIBELLES[$code] ?? throw new InvalidArgumentException("Code processus inconnu : {$code}");
    }

    /** @return array{libelle:string, declencheur:string, strategie_ancrage_site:string} */
    public static function pour(string $organizationId, string $code): array
    {
        return match ($code) {
            CommissionProcessus::CODE_VENTE => [
                'libelle' => self::libelle($code),
                'declencheur' => Parametre::getDeclencheurCommissionVente($organizationId)->value,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            ],
            CommissionProcessus::CODE_DISTRIBUTION_CLIENT => [
                'libelle' => self::libelle($code),
                'declencheur' => Parametre::getDeclencheurCommissionVente($organizationId)->value,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            ],
            CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT => [
                'libelle' => self::libelle($code),
                'declencheur' => Parametre::getDeclencheurCommissionLogistique($organizationId)->value,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::SOURCE->value,
            ],
            // Déclenché par le workflow de vente standard (chargement/encaissement), jamais le
            // paramètre logistique : un Grossiste + Livraison suit le même workflow flotte
            // qu'une vente classique (cf. docs/grossiste.md), seul le processus de commission
            // diffère. Ancrage OPERATION (site de la commande elle-même), comme Vente/Distribution
            // — jamais SOURCE, notion propre aux transferts internes (TransfertLogistique).
            CommissionProcessus::CODE_TRANSFERT_GROSSISTE => [
                'libelle' => self::libelle($code),
                'declencheur' => Parametre::getDeclencheurCommissionVente($organizationId)->value,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            ],
            default => throw new InvalidArgumentException("Code processus inconnu : {$code}"),
        };
    }

    public static function resoudreOuCreer(string $organizationId, string $code): CommissionProcessus
    {
        return CommissionProcessus::firstOrCreate(
            ['organization_id' => $organizationId, 'code' => $code],
            [...self::pour($organizationId, $code), 'statut' => CommissionActivationStatut::ACTIF->value],
        );
    }

    /**
     * Colonne d'usage (`Vehicule::livraison_vente`/`livraison_logistique`) qui rend CE processus
     * applicable à un véhicule — source unique du mapping "processus disponible" ≠ "processus
     * obligatoire" (révisé le 31/08/2026, incident : un véhicule Vente-only affichait Distribution
     * client/Transfert logistique comme « à faire » alors qu'aucune donnée métier ne l'autorise à
     * exercer ces processus). Consommée par VehiculeController (onglets/tabs et statuts de partage
     * de la fiche véhicule) et EquipeLivraisonController (validation `processus_code`), pour que
     * les deux ne puissent jamais diverger sur "ce processus a-t-il un sens pour ce véhicule ?".
     * `vente` ↔ livraison_vente ; `distribution_client`/`logistique_transfert`/`transfert_grossiste`
     * ↔ livraison_logistique (une distribution client, un transfert interne et une livraison
     * Grossiste sont tous les trois des opérations logistiques, jamais des ventes au comptoir —
     * décision produit du 05/09/2026, cf. docs/grossiste.md : "Transfert grossiste" ne s'applique
     * qu'aux véhicules qui font de la logistique).
     */
    public static function usageVehiculeRequis(string $code): string
    {
        return match ($code) {
            CommissionProcessus::CODE_VENTE => 'livraison_vente',
            CommissionProcessus::CODE_DISTRIBUTION_CLIENT,
            CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
            CommissionProcessus::CODE_TRANSFERT_GROSSISTE => 'livraison_logistique',
            default => throw new InvalidArgumentException("Code processus inconnu : {$code}"),
        };
    }

    public static function estApplicablePourVehicule(string $code, Vehicule $vehicule): bool
    {
        return (bool) $vehicule->{self::usageVehiculeRequis($code)};
    }

    /**
     * Sous-ensemble de `CommissionRegleController::processusCodesDisponibles()` réellement
     * applicable à ce véhicule selon ses usages — jamais l'inverse (aucun ordre ajouté, aucun code
     * inventé). Un véhicule sans aucun usage actif (`is_utilisable() === false`, cas transitoire)
     * renvoie un tableau vide : à l'appelant de décider du repli (cf. VehiculeController::show()).
     *
     * @param  array<int, string>  $codesDisponibles
     * @return array<int, string>
     */
    public static function codesApplicablesPourVehicule(Vehicule $vehicule, array $codesDisponibles): array
    {
        return array_values(array_filter(
            $codesDisponibles,
            fn (string $code) => self::estApplicablePourVehicule($code, $vehicule),
        ));
    }

    /**
     * Décision produit du 02/09/2026 : "processus métier" (identité, reporting, historique) et
     * "barème" (montant réellement appliqué) sont deux notions distinctes. `distribution_client`
     * reste un processus réel — chaque distribution génère une CommissionEnveloppe qui LUI est
     * rattachée, jamais silencieusement reclassée en `logistique_transfert` — mais il n'a pas
     * d'onglet dédié dans Paramètres > Commissions
     * (`Settings\CommissionRegleController::processusCodesDisponibles()` n'expose que Vente et
     * Transfert logistique). Tant qu'aucune CommissionRegle active ne lui est explicitement
     * propre, le calcul du montant retombe donc sur celui de `logistique_transfert` — un
     * distributeur ELM est livré par la même flotte/équipe qu'un transfert interne, décision
     * confirmée par le métier.
     *
     * Bascule "tout ou rien" par organisation, jamais cible par cible : dès qu'UNE seule
     * CommissionRegle active existe pour `distribution_client`, il cesse immédiatement d'hériter
     * de `logistique_transfert`, même pour les cibles où lui-même n'aurait rien configuré (évite
     * un mélange confus "moitié barème propre, moitié hérité" difficile à auditer). Utilisée à la
     * fois par la génération réelle (CommissionEnveloppeGenerator) et par le garde-fou préventif à
     * la création (CommandeVenteController::ensurePartageLivraisonCategorieConfigure()), pour que
     * les deux ne puissent jamais résoudre un barème différent pour la même commande.
     */
    /**
     * Code processus D'IDENTITÉ applicable à une CommandeVente — source UNIQUE de cette décision
     * (chantier « Transfert grossiste », 05/09/2026), consommée à la fois par
     * CommissionEnveloppeGenerator::genererPourCommandeVente() (génération réelle) et
     * CommandeVenteController::ensurePartageLivraisonCategorieConfigure() (garde-fou préventif à
     * la création) — ces deux appelants calculaient auparavant ce code indépendamment (risque de
     * divergence déjà signalé avant ce chantier), désormais éliminé.
     *
     * - distribution_client : priorité, inchangé (cf. NatureOperation).
     * - Grossiste + Livraison (véhicule de flotte) : transfert_grossiste — jamais vente, jamais
     *   logistique_transfert (cf. docs/grossiste.md, CommissionProcessus::CODE_TRANSFERT_GROSSISTE).
     * - Grossiste + Enlèvement (aucun véhicule) : vente, comme n'importe quel autre client sans
     *   véhicule — décision produit explicite du 05/09/2026, ne pas généraliser.
     * - Tout le reste (Externe/Revendeur/Distributeur, avec ou sans véhicule) : vente, inchangé.
     *
     * $natureOperation nullable : certaines commandes historiques (antérieures à l'introduction de
     * la colonne) portent un `nature_operation` NULL en base — comportement inchangé, `null` n'est
     * jamais égal à `DISTRIBUTION_CLIENT`, retombe correctement sur la même branche qu'avant.
     */
    public static function identiteCodePourVente(
        ?NatureOperation $natureOperation,
        ?ClientType $clientType,
        ?ModeRemiseGrossiste $modeRemiseGrossiste,
    ): string {
        if ($natureOperation === NatureOperation::DISTRIBUTION_CLIENT) {
            return CommissionProcessus::CODE_DISTRIBUTION_CLIENT;
        }

        if ($clientType === ClientType::GROSSISTE && $modeRemiseGrossiste === ModeRemiseGrossiste::LIVRAISON) {
            return CommissionProcessus::CODE_TRANSFERT_GROSSISTE;
        }

        return CommissionProcessus::CODE_VENTE;
    }

    public static function processusResolutionBareme(CommissionProcessus $identite): CommissionProcessus
    {
        if ($identite->code !== CommissionProcessus::CODE_DISTRIBUTION_CLIENT) {
            return $identite;
        }

        $aSaPropreConfiguration = CommissionRegle::where('processus_id', $identite->id)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->exists();

        if ($aSaPropreConfiguration) {
            return $identite;
        }

        return self::resoudreOuCreer($identite->organization_id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
    }
}
