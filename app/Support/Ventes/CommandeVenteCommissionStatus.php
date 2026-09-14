<?php

namespace App\Support\Ventes;

use App\Enums\CommissionGenerationStatut;
use App\Enums\NatureOperation;
use App\Models\CommandeVente;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;

/**
 * Statut de la dernière tentative de génération de commission d'une commande vente — extrait de
 * l'ancien `CommandeVenteController`, partagé par `Ventes\ShowCommandeVenteController` et
 * `Ventes\RelancerCommissionsCommandeVenteController`.
 */
final class CommandeVenteCommissionStatus
{
    /**
     * Statut de la DERNIÈRE tentative de génération de commission (distinct de
     * commission_statut, qui ne reflète que le paiement de commissions déjà
     * générées avec succès) — retourne null tant que rien n'est en anomalie :
     * aucune tentative encore, ou dernière tentative pleinement réussie (SUCCES).
     * Remonte ERREUR ("à régulariser", aucune cible générée) et PARTIEL
     * ("partiellement générée", au moins une cible générée mais une autre à
     * régulariser — chantier 2A du 05/09/2026, indépendance des cibles), les
     * deux seuls cas nécessitant une alerte visible (cf. incident CMD-230826-004,
     * où cet état n'était visible nulle part dans l'UI faute d'être exposé ici).
     *
     * Ne filtre plus sur commission_eligible_snapshot (retiré le 05/09/2026,
     * chantier 2A) : ce champ ne conditionne plus que les cibles PROPRIETAIRE/
     * EQUIPE_LIVRAISON (cf. CommissionEnveloppeGenerator) — une commande sans
     * véhicule peut désormais avoir une tentative réelle (SITE/CONSULTANT) à
     * exposer ici. Sans effet pour une commande n'ayant jamais atteint de
     * déclencheur : $derniere reste simplement null ci-dessous.
     */
    public static function getCommissionGenerationStatut(CommandeVente $commande): ?array
    {
        // Route par nature_operation — jamais un CODE_VENTE codé en dur (correctif du
        // 30/08/2026 : une commande distribution_client ne remontait jamais son état "à
        // régulariser", puisque sa CommissionGenerationAttempt est rattachée au processus
        // distribution_client, pas vente). CommissionEnveloppeGenerator::executerAvecTentative()
        // tague toujours l'attempt avec le processus D'IDENTITÉ (jamais celui de résolution du
        // barème, cf. décision produit du 02/09/2026) — CODE_DISTRIBUTION_CLIENT reste donc le
        // bon code ici, que son barème soit propre ou hérité de logistique_transfert.
        $processusCode = $commande->nature_operation === NatureOperation::DISTRIBUTION_CLIENT
            ? CommissionProcessus::CODE_DISTRIBUTION_CLIENT
            : CommissionProcessus::CODE_VENTE;

        $processusId = CommissionProcessus::where('organization_id', $commande->organization_id)
            ->where('code', $processusCode)
            ->value('id');

        if (! $processusId) {
            return null;
        }

        $derniere = CommissionGenerationAttempt::where('source_type', CommandeVente::class)
            ->where('source_id', $commande->id)
            ->where('processus_id', $processusId)
            ->latest('created_at')
            ->first();

        if (! $derniere || ! in_array($derniere->statut, [CommissionGenerationStatut::ERREUR, CommissionGenerationStatut::PARTIEL], true)) {
            return null;
        }

        return [
            'value' => $derniere->statut->value,
            'label' => $derniere->statut->label(),
            'motif' => $derniere->motif_erreur,
        ];
    }
}
