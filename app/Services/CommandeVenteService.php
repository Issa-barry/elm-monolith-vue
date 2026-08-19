<?php

namespace App\Services;

use App\Enums\ClientType;
use App\Enums\ModeTarification;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\FactureVente;
use App\Services\Commission\MoteurCommissionResolver;
use App\Services\Comptabilite\VenteComptabilisationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * Crée dans le même mouvement la facture associée, en statut « Créée ».
     * Aucune commission n'est générée à ce stade : elle naît du chargement réel,
     * pas de la commande — cf. validerChargement().
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

            self::creerFactureInitiale($commande);
        });
    }

    /**
     * Crée la facture (statut CREEE) — idempotent : ne la recrée pas si déjà
     * présente.
     */
    private static function creerFactureInitiale(CommandeVente $commande): void
    {
        // load() (et non loadMissing()) : si un appel précédent sur cette même
        // instance a mis en cache une relation "facture" nulle avant sa création,
        // loadMissing() ne la rafraîchirait pas et provoquerait une double création.
        $commande->load('facture');

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

            $facture = FactureVente::create([
                'organization_id' => $commande->organization_id,
                'site_id' => $commande->site_id,
                'vehicule_id' => null,
                'commande_vente_id' => $commande->id,
                'reference' => $commande->reference,
                'montant_brut' => $commande->total_commande,
                'montant_net' => $commande->total_commande,
                'statut_facture' => StatutFactureVente::IMPAYEE,
            ]);

            self::comptabiliserVenteFacturee($facture);
        });
    }

    /**
     * Comptabilité générale, en aval — ne doit jamais empêcher une vente d'être
     * facturée (mode shadow, même principe que DepenseObserver/FicheComptabilisationService).
     */
    private static function comptabiliserVenteFacturee(FactureVente $facture): void
    {
        try {
            app(VenteComptabilisationService::class)->comptabiliserVenteFacturee($facture);
        } catch (\Throwable $e) {
            Log::error('Comptabilisation vente facturée échouée', [
                'facture_id' => $facture->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A_CHARGER → CHARGEMENT_EN_COURS.
     * La facture existe déjà depuis confirmer() ; cette étape ne fait
     * qu'avancer le statut (sécurité : recrée la facture si jamais manquante,
     * pour les commandes créées avant ce correctif).
     */
    public static function demarrerChargement(CommandeVente $commande): void
    {
        abort_if(! $commande->isACharger(), 422, 'La commande doit être en statut « À charger ».');

        DB::transaction(function () use ($commande) {
            self::creerFactureInitiale($commande);

            $commande->update([
                'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
                'chargement_demarre_at' => now(),
            ]);
        });
    }

    /**
     * CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS.
     * Enregistre les quantités chargées par ligne — quantités qui déterminent
     * définitivement le calcul de la commission de vente, quel que soit le
     * déclencheur configuré (jamais recalculée plus tard). Sous CHARGEMENT_VALIDE
     * (déclencheur par défaut), c'est ici que la commission naît, en statut
     * CREEE — elle ne devient payable qu'à la validation de la période de
     * paiement qui la couvre (cf. CommissionTriggerService::onChargementValide(),
     * CommissionAdjustmentService::activerCommissionsCreees()).
     *
     * @param  array<array{id: string, quantite_chargee?: int|null, type_ecart?: string|null, commentaire_ecart?: string|null}>  $lignesData
     */
    public static function validerChargement(CommandeVente $commande, array $lignesData = []): void
    {
        abort_if(! $commande->isChargementEnCours(), 422, 'La commande doit être en cours de chargement.');

        DB::transaction(function () use ($commande, $lignesData) {
            self::assertEquipeCommissionValide($commande);

            self::appliquerQuantitesChargees($commande, $lignesData);
            self::recalculerTotaux($commande);
            self::decrementerStock($commande);
            self::validerPreconditions($commande->fresh(), StatutCommandeVente::LIVRAISON_EN_COURS);

            $commande->update([
                'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
                'chargement_valide_at' => now(),
            ]);

            self::activerFacture($commande);
            CommissionTriggerService::onChargementValide($commande);
        });
    }

    /**
     * LEGACY UNIQUEMENT : bloque la validation du chargement si le véhicule a
     * une équipe dont la répartition des taux est invalide (≠ 100 %) — sans
     * cela, le chargement serait validé silencieusement sans qu'aucune
     * commission ne puisse être créée derrière, ce qui ne doit jamais passer
     * inaperçu. Ne bloque rien si le véhicule n'est pas éligible aux
     * commissions ou n'a pas d'équipe : dans ces cas, aucune commission n'est
     * due, ce n'est pas une erreur.
     *
     * Sans objet pour une organisation V2 : equipe_livraison.taux_commission_proprietaire
     * et equipe_livreurs.taux_commission n'y sont plus jamais maintenus (le
     * partage vit désormais dans equipe_livraison_partages_categorie) — les
     * valider ici bloquerait TOUTE validation de chargement pour ces
     * organisations. La validité du partage V2 est vérifiée à la génération
     * réelle, par catégorie, dans CommissionEnveloppeGenerator (tout-ou-rien,
     * jamais bloquant pour l'opération commerciale).
     */
    private static function assertEquipeCommissionValide(CommandeVente $commande): void
    {
        $commande->loadMissing('vehicule.equipe');
        $vehicule = $commande->vehicule;

        if (! $vehicule || ! $commande->commission_eligible_snapshot || ! $vehicule->equipe) {
            return;
        }

        if (MoteurCommissionResolver::estV2($commande->organization_id)) {
            return;
        }

        try {
            CommissionCalculator::validateTauxTotal($vehicule->equipe, (float) $vehicule->equipe->taux_commission_proprietaire);
        } catch (InvalidArgumentException $e) {
            abort(422, "Impossible de valider le chargement : {$e->getMessage()}");
        }
    }

    /**
     * Active la facture encore en statut CREEE : IMPAYEE si un montant est dû,
     * sinon PAYEE directement — pas de dette à créer pour un montant nul (ex.
     * commande entièrement annulée au chargement).
     */
    private static function activerFacture(CommandeVente $commande): void
    {
        $commande->load('facture');

        if ($commande->facture && $commande->facture->statut_facture === StatutFactureVente::CREEE) {
            $commande->facture->update([
                'statut_facture' => (float) $commande->facture->montant_net > 0
                    ? StatutFactureVente::IMPAYEE
                    : StatutFactureVente::PAYEE,
            ]);

            self::comptabiliserVenteFacturee($commande->facture);
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
                $prixUnitaire = $commande->mode_tarification_snapshot === ModeTarification::PRIX_USINE
                    ? (float) $ligne->prix_usine_snapshot
                    : (float) $ligne->prix_vente_snapshot;
                $update['total_ligne'] = $update['quantite_chargee'] * $prixUnitaire;
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
     * Décrémente le stock du site de la commande, ligne par ligne, à partir
     * des quantités réellement chargées (repli sur la quantité demandée si
     * aucun écart n'a été saisi — même convention que MouvementStockService
     * pour les transferts logistiques). Indépendant du mode de tarification :
     * la sortie physique de stock a lieu que le véhicule soit pris en charge
     * par l'usine ou non. Idempotent : ne redécrémente jamais une ligne déjà
     * traitée (le workflow ne repasse de toute façon jamais par ce statut).
     */
    private static function decrementerStock(CommandeVente $commande): void
    {
        $commande->load('lignes');
        $userId = Auth::id();

        foreach ($commande->lignes as $ligne) {
            $quantite = $ligne->quantite_chargee ?? $ligne->quantite_demandee;

            MouvementStockService::sortirStock(
                varianteId: $ligne->variante_id,
                siteId: $commande->site_id,
                orgId: $commande->organization_id,
                quantite: $quantite,
                sourceType: CommandeVenteLigne::class,
                sourceId: $ligne->id,
                userId: $userId,
            );
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
                    self::contrepasserVenteFactureeSiExistante($commande->facture, $motif);
                }
            }

            self::annulerCommissionsAssociees($commande);
        });
    }

    /**
     * Contrepasse la pièce comptable d'une facture déjà comptabilisée (statut IMPAYEE
     * atteint, cf. comptabiliserVenteFacturee()) puis annulée — sans effet si elle
     * n'avait jamais été comptabilisée (montant nul, ou échec de comptabilisation en
     * amont). Mode shadow, même principe que comptabiliserVenteFacturee().
     */
    private static function contrepasserVenteFactureeSiExistante(FactureVente $facture, string $motif): void
    {
        try {
            app(VenteComptabilisationService::class)->contrepasserVenteFactureeSiExistante($facture, 'Facture annulée — '.$motif);
        } catch (\Throwable $e) {
            Log::error('Contrepassation vente facturée (annulation) échouée', [
                'facture_id' => $facture->id,
                'error' => $e->getMessage(),
            ]);
        }
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

    /**
     * BROUILLON → A_CHARGER : au moins une ligne requise. Le véhicule n'est obligatoire que
     * hors commande externe — un client EXTERNE charge sa propre commande sans véhicule
     * de flotte (facturée à prix usine, cf. VehiculeCommandeContextResolver).
     */
    private static function checkConfirmer(CommandeVente $commande, array &$errors): void
    {
        $commande->loadMissing('lignes', 'client');

        if ($commande->lignes->isEmpty()) {
            $errors[] = 'La commande doit contenir au moins une ligne produit.';
        }

        if (! $commande->vehicule_id && $commande->client?->type !== ClientType::EXTERNE) {
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
