<?php

namespace App\Support\Commission;

use App\Models\CommissionProcessus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtre optionnel par processus (vente/distribution_client/logistique_transfert) pour les écrans
 * de reporting Comptabilité qui interrogent CommissionEnveloppePart — jamais appliqué à la
 * machinerie de paiement/période (PeriodeCalculatorService, CommissionEnveloppePartAllocationService),
 * qui doit au contraire toujours unir tous les processus (cf. docs/commissions.md).
 *
 * Garde volontairement les 3 codes, y compris distribution_client, alors que
 * Settings\CommissionRegleController::processusCodesDisponibles() (routage/configuration de
 * NOUVELLES opérations, depuis le 01/09/2026) n'en propose plus que 2 : ce filtre sert à
 * REPORTER des CommissionEnveloppe déjà générées, dont certaines restent historiquement
 * rattachées à distribution_client. Comptabilite\CommissionVenteController::breakdownParProcessus()
 * répartit le total déjà généré sur exactement ces options — en retirer une ferait disparaître
 * silencieusement sa part du total affiché (aucune commission perdue en base, mais une
 * réconciliation visuellement fausse). Ne jamais aligner cette liste sur processusCodesDisponibles().
 */
class CommissionProcessusFilter
{
    /** @return array<array{value:string,label:string}> */
    public static function options(): array
    {
        return [
            ['value' => CommissionProcessus::CODE_VENTE, 'label' => 'Vente'],
            ['value' => CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 'label' => 'Distribution client'],
            ['value' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 'label' => 'Transfert logistique'],
        ];
    }

    /**
     * Mêmes options que options(), avec en tête un choix "Tous les processus" (valeur vide, donc
     * sans effet dans appliquer()) — pour les fiches détail bénéficiaire, où la situation globale
     * est la valeur par défaut la plus utile (cf. docs/commissions.md).
     *
     * @return array<array{value:string,label:string}>
     */
    public static function optionsAvecTous(): array
    {
        return [
            ['value' => '', 'label' => 'Tous les processus'],
            ...self::options(),
        ];
    }

    /** Résout le libellé d'un code processus depuis options() — jamais dupliqué localement. */
    public static function labelFor(?string $processusCode): ?string
    {
        if (! $processusCode) {
            return null;
        }

        foreach (self::options() as $option) {
            if ($option['value'] === $processusCode) {
                return $option['label'];
            }
        }

        return $processusCode;
    }

    /**
     * Applique le filtre sur une requête CommissionEnveloppePart si $processusCode est non vide —
     * sans effet sinon (vue consolidée par défaut, cf. décision produit : "les vues comptables
     * globales peuvent naturellement consolider plusieurs processus").
     */
    public static function appliquer(Builder $query, ?string $processusCode): Builder
    {
        if (! $processusCode) {
            return $query;
        }

        return $query->whereHas('enveloppe.processus', fn ($q) => $q->where('code', $processusCode));
    }
}
