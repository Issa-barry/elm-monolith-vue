<?php

namespace App\Services;

use App\Enums\StatutTransfert;
use App\Models\TransfertLogistique;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransfertLogistiqueService
{
    /**
     * Faire avancer le statut d'un transfert vers l'étape suivante.
     *
     * @throws ValidationException si les pré-conditions de la transition ne sont pas satisfaites
     */
    public static function avancerStatut(TransfertLogistique $transfert): TransfertLogistique
    {
        $suivant = match ($transfert->statut) {
            StatutTransfert::BROUILLON   => StatutTransfert::PREPARATION,
            StatutTransfert::PREPARATION => StatutTransfert::CHARGEMENT,
            StatutTransfert::CHARGEMENT  => StatutTransfert::TRANSIT,
            StatutTransfert::TRANSIT     => StatutTransfert::RECEPTION,
            StatutTransfert::RECEPTION   => StatutTransfert::CLOTURE,
            default                      => null,
        };

        if ($suivant === null) {
            return $transfert;
        }

        // Valider les pré-conditions avant toute transaction
        self::validerPreconditions($transfert, $suivant);

        DB::transaction(function () use ($transfert, $suivant) {
            $now     = now();
            $updates = ['statut' => $suivant->value];

            // Départ réel enregistré au passage en TRANSIT
            if ($suivant === StatutTransfert::TRANSIT) {
                $updates['date_depart_reelle'] = $now->toDateString();
            }

            // Arrivée réelle + mouvements stock destination à la CLÔTURE
            if ($suivant === StatutTransfert::CLOTURE) {
                $updates['date_arrivee_reelle'] = $now->toDateString();
                MouvementStockService::enregistrerEntreeDestination($transfert);
            }

            $transfert->update($updates);

            // Mouvements stock source au passage en RECEPTION (produits physiquement partis)
            if ($suivant === StatutTransfert::RECEPTION) {
                MouvementStockService::enregistrerSortieSource($transfert);
            }
        });

        return $transfert->fresh();
    }

    /**
     * Annuler un transfert (si pas encore en état terminal).
     */
    public static function annuler(TransfertLogistique $transfert): TransfertLogistique
    {
        if ($transfert->isTerminal()) {
            return $transfert;
        }

        $transfert->update(['statut' => StatutTransfert::ANNULE->value]);

        return $transfert->fresh();
    }

    // ── Pré-conditions ────────────────────────────────────────────────────────

    /**
     * Valide les pré-conditions métier avant une transition de statut.
     *
     * @throws ValidationException
     */
    public static function validerPreconditions(TransfertLogistique $transfert, StatutTransfert $cible): void
    {
        $errors = [];

        match ($cible) {
            StatutTransfert::PREPARATION => self::checkPreparation($transfert, $errors),
            StatutTransfert::CHARGEMENT  => self::checkChargement($transfert, $errors),
            StatutTransfert::TRANSIT     => self::checkTransit($transfert, $errors),
            StatutTransfert::RECEPTION   => self::checkReception($transfert, $errors),
            StatutTransfert::CLOTURE     => self::checkCloture($transfert, $errors),
            default                      => null,
        };

        if (! empty($errors)) {
            throw ValidationException::withMessages(['statut' => $errors]);
        }
    }

    private static function checkPreparation(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        if ($t->lignes->isEmpty()) {
            $errors[] = 'Le transfert doit contenir au moins une ligne produit.';
        }

        if (! $t->vehicule_id) {
            $errors[] = 'Un véhicule doit être assigné avant de démarrer la préparation.';
        }
    }

    private static function checkChargement(TransfertLogistique $t, array &$errors): void
    {
        if (! $t->equipe_livraison_id) {
            $errors[] = 'Une équipe de livraison doit être assignée avant le chargement.';
        }

        if (! $t->date_depart_prevue) {
            $errors[] = 'La date de départ prévue doit être renseignée.';
        }
    }

    private static function checkTransit(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        $lignesSansChargement = $t->lignes->filter(
            fn ($l) => $l->quantite_chargee === null
        );

        if ($lignesSansChargement->isNotEmpty()) {
            $errors[] = 'Toutes les lignes doivent avoir une quantité chargée renseignée.';
        }
    }

    private static function checkReception(TransfertLogistique $t, array &$errors): void
    {
        if (! $t->date_depart_reelle) {
            $errors[] = 'La date de départ réelle doit être renseignée.';
        }
    }

    private static function checkCloture(TransfertLogistique $t, array &$errors): void
    {
        $t->loadMissing('lignes');

        $lignesIncompletes = $t->lignes->filter(
            fn ($l) => ! $l->estReceptionComplete()
        );

        if ($lignesIncompletes->isNotEmpty()) {
            $errors[] = 'Toutes les lignes doivent avoir une quantité reçue et un type d\'écart renseigné.';
        }
    }
}
