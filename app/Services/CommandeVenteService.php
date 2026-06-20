<?php

namespace App\Services;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Parametre;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommandeVenteService
{
    /**
     * Workflow : BROUILLON → A_CHARGER → CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS → LIVREE → CLOTUREE
     *            ↘ ANNULEE (depuis BROUILLON ou A_CHARGER seulement)
     *
     * @throws ValidationException si les pré-conditions ne sont pas satisfaites
     */
    public static function avancerStatut(CommandeVente $commande, array $lignesData = []): CommandeVente
    {
        match ($commande->statut) {
            StatutCommandeVente::BROUILLON => self::confirmer($commande),
            StatutCommandeVente::A_CHARGER => self::demarrerChargement($commande),
            StatutCommandeVente::CHARGEMENT_EN_COURS => self::validerChargement($commande, $lignesData),
            default => abort(422, 'Impossible d\'avancer depuis ce statut.'),
        };

        return $commande->fresh();
    }

    // ── Transitions ───────────────────────────────────────────────────────────

    /**
     * BROUILLON → A_CHARGER.
     * Vérifie qu'il y a au moins une ligne et (optionnellement) un véhicule.
     */
    public static function confirmer(CommandeVente $commande): void
    {
        abort_if(! $commande->isBrouillon(), 422, 'Seule une commande en brouillon peut être confirmée.');

        self::validerPreconditions($commande, StatutCommandeVente::A_CHARGER);

        $commande->update([
            'statut' => StatutCommandeVente::A_CHARGER,
            'a_charger_at' => now(),
        ]);
    }

    /**
     * A_CHARGER → CHARGEMENT_EN_COURS.
     * Crée la facture et déclenche la commission si le mode est "commande validée".
     */
    public static function demarrerChargement(CommandeVente $commande): void
    {
        abort_if(! $commande->isACharger(), 422, 'La commande doit être en statut « À charger ».');

        DB::transaction(function () use ($commande) {
            if (! $commande->relationLoaded('facture')) {
                $commande->load('facture');
            }

            if (! $commande->facture) {
                FactureVente::create([
                    'organization_id' => $commande->organization_id,
                    'site_id' => $commande->site_id,
                    'vehicule_id' => $commande->vehicule_id,
                    'commande_vente_id' => $commande->id,
                    'reference' => $commande->reference,
                    'montant_brut' => $commande->total_commande,
                    'montant_net' => $commande->total_commande,
                ]);
            }

            $commande->update([
                'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
                'chargement_demarre_at' => now(),
            ]);
        });

        // Commission au mode "commande validée" (déclenchée au lancement du chargement)
        $modeCommission = Parametre::getVentesCommissionMode($commande->organization_id);
        $commande->loadMissing('vehicule');

        if (
            $modeCommission === Parametre::COMMISSION_MODE_COMMANDE_VALIDEE
            && $commande->vehicule_id
            && $commande->vehicule
        ) {
            CommissionGenerator::generateForCommandeIfMissing(
                $commande,
                null,
                Parametre::COMMISSION_MODE_COMMANDE_VALIDEE
            );
        }
    }

    /**
     * CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS.
     * Enregistre les quantités chargées par ligne.
     *
     * @param  array<array{id: string, quantite_chargee?: int|null, type_ecart?: string|null, commentaire_ecart?: string|null}>  $lignesData
     */
    public static function validerChargement(CommandeVente $commande, array $lignesData = []): void
    {
        abort_if(! $commande->isChargementEnCours(), 422, 'La commande doit être en cours de chargement.');

        DB::transaction(function () use ($commande, $lignesData) {
            self::appliquerQuantitesChargees($commande, $lignesData);
            self::recalculerTotaux($commande);
            self::validerPreconditions($commande->fresh(), StatutCommandeVente::LIVRAISON_EN_COURS);

            $commande->update([
                'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
                'chargement_valide_at' => now(),
            ]);
        });
    }

