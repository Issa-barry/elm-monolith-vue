<?php

namespace App\Services;

use App\Enums\StatutTransfert;
use App\Models\TransfertLogistique;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransfertLogistiqueService
{
    /**
     * Workflow : BROUILLON → CHARGEMENT → TRANSIT → RECEPTION → CLOTURE
     *
     * @throws ValidationException si les pré-conditions ne sont pas satisfaites
     */
    public static function avancerStatut(TransfertLogistique $transfert): TransfertLogistique
    {
        $suivant = match ($transfert->statut) {
            StatutTransfert::BROUILLON => StatutTransfert::CHARGEMENT,
            StatutTransfert::CHARGEMENT => StatutTransfert::TRANSIT,
            StatutTransfert::TRANSIT => StatutTransfert::RECEPTION,
            default => null,
        };

        if ($suivant === null) {
            return $transfert;
        }

        self::validerPreconditions($transfert, $suivant);

        DB::transaction(function () use ($transfert, $suivant) {
            $updates = ['statut' => $suivant->value];

            // Départ réel enregistré quand le camion part (TRANSIT)
            if ($suivant === StatutTransfert::TRANSIT) {
                $updates['date_depart_reelle'] = now()->toDateString();
            }

            // Arrivée réelle enregistrée à la RECEPTION
            if ($suivant === StatutTransfert::RECEPTION) {
                $updates['date_arrivee_reelle'] = now()->toDateString();
            }

            $transfert->update($updates);

            // Sortie stock source : marchandises physiquement parties (TRANSIT)
            if ($suivant === StatutTransfert::TRANSIT) {
                MouvementStockService::enregistrerSortieSource($transfert);
            }

            // Entrée stock destination : marchandises reçues (RECEPTION)
            if ($suivant === StatutTransfert::RECEPTION) {
                MouvementStockService::enregistrerEntreeDestination($transfert);
            }
        });

        return $transfert->fresh();
    }

    /**
     * Clôturer automatiquement un transfert en RECEPTION une fois toutes les commissions versées.
     * Ne pas appeler manuellement : déclenché uniquement par VersementCommissionLogistiqueController.
     *
     * @throws \LogicException si le transfert n'est pas en RECEPTION ou si la commission est incomplète
     */
    public static function cloturerAutomatiquement(TransfertLogistique $transfert): TransfertLogistique
    {
        if ($transfert->statut !== StatutTransfert::RECEPTION) {
            throw new \LogicException('Seul un transfert en RECEPTION peut être clôturé automatiquement.');
        }

        $transfert->loadMissing('commission');

        if ($transfert->commission && ! $transfert->commission->isVersee()) {
            throw new \LogicException('La commission logistique n\'est pas encore entièrement versée.');
        }

        $transfert->update(['statut' => StatutTransfert::CLOTURE->value]);

        return $transfert->fresh();
    }

    /**
     * Annuler un transfert — autorisé uniquement en BROUILLON ou CHARGEMENT.
     * Silently no-op si le statut n'est pas éligible (la policy devrait déjà avoir bloqué).
     */
    public static function annuler(TransfertLogistique $transfert): TransfertLogistique
    {
        if (! in_array($transfert->statut, [StatutTransfert::BROUILLON, StatutTransfert::CHARGEMENT])) {
            return $transfert;
        }

        $transfert->update(['statut' => StatutTransfert::ANNULE->value]);

        return $transfert->fresh();
    }

    // ── Pré-conditions ────────────────────────────────────────────────────────

    public static function validerPreconditions(TransfertLogistique $transfert, StatutTransfert $cible): void
    {
        $errors = [];

        match ($cible) {
            StatutTransfert::CHARGEMENT => self::checkChargement($transfert, $errors),
            StatutTransfert::TRANSIT => self::checkTransit($transfert, $errors),
            StatutTransfert::RECEPTION => self::checkReception($transfert, $errors),
            default => null,
        };

        if (! empty($errors)) {
            throw ValidationException::withMessages(['statut' => $errors]);
        }
    }

    /**
     * BROUILLON → CHARGEMENT : véhicule + au moins une ligne requise.
     */
    private static function checkChargement(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        if ($t->lignes->isEmpty()) {
            $errors[] = 'Le transfert doit contenir au moins une ligne produit.';
        }

        if (! $t->vehicule_id) {
            $errors[] = 'Un véhicule doit être assigné avant de démarrer le chargement.';
        }
    }

    /**
     * CHARGEMENT → TRANSIT : toutes les quantités chargées doivent être renseignées.
     */
    private static function checkTransit(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        $manquantes = $t->lignes->filter(fn ($l) => $l->quantite_chargee === null);

        if ($manquantes->isNotEmpty()) {
            $errors[] = 'Toutes les lignes doivent avoir une quantité chargée renseignée.';
        }
    }

    /**
     * TRANSIT → RECEPTION : toutes les quantités reçues et types d'écart requis.
     */
    private static function checkReception(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        $incompletes = $t->lignes->filter(
            fn ($l) => $l->quantite_recue === null || $l->ecart_type === null
        );

        if ($incompletes->isNotEmpty()) {
            $errors[] = 'Toutes les lignes doivent avoir une quantité reçue et un type d\'écart renseigné.';
        }
    }

    /**
     * RECEPTION → CLOTURE : commissions entièrement versées (si existantes).
     */
    private static function checkCloture(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing(['lignes', 'commission']);

        if ($t->commission && ! $t->commission->isVersee()) {
            $errors[] = 'Les commissions logistiques doivent être entièrement versées avant la clôture.';
        }
    }
}
