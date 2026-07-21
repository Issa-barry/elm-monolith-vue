<?php

namespace App\Services;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
     * Crée dans le même mouvement la facture et les commissions associées,
     * en statut « Créée » (basées sur les quantités demandées) : elles existent
     * dès la commande mais ne deviennent encaissables/payables qu'à la validation
     * du chargement (cf. validerChargement()).
     */
    public static function confirmer(CommandeVente $commande): void
    {
        abort_if(! $commande->isBrouillon(), 422, 'Seule une commande en brouillon peut être confirmée.');

        self::validerPreconditions($commande, StatutCommandeVente::A_CHARGER);

        DB::transaction(function () use ($commande) {
            $commande->update([
                'statut' => StatutCommandeVente::A_CHARGER,
                'a_charger_at' => now(),
            ]);

            self::creerFactureEtCommissionsInitiales($commande);
        });
    }

    /**
     * Crée la facture (statut CREEE) et, si le véhicule a une équipe, les
     * commissions (statut CREEE) — idempotent : ne recrée rien si déjà présent.
     */
    private static function creerFactureEtCommissionsInitiales(CommandeVente $commande): void
    {
        // load() (et non loadMissing()) : si un appel précédent sur cette même
        // instance a mis en cache une relation "facture" nulle avant sa création,
        // loadMissing() ne la rafraîchirait pas et provoquerait une double création.
        $commande->load('facture', 'vehicule');

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

        if ($commande->vehicule_id && $commande->vehicule) {
            CommissionGenerator::generateForCommandeIfMissing($commande);
        }
    }

    /**
     * Vente directe client (sans véhicule) : BROUILLON → FACTURATION + création facture.
     * Aucune commission n'est générée.
     */
    public static function creerFactureDirecte(CommandeVente $commande): void
    {
        abort_if($commande->vehicule_id, 422, 'Cette méthode est réservée aux commandes sans véhicule.');
        abort_if(! $commande->isBrouillon(), 422, 'Seule une commande en brouillon peut être traitée.');

        $commande->loadMissing('lignes');

        if ($commande->lignes->isEmpty()) {
            throw ValidationException::withMessages([
                'lignes' => 'La commande doit contenir au moins une ligne produit.',
            ]);
        }

        DB::transaction(function () use ($commande) {
            $commande->update([
                'statut' => StatutCommandeVente::FACTURATION,
            ]);

            FactureVente::create([
                'organization_id' => $commande->organization_id,
                'site_id' => $commande->site_id,
                'vehicule_id' => null,
                'commande_vente_id' => $commande->id,
                'reference' => $commande->reference,
                'montant_brut' => $commande->total_commande,
                'montant_net' => $commande->total_commande,
                'statut_facture' => StatutFactureVente::IMPAYEE,
            ]);
        });
    }

    /**
     * A_CHARGER → CHARGEMENT_EN_COURS.
     * La facture et les commissions existent déjà depuis confirmer() ; cette
     * étape ne fait qu'avancer le statut (sécurité : recrée si jamais manquant,
     * pour les commandes créées avant ce correctif).
     */
    public static function demarrerChargement(CommandeVente $commande): void
    {
        abort_if(! $commande->isACharger(), 422, 'La commande doit être en statut « À charger ».');

        DB::transaction(function () use ($commande) {
            self::creerFactureEtCommissionsInitiales($commande);

            $commande->update([
                'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
                'chargement_demarre_at' => now(),
            ]);
        });
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
            self::recalculerCommissions($commande);
            self::validerPreconditions($commande->fresh(), StatutCommandeVente::LIVRAISON_EN_COURS);

            $commande->update([
                'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
                'chargement_valide_at' => now(),
            ]);

            self::activerFactureEtCommissions($commande);
        });
    }

    /**
     * Recalcule les commissions (totale + part de chaque membre actif de
     * l'équipe — chauffeur, convoyeur, etc.) à partir des quantités réellement
     * chargées. Met à jour les enregistrements existants (idempotent — ne
     * crée jamais de doublon).
     */
    private static function recalculerCommissions(CommandeVente $commande): void
    {
        $commande->load('vehicule.equipe.membres.livreur', 'vehicule.proprietaire', 'lignes', 'commissions.parts');

        if (! $commande->vehicule || ! $commande->vehicule->equipe) {
            return;
        }

        try {
            $calc = CommissionCalculator::fromCommande($commande);
        } catch (InvalidArgumentException) {
            return;
        }

        $commission = $commande->commissions->first();
        if (! $commission) {
            return;
        }

        $commission->montant_commande = (float) $commande->total_commande;
        $commission->montant_commission_totale = $calc['commission_totale'];
        $commission->saveQuietly();

        foreach ($calc['parts'] as $partData) {
            $part = $commission->parts->first(fn ($p) => $partData['type_beneficiaire'] === 'livreur'
                ? ($p->type_beneficiaire === 'livreur' && $p->livreur_id === $partData['livreur_id'])
                : ($p->type_beneficiaire === 'proprietaire' && $p->proprietaire_id === $partData['proprietaire_id']));

            if (! $part) {
                continue;
            }

            $part->taux_commission = $partData['taux_commission'];
            $part->montant_brut = $partData['montant_brut'];
            $part->montant_net = max(0.0, round($partData['montant_brut'] - (float) $part->frais_supplementaires, 2));
            $part->saveQuietly();
        }
    }

    /**
     * Active la facture et les commissions encore en statut CREEE :
     * IMPAYE(E) si un montant est dû, sinon PAYE(E) directement — pas de
     * dette à créer pour un montant nul (ex. commande entièrement annulée
     * au chargement).
     */
    private static function activerFactureEtCommissions(CommandeVente $commande): void
    {
        $commande->load('facture', 'commissions.parts');

        if ($commande->facture && $commande->facture->statut_facture === StatutFactureVente::CREEE) {
            $commande->facture->update([
                'statut_facture' => (float) $commande->facture->montant_net > 0
                    ? StatutFactureVente::IMPAYEE
                    : StatutFactureVente::PAYEE,
            ]);
        }

        foreach ($commande->commissions as $commission) {
            if ($commission->statut !== StatutCommission::CREEE) {
                continue;
            }

            $commission->update([
                'statut' => (float) $commission->montant_commission_totale > 0
                    ? StatutCommission::IMPAYE
                    : StatutCommission::PAYE,
            ]);

            foreach ($commission->parts as $part) {
                if ($part->statut !== StatutCommission::CREEE) {
                    continue;
                }

                $part->update([
                    'statut' => (float) $part->montant_net > 0
                        ? StatutCommission::IMPAYE
                        : StatutCommission::PAYE,
                ]);
            }
        }
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
     * Annuler — depuis BROUILLON, A_CHARGER ou FACTURATION (vente directe non encaissée).
     * Pour les commandes FACTURATION, la facture associée est également annulée.
     */
    public static function annuler(CommandeVente $commande, string $motif): void
    {
        abort_if($commande->isAnnulee(), 422, 'Cette commande est déjà annulée.');
        abort_if(
            ! $commande->statut->isAnnulable(),
            422,
            'L\'annulation n\'est possible que depuis les statuts « Brouillon », « À charger » ou « Facturation ».'
        );

        $commande->loadMissing('facture');
        abort_if(
            $commande->facture && (float) $commande->facture->montant_encaisse > 0,
            422,
            'Impossible d\'annuler une commande ayant reçu au moins un encaissement.'
        );

        $estDirecte = $commande->isFacturation();

        DB::transaction(function () use ($commande, $motif, $estDirecte) {
            $commande->update([
                'statut' => StatutCommandeVente::ANNULEE,
                'motif_annulation' => $motif,
                'annulee_at' => now(),
                'annulee_par' => Auth::id(),
            ]);

            if ($estDirecte) {
                $commande->loadMissing('facture');
                if ($commande->facture && ! $commande->facture->isAnnulee() && ! $commande->facture->isPayee()) {
                    $commande->facture->update(['statut_facture' => StatutFactureVente::ANNULEE]);
                }
            }

            self::annulerCommissionsAssociees($commande);
        });
    }

    /**
     * Annuler une commande annule ses commissions non encore soldées : une part déjà
     * payée n'est jamais reprise (historique de paiement conservé tel quel).
     */
    private static function annulerCommissionsAssociees(CommandeVente $commande): void
    {
        foreach ($commande->commissions as $commission) {
            $commission->parts()
                ->whereNotIn('statut', [StatutCommission::PAYE->value, StatutCommission::ANNULEE->value])
                ->update(['statut' => StatutCommission::ANNULEE->value]);

            if ($commission->statut !== StatutCommission::PAYE) {
                $commission->update(['statut' => StatutCommission::ANNULEE->value]);
            }
        }
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