    private static function appliquerQuantitesChargees(CommandeVente $commande, array $lignesData): void
    {
        if (empty($lignesData)) {
            return;
        }

        $commande->loadMissing('lignes');

        foreach ($lignesData as $ligneData) {
            $ligne = $commande->lignes->find($ligneData['id'] ?? null);
            if (! $ligne) {
                continue;
            }

            $update = array_intersect_key($ligneData, array_flip([
                'quantite_chargee',
                'type_ecart',
                'commentaire_ecart',
            ]));

            if (array_key_exists('quantite_chargee', $update) && $update['quantite_chargee'] !== null) {
                $update['total_ligne'] = $update['quantite_chargee'] * (float) $ligne->prix_vente_snapshot;
            }

            if (! empty($update)) {
                $ligne->update($update);
            }
        }
    }

    /**
     * Recalcule le total de la commande à partir des lignes (quantités réellement chargées)
     * et répercute le nouveau montant sur la facture associée si elle existe.
     */
    private static function recalculerTotaux(CommandeVente $commande): void
    {
        $commande->load('lignes', 'facture');

        $totalCommande = (float) $commande->lignes->sum('total_ligne');

        $commande->update(['total_commande' => $totalCommande]);

        if ($commande->facture) {
            $commande->facture->update([
                'montant_brut' => $totalCommande,
                'montant_net' => $totalCommande,
            ]);
            $commande->facture->recalculStatut();
        }
    }

    /**
     * LIVRAISON_EN_COURS → LIVREE.
     * Déclenché automatiquement au premier encaissement.
     */
    public static function passerEnLivree(CommandeVente $commande): void
    {
        abort_if(! $commande->isLivraisonEnCours(), 422, 'La commande doit être en livraison.');

        $commande->update([
            'statut' => StatutCommandeVente::LIVREE,
            'livree_at' => now(),
        ]);
    }

    /**
     * Annuler — uniquement depuis BROUILLON ou A_CHARGER.
     * Aucune facture n'existe encore à ces stades.
     */
    public static function annuler(CommandeVente $commande, string $motif): void
    {
        abort_if($commande->isAnnulee(), 422, 'Cette commande est déjà annulée.');
        abort_if(
            ! $commande->statut->isAnnulable(),
            422,
            'L\'annulation n\'est possible que depuis les statuts « Brouillon » ou « À charger ».'
        );

        $commande->update([
            'statut' => StatutCommandeVente::ANNULEE,
            'motif_annulation' => $motif,
            'annulee_at' => now(),
            'annulee_par' => Auth::id(),
        ]);
    }

    // ── Pré-conditions ────────────────────────────────────────────────────────

    public static function validerPreconditions(CommandeVente $commande, StatutCommandeVente $cible): void
    {
        $errors = [];

        match ($cible) {
            StatutCommandeVente::A_CHARGER => self::checkConfirmer($commande, $errors),
            StatutCommandeVente::LIVRAISON_EN_COURS => self::checkValiderChargement($commande, $errors),
            default => null,
        };

        if (! empty($errors)) {
            throw ValidationException::withMessages(['statut' => $errors]);
        }
    }

    /** BROUILLON → A_CHARGER : au moins une ligne + véhicule requis. */
    private static function checkConfirmer(CommandeVente $commande, array &$errors): void
    {
        $commande->loadMissing('lignes');

        if ($commande->lignes->isEmpty()) {
            $errors[] = 'La commande doit contenir au moins une ligne produit.';
        }

        if (! $commande->vehicule_id) {
            $errors[] = 'Un véhicule doit être assigné avant de confirmer la commande.';
        }
    }

    /** CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS : toutes les quantités chargées renseignées. */
    private static function checkValiderChargement(CommandeVente $commande, array &$errors): void
    {
        $commande->loadMissing('lignes');

        $manquantes = $commande->lignes->filter(fn ($l) => $l->quantite_chargee === null);

        if ($manquantes->isNotEmpty()) {
            $errors[] = 'Toutes les lignes doivent avoir une quantité chargée renseignée.';
        }
    }
}
