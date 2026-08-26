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
use App\Models\Parametre;
use App\Models\ProduitVariante;
use App\Services\Comptabilite\VenteComptabilisationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CommandeVenteService
{
    /**
     * Décide si une NOUVELLE commande peut être créée pour ce site — bouton « Nouvelle
     * commande » de la page Ventes et route de création elle-même (les deux appellent cette
     * même méthode, cf. CommandeVenteController::index()/create()/store()). Toujours vrai si
     * la politique globale autorise la vente sans stock (Parametre::
     * isVentesAutoriseesSansStock()) ; sinon délègue à StockStatutService::
     * sitePossedeStockVendable() — une EXISTENCE ("ce site a-t-il au moins un produit
     * vendable maintenant ?"), jamais une somme de quantités : un produit à +5 et un autre à
     * -5 sur le même site reste vendable, une quantité négative isolée ne l'est jamais. Un
     * garde-fou volontairement grossier, pas une garantie ligne par ligne : le contrôle fin
     * par variante reste fait au moment réel de la vente (PDV, chargement de commande), jamais
     * dupliqué ici.
     */
    public static function siteAutoriseNouvelleCommande(string $orgId, string $siteId): bool
    {
        if (Parametre::isVentesAutoriseesSansStock($orgId)) {
            return true;
        }

        return app(StockStatutService::class)->sitePossedeStockVendable($orgId, $siteId);
    }

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
            self::reserverLignes($commande);

            $commande->update([
                'statut' => StatutCommandeVente::A_CHARGER,
                'a_charger_at' => now(),
            ]);

            self::creerFactureInitiale($commande);
        });
    }

    /**
     * A_CHARGER : réserve la quantité demandée de chaque ligne — le disponible baisse dès la
     * confirmation, jamais seulement au chargement (cf. StockReservationService, correctif du
     * 24/08/2026 : avant cela, deux commandes concurrentes pouvaient toutes deux être confirmées
     * en promettant le même stock physique, le conflit n'étant détecté qu'au chargement de
     * l'une des deux — trop tard pour l'autre). Ignore les lignes dont le produit ne gère pas de
     * stock (type service) — même convention que decrementerStock(). Le contrôle de
     * disponibilité proprement dit a déjà eu lieu juste avant, dans validerPreconditions() →
     * checkDisponibiliteStock() ; StockReservationService::reserver() le REFAIT sous verrou (seul
     * endroit réellement protégé contre la concurrence), ce contrôle-ci n'est qu'un pré-filtre
     * offrant un message d'erreur groupé.
     */
    private static function reserverLignes(CommandeVente $commande): void
    {
        $commande->load('lignes.variante.produit.produitType');
        $userId = Auth::id();
        $autoriseVenteStockNegatif = Parametre::isVentesAutoriseesSansStock($commande->organization_id);

        foreach ($commande->lignes as $ligne) {
            $produit = $ligne->variante?->produit;
            if (! $produit?->produitType?->gere_stock) {
                continue;
            }

            StockReservationService::reserver(
                varianteId: $ligne->variante_id,
                siteId: $commande->site_id,
                orgId: $commande->organization_id,
                quantite: $ligne->quantite_demandee,
                sourceType: CommandeVenteLigne::class,
                sourceId: $ligne->id,
                userId: $userId,
                allowNegative: $autoriseVenteStockNegatif,
            );
        }
    }

    /**
     * Libère les réservations actives de chaque ligne (annulation avant chargement) — no-op pour
     * une ligne jamais réservée (annulation depuis BROUILLON) ou déjà consommée (n'arrive jamais
     * ici : annuler() n'est permis que depuis BROUILLON/A_CHARGER/FACTURATION, cf.
     * StatutCommandeVente::isAnnulable()).
     */
    private static function libererLignesReservees(CommandeVente $commande): void
    {
        $commande->loadMissing('lignes');

        foreach ($commande->lignes as $ligne) {
            StockReservationService::liberer(CommandeVenteLigne::class, $ligne->id, $commande->site_id, $commande->organization_id);
        }
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
            self::appliquerQuantitesChargees($commande, $lignesData);
            self::recalculerTotaux($commande);
            // validerPreconditions() (→ checkDisponibiliteStock()) DOIT s'exécuter avant
            // decrementerStock() : sinon le stock serait déjà décrémenté (ou l'exception déjà
            // levée depuis l'intérieur de MouvementStockService::appliquer(), sans jamais
            // atteindre ce contrôle) avant même d'avoir pu vérifier la disponibilité ligne par
            // ligne avec un message d'erreur groupé.
            self::validerPreconditions($commande->fresh(), StatutCommandeVente::LIVRAISON_EN_COURS);
            self::decrementerStock($commande);

            $commande->update([
                'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
                'chargement_valide_at' => now(),
            ]);

            self::activerFacture($commande);
            CommissionTriggerService::onChargementValide($commande);
        });
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
     *
     * Ignore les lignes dont le produit ne gère pas de stock (type service) — même
     * convention que PdvCheckoutService::buildLignes(), qui ne les fait jamais transiter
     * par MouvementStockService.
     *
     * allowNegative (cf. MouvementStockService::appliquer()) suit la politique globale
     * d'organisation (Parametre::isVentesAutoriseesSansStock()), lue une seule fois — jamais
     * par produit : c'est checkDisponibiliteStock() qui a déjà statué, en amont, sur ce qui
     * est autorisé.
     */
    private static function decrementerStock(CommandeVente $commande): void
    {
        $commande->load('lignes.variante.produit.produitType');
        $userId = Auth::id();
        $autoriseVenteStockNegatif = Parametre::isVentesAutoriseesSansStock($commande->organization_id);

        foreach ($commande->lignes as $ligne) {
            $produit = $ligne->variante?->produit;
            if (! $produit?->produitType?->gere_stock) {
                continue;
            }

            $quantite = $ligne->quantite_chargee ?? $ligne->quantite_demandee;

            // Consomme la réservation (créée à la confirmation) AVANT le décrément physique :
            // sinon le garde-fou "le stock physique ne descend jamais sous le réservé" de
            // MouvementStockService::appliquer() rejetterait à tort la propre consommation de
            // cette réservation. Libère intégralement la réservation quelle que soit la quantité
            // réellement chargée — un écart négatif (moins chargé que demandé) rend
            // automatiquement le surplus non chargé disponible pour d'autres commandes (cf.
            // StockReservationService::consommer()).
            StockReservationService::consommer(CommandeVenteLigne::class, $ligne->id, $commande->site_id, $commande->organization_id);

            MouvementStockService::sortirStock(
                varianteId: $ligne->variante_id,
                siteId: $commande->site_id,
                orgId: $commande->organization_id,
                quantite: $quantite,
                sourceType: CommandeVenteLigne::class,
                sourceId: $ligne->id,
                userId: $userId,
                allowNegative: $autoriseVenteStockNegatif,
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
     * Annuler — depuis BROUILLON, A_CHARGER ou FACTURATION (vente directe non encaissée). La
     * facture associée est également annulée si elle existe, quel que soit le statut de la
     * commande (flotte ou vente directe) : le garde-fou ci-dessous garantit déjà qu'elle n'a
     * reçu aucun encaissement. Sans cela, une commande flotte annulée depuis A_CHARGER
     * laisserait sa facture orpheline en statut CREEE (0 encaissé, solde > 0) — qui bloquerait
     * alors indéfiniment le véhicule pour toute nouvelle commande, cf.
     * SolvabiliteService::premiereFactureNonEncaisseeVehicule() (correctif du 20/08/2026, avant
     * cela seul le chemin vente directe annulait la facture).
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

        DB::transaction(function () use ($commande, $motif) {
            $commande->update([
                'statut' => StatutCommandeVente::ANNULEE,
                'motif_annulation' => $motif,
                'annulee_at' => now(),
                'annulee_par' => Auth::id(),
            ]);

            self::libererLignesReservees($commande);

            $commande->loadMissing('facture');
            if ($commande->facture && ! $commande->facture->isAnnulee() && ! $commande->facture->isPayee()) {
                $commande->facture->update(['statut_facture' => StatutFactureVente::ANNULEE]);
                self::contrepasserVenteFactureeSiExistante($commande->facture, $motif);
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
     * de flotte (facturée à prix usine, cf. VehiculeCommandeContextResolver). Recontrôle aussi
     * la disponibilité (24/08/2026, cf. reserverLignes()) : le stock a pu changer depuis la
     * création du brouillon (une autre commande confirmée entre-temps a pu le réserver) — jamais
     * suffisant de ne compter que sur le contrôle fait à la création (CommandeVenteController::
     * store()/update()).
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

        self::checkDisponibiliteStock($commande, $errors);
    }

    /**
     * CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS : toutes les quantités chargées renseignées,
     * puis (uniquement si c'est le cas) chaque ligne vérifiée contre le stock disponible du
     * site — cf. checkDisponibiliteStock().
     */
    private static function checkValiderChargement(CommandeVente $commande, array &$errors): void
    {
        $commande->loadMissing('lignes');

        $manquantes = $commande->lignes->filter(fn ($l) => $l->quantite_chargee === null);

        if ($manquantes->isNotEmpty()) {
            $errors[] = 'Toutes les lignes doivent avoir une quantité chargée renseignée.';

            return;
        }

        self::checkDisponibiliteStock($commande, $errors);
    }

    /**
     * Vérifie, ligne par ligne et sur le site de la commande, que la quantité chargée ne
     * dépasse pas le stock disponible — cf. verifierDisponibiliteLignes() ci-dessous, point
     * d'entrée unique réutilisé par CommandeVenteController::store()/update() (création et
     * modification, 24/08/2026), checkConfirmer() (confirmation — réservation) ET ce contrôle au
     * chargement. Le stock a pu changer entre chaque étape (autre vente entre-temps,
     * ajustement...) : chaque contrôle reste indispensable même si le précédent a déjà validé la
     * commande à son étape.
     *
     * 'ligne_id' est transmis à chaque appel (BROUILLON compris) : verifierDisponibiliteLignes()
     * l'utilise pour rendre à la ligne sa PROPRE réservation active — sans effet tant qu'aucune
     * réservation n'existe encore (BROUILLON, avant confirmer()), indispensable dès A_CHARGER
     * pour ne jamais bloquer une commande sur SA propre réservation.
     */
    private static function checkDisponibiliteStock(CommandeVente $commande, array &$errors): void
    {
        $commande->loadMissing('lignes');

        $lignes = $commande->lignes->map(fn (CommandeVenteLigne $l) => [
            'ligne_id' => $l->id,
            'variante_id' => $l->variante_id,
            'quantite' => $l->quantite_chargee ?? $l->quantite_demandee,
        ])->all();

        self::verifierDisponibiliteLignes($commande->organization_id, $commande->site_id, $lignes, $errors);
    }

    /**
     * Cœur RÉUTILISABLE du contrôle de disponibilité — jamais dupliqué en logique dans les
     * contrôleurs. Vérifie que chaque ligne (variante_id => quantité) ne dépasse pas le stock
     * disponible du site donné, sauf si la politique globale d'organisation autorise
     * explicitement la vente au-delà du disponible (Parametre::isVentesAutoriseesSansStock(),
     * paramètre DSI, réservé au PDV et aux commandes vente — jamais aux transferts/
     * ajustements, et jamais un réglage par produit). Appelée par :
     *  - CommandeVenteController::store()/update() (création/modification d'une commande,
     *    24/08/2026 — avant cette date, une commande pouvait être créée avec une quantité
     *    supérieure au stock, le seul contrôle existant était au chargement) ;
     *  - checkDisponibiliteStock() ci-dessus (chargement) ;
     *  - PdvCheckoutService::buildLignes() reste néanmoins un contrôle SÉPARÉ (verrouillage
     *    lockForUpdate() + vente comptoir immédiate, pas de brouillon à valider plus tard) —
     *    jamais dupliqué en RÈGLE (même Parametre, même MouvementStockService::
     *    quantiteDisponible()), seulement en mécanique d'appel.
     * Ignore les lignes dont le produit ne gère pas de stock (type service).
     *
     * MouvementStockService::quantiteDisponible() nette désormais le réservé de TOUTES les
     * sources (StockReservationService, 24/08/2026) — y compris la propre réservation de la
     * ligne en cours de recontrôle. Quand 'ligne_id' est fourni, sa réservation active est
     * rajoutée au disponible avant comparaison : une commande ne doit jamais être bloquée par SA
     * PROPRE réservation (ex : recontrôle au chargement, la ligne détient déjà sa réservation
     * depuis confirmer()). Absent (création/modification d'un brouillon, où aucune réservation
     * n'existe encore) : aucun effet, la propre réservation vaut 0.
     *
     * @param  array<int, array{ligne_id?: string, variante_id: string, quantite: int}>  $lignes
     * @param  array<int, string>  $errors  Passé par référence, une entrée par ligne en anomalie.
     */
    public static function verifierDisponibiliteLignes(string $orgId, string $siteId, array $lignes, array &$errors): void
    {
        if (Parametre::isVentesAutoriseesSansStock($orgId)) {
            return;
        }

        $varianteIds = array_column($lignes, 'variante_id');
        $variantes = ProduitVariante::with('produit.produitType')
            ->whereIn('id', $varianteIds)
            ->get()
            ->keyBy('id');

        foreach ($lignes as $ligne) {
            $variante = $variantes->get($ligne['variante_id']);
            $produit = $variante?->produit;
            if (! $produit?->produitType?->gere_stock) {
                continue;
            }

            $disponible = MouvementStockService::quantiteDisponible($ligne['variante_id'], $siteId);

            if (! empty($ligne['ligne_id'])) {
                $disponible += StockReservationService::quantiteReserveeActivePourSource(CommandeVenteLigne::class, $ligne['ligne_id'], $siteId, $orgId);
            }

            if ($ligne['quantite'] > $disponible) {
                $errors[] = "Stock insuffisant pour « {$produit->nom} » : {$ligne['quantite']} demandés, {$disponible} disponibles.";
            }
        }
    }
}
