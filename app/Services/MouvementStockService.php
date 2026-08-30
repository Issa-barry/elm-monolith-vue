<?php

namespace App\Services;

use App\Enums\StockStatut;
use App\Models\MouvementStock;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\VarianteStock;
use App\Notifications\StockAlerteNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Gestion des mouvements de stock, ventilés par site. Point d'entrée unique
 * (appliquer()) pour toute mutation de VarianteStock, afin qu'un seul chemin de
 * calcul (stock_avant/stock_apres, resynchronisation de l'agrégat produit,
 * traçabilité MouvementStock) existe dans toute l'application.
 *
 * Règle invariante : une ligne VarianteStock nouvellement créée démarre TOUJOURS
 * à 0. Le stock legacy (Produit::qte_stock, hérité de l'ancien modèle mono-stock
 * sans notion de site) n'est jamais recopié implicitement sur le premier site
 * touché — quel que soit le flux (ajustement manuel, vente, réception, transfert).
 * L'ordre dans lequel les agences sont touchées ne doit jamais décider de quelle
 * agence « possède » un stock historique non ventilé.
 */
class MouvementStockService
{
    /**
     * Primitive unique de mutation du stock par site. Verrouille (lockForUpdate)
     * la ligne VarianteStock concernée — tous les appelants s'exécutent déjà dans
     * une DB::transaction — l'initialise à 0 si absente, applique le delta,
     * resynchronise Produit::qte_stock, et trace un MouvementStock avec
     * stock_avant/stock_apres complets.
     *
     * $allowNegative gouverne ce qui se passe quand une sortie dépasse le stock
     * disponible : soit elle est refusée EN ENTIER avant toute écriture (défaut —
     * aucun mouvement créé, stock inchangé), soit elle est appliquée EN ENTIER,
     * quitte à faire passer le stock sous 0 (cf. Produit::autorise_vente_stock_negatif).
     * Il n'existe plus de troisième voie : jamais de clamp silencieux à 0 qui
     * n'appliquerait qu'une partie du delta demandé (cf. audit stock du 23/08/2026 —
     * un mouvement dont le calcul stock_avant/delta/stock_apres ne correspond plus
     * à la réalité rend le journal non réconciliable). Le contrôle est fait ICI,
     * sous le verrou — jamais seulement par un pré-contrôle dans l'appelant, qui
     * laisserait une fenêtre de concurrence (cf. faille TOCTOU relevée sur
     * ProduitController::ajusterStock() avant ce correctif) ou pourrait être
     * contourné par un appel direct au service.
     *
     * @throws ValidationException si la sortie dépasse le disponible et $allowNegative est faux
     */
    public static function appliquer(
        string $varianteId,
        string $siteId,
        string $orgId,
        string $type, // 'entree' | 'sortie'
        int $quantite,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $userId = null,
        ?string $notes = null,
        bool $allowNegative = false,
    ): MouvementStock {
        // VarianteStock::lockOuCreer() (24-25/08/2026) : récupère la ligne sous verrou, ou la
        // matérialise à 0 si c'est le tout premier mouvement pour cette variante × site — de
        // façon protégée contre la concurrence (cf. docblock de la méthode). Conséquence
        // assumée : un refus juste après (sortie insuffisante) peut laisser une ligne à 0 en
        // base là où rien n'existait avant — observationnellement IDENTIQUE à "aucune ligne"
        // pour tout le reste de l'application (quantiteDisponible(), affichages, filtres
        // COALESCE(...,0)) : la priorité va à l'absence de course, pas à l'absence de ligne.
        $varianteStock = VarianteStock::lockOuCreer($varianteId, $siteId, $orgId);

        $stockAvant = $varianteStock->qte_stock;
        $qteReservee = $varianteStock->qte_reservee;
        $delta = $type === 'entree' ? $quantite : -$quantite;
        $stockApres = $stockAvant + $delta;

        // Le plancher est qte_reservee (jamais juste 0) depuis l'introduction de
        // StockReservationService (24/08/2026) : une sortie ne doit jamais faire passer le stock
        // physique sous ce qui est activement promis à d'autres commandes confirmées — sauf
        // quand cette sortie EST la consommation de cette réservation, auquel cas l'appelant a
        // déjà libéré la réservation concernée avant d'appeler appliquer() (cf.
        // StockReservationService::consommer(), CommandeVenteService::decrementerStock()).
        if ($type === 'sortie' && $stockApres < $qteReservee && ! $allowNegative) {
            throw ValidationException::withMessages([
                'stock' => "Stock insuffisant : {$quantite} demandés, ".($stockAvant - $qteReservee).' disponibles.',
            ]);
        }

        $varianteStock->update(['qte_stock' => $stockApres]);

        $produit = ProduitVariante::with('produit.produitType')->find($varianteId)?->produit;
        $produit?->resynchroniserQteStock();

        $mouvement = MouvementStock::create([
            'organization_id' => $orgId,
            'site_id' => $siteId,
            'produit_variante_id' => $varianteId,
            'type' => $type,
            'quantite' => $quantite,
            'stock_avant' => $stockAvant,
            'stock_apres' => $stockApres,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'notes' => $notes,
            'created_by' => $userId,
        ]);

        if ($produit) {
            self::alerterSiFranchissementSeuil($produit, $siteId, $stockAvant, $stockApres, $qteReservee);
        }

        return $mouvement;
    }

    /**
     * Alerte les administrateurs (super_admin/admin_entreprise) de l'organisation quand ce
     * mouvement fait FRANCHIR le seuil d'alerte (transition Disponible → Faible/Rupture/Négatif)
     * pour ce couple produit × site — jamais à chaque mouvement tant que le produit reste sous le
     * seuil (cf. docblock StockAlerteNotification). Toujours envoyée à TOUS les administrateurs
     * de l'organisation, sans restriction d'agence (décision produit 30/08/2026) — jamais
     * seulement ceux rattachés au site concerné.
     *
     * Ne doit JAMAIS interrompre le mouvement de stock ni l'opération métier appelante : toute
     * erreur (mail indisponible, etc.) est avalée et journalisée, même garantie que
     * CommissionEnveloppeGenerator::alerterCommissionManquante().
     */
    private static function alerterSiFranchissementSeuil(
        Produit $produit,
        string $siteId,
        int $stockAvant,
        int $stockApres,
        int $qteReservee,
    ): void {
        if (! $produit->produitType?->gere_stock) {
            return;
        }

        // Interrupteur d'organisation existant (Paramètres > Général > "Alertes de stock
        // faible", cf. Parametre::CLE_NOTIFICATIONS_STOCK_ACTIVES) — jamais dupliqué par un
        // second réglage : quand un admin le désactive, plus aucun email d'alerte stock ne
        // doit partir pour son organisation, quel que soit le produit/site concerné.
        if (! Parametre::isNotificationsStockActives($produit->organization_id)) {
            return;
        }

        try {
            $stockStatutService = app(StockStatutService::class);
            $seuil = $stockStatutService->seuilEffectifPourSite($produit, $siteId);
            $alerteActive = (bool) $produit->alerte_stock_active;

            $statutAvant = $stockStatutService->statutPour($stockAvant - $qteReservee, $seuil, $alerteActive);
            $statutApres = $stockStatutService->statutPour($stockApres - $qteReservee, $seuil, $alerteActive);

            $etaitEnAlerte = $statutAvant !== StockStatut::DISPONIBLE;
            $estEnAlerte = $statutApres !== StockStatut::DISPONIBLE;

            if ($etaitEnAlerte || ! $estEnAlerte) {
                return;
            }

            $site = Site::find($siteId);

            $destinataires = User::where('organization_id', $produit->organization_id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin_entreprise']))
                ->get();

            $notification = new StockAlerteNotification(
                $produit->id,
                $produit->nom,
                $site?->id,
                $site?->nom,
                $stockApres - $qteReservee,
                $seuil,
                $statutApres,
            );

            foreach ($destinataires as $destinataire) {
                $destinataire->notify($notification);
            }
        } catch (Throwable $e) {
            Log::error('StockAlerteNotification : envoi échoué', [
                'produit_id' => $produit->id,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Annule un mouvement précédemment appliqué via appliquer() : inverse le delta sur
     * VarianteStock (jamais en dessous de 0), resynchronise l'agrégat produit, puis trace un
     * CONTRE-MOUVEMENT (type inversé, même quantité) — jamais une suppression (correctif du
     * 25/08/2026 : un delete() effaçait la trace du mouvement original, en contradiction avec le
     * principe d'immuabilité de ce journal). Le mouvement original reste inchangé et
     * consultable ; son annule_par_id pointe vers le contre-mouvement qui l'a neutralisé.
     * Idempotent : un mouvement déjà annulé est un no-op.
     *
     * Utilisé quand une opération source est invalidée après coup (ex : réception de transfert
     * renvoyée en TRANSIT via supprimerEntreeDestination()).
     */
    private static function annulerMouvement(MouvementStock $mouvement): void
    {
        if ($mouvement->annule_par_id) {
            return;
        }

        $varianteStock = VarianteStock::lockOuCreer($mouvement->produit_variante_id, $mouvement->site_id, $mouvement->organization_id);

        $typeContraire = $mouvement->type === 'entree' ? 'sortie' : 'entree';
        $delta = $typeContraire === 'entree' ? $mouvement->quantite : -$mouvement->quantite;
        $stockAvant = $varianteStock->qte_stock;
        $qteReservee = $varianteStock->qte_reservee;
        $stockApres = max(0, $stockAvant + $delta);

        $varianteStock->update(['qte_stock' => $stockApres]);
        $produit = ProduitVariante::with('produit.produitType')->find($mouvement->produit_variante_id)?->produit;
        $produit?->resynchroniserQteStock();

        $contreMouvement = MouvementStock::create([
            'organization_id' => $mouvement->organization_id,
            'site_id' => $mouvement->site_id,
            'produit_variante_id' => $mouvement->produit_variante_id,
            'type' => $typeContraire,
            'quantite' => $mouvement->quantite,
            'stock_avant' => $stockAvant,
            'stock_apres' => $stockApres,
            'source_type' => $mouvement->source_type,
            'source_id' => $mouvement->source_id,
            'notes' => "Contre-mouvement — annule le mouvement {$mouvement->id}",
            'created_by' => Auth::id(),
        ]);

        $mouvement->update(['annule_par_id' => $contreMouvement->id]);

        if ($produit) {
            self::alerterSiFranchissementSeuil($produit, $mouvement->site_id, $stockAvant, $stockApres, $qteReservee);
        }
    }

    /**
     * Enregistre la sortie de stock du site source : décrémente VarianteStock sur
     * site_source_id et trace le mouvement. Idempotent : ne rejoue pas si déjà
     * enregistré pour cette ligne (source_type/source_id/site_id/type).
     */
    public static function enregistrerSortieSource(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');
        $userId = Auth::id();

        foreach ($transfert->lignes as $ligne) {
            $quantite = $ligne->quantite_chargee ?? $ligne->quantite_demandee;
            if ($quantite <= 0) {
                continue;
            }

            $dejaTraite = MouvementStock::where('source_type', TransfertLigne::class)
                ->where('source_id', $ligne->id)
                ->where('site_id', $transfert->site_source_id)
                ->where('type', 'sortie')
                ->exists();

            if ($dejaTraite) {
                continue;
            }

            self::appliquer(
                varianteId: $ligne->variante_id,
                siteId: $transfert->site_source_id,
                orgId: $transfert->organization_id,
                type: 'sortie',
                quantite: $quantite,
                sourceType: TransfertLigne::class,
                sourceId: $ligne->id,
                userId: $userId,
            );
        }
    }

    /**
     * Enregistre l'entrée de stock au site destination : incrémente VarianteStock
     * sur site_destination_id et trace le mouvement. Utilise quantite_recue
     * (données réelles de réception). Idempotent.
     */
    public static function enregistrerEntreeDestination(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');
        $userId = Auth::id();

        foreach ($transfert->lignes as $ligne) {
            // On comptabilise ce qui a réellement été reçu
            $quantite = $ligne->quantite_recue ?? $ligne->quantite_chargee ?? $ligne->quantite_demandee;
            if ($quantite <= 0) {
                continue;
            }

            $dejaTraite = MouvementStock::where('source_type', TransfertLigne::class)
                ->where('source_id', $ligne->id)
                ->where('site_id', $transfert->site_destination_id)
                ->where('type', 'entree')
                ->exists();

            if ($dejaTraite) {
                continue;
            }

            self::appliquer(
                varianteId: $ligne->variante_id,
                siteId: $transfert->site_destination_id,
                orgId: $transfert->organization_id,
                type: 'entree',
                quantite: $quantite,
                sourceType: TransfertLigne::class,
                sourceId: $ligne->id,
                userId: $userId,
            );
        }
    }

    /**
     * Supprime les entrées de stock destination créées lors de la réception, en
     * inversant symétriquement leur effet sur VarianteStock (cf. annulerMouvement()).
     * Appelé quand un admin invalide une réception pour la renvoyer en transit.
     */
    public static function supprimerEntreeDestination(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');

        foreach ($transfert->lignes as $ligne) {
            $mouvements = MouvementStock::where('source_type', TransfertLigne::class)
                ->where('source_id', $ligne->id)
                ->where('site_id', $transfert->site_destination_id)
                ->where('type', 'entree')
                ->get();

            foreach ($mouvements as $mouvement) {
                self::annulerMouvement($mouvement);
            }
        }
    }

    /**
     * Sortie de stock générique pour une variante sur un site donné — idempotent
     * sur (source_type, source_id, site_id, type). Utilisé par CommandeVenteService
     * (chargement logistique) et PdvCheckoutService (vente comptoir) — pas par les
     * transferts inter-sites, qui suivent un timing différent (voir
     * enregistrerSortieSource()/enregistrerEntreeDestination() ci-dessus).
     *
     * $allowNegative : cf. appliquer(). Réservé aux ventes (PDV/commande vente) — les
     * appelants décident au cas par cas selon Produit::autorise_vente_stock_negatif,
     * jamais un défaut implicite ici.
     *
     * @throws ValidationException si la sortie dépasse le disponible et $allowNegative est faux
     */
    public static function sortirStock(
        string $varianteId,
        string $siteId,
        string $orgId,
        int $quantite,
        string $sourceType,
        string $sourceId,
        ?string $userId,
        bool $allowNegative = false,
    ): void {
        if ($quantite <= 0) {
            return;
        }

        $dejaTraite = MouvementStock::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('site_id', $siteId)
            ->where('type', 'sortie')
            ->exists();

        if ($dejaTraite) {
            return;
        }

        self::appliquer(
            varianteId: $varianteId,
            siteId: $siteId,
            orgId: $orgId,
            type: 'sortie',
            quantite: $quantite,
            sourceType: $sourceType,
            sourceId: $sourceId,
            userId: $userId,
            allowNegative: $allowNegative,
        );
    }

    /**
     * Annule la sortie de stock précédemment enregistrée par sortirStock() pour une source
     * donnée, en inversant symétriquement son effet sur VarianteStock (cf. annulerMouvement()).
     * Idempotent : no-op si aucune sortie n'a jamais été enregistrée (rien à annuler) ou si elle
     * l'a déjà été. Utilisé par CommandeVenteService::annuler() pour la vente directe sans
     * véhicule (creerFactureDirecte() décrémente le stock immédiatement, sans étape de
     * réservation intermédiaire — contrairement au chemin véhicule, jamais annulable après son
     * propre décrément physique, cf. StatutCommandeVente::isAnnulable()).
     */
    public static function annulerSortieStock(string $sourceType, string $sourceId, string $siteId): void
    {
        $mouvements = MouvementStock::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('site_id', $siteId)
            ->where('type', 'sortie')
            ->get();

        foreach ($mouvements as $mouvement) {
            self::annulerMouvement($mouvement);
        }
    }

    /**
     * Quantité disponible pour une variante sur un site donné = stock physique moins réservé
     * (StockReservationService — commandes vente confirmées, pas encore chargées). Un site sans
     * ligne VarianteStock a strictement 0 de disponible — jamais de repli sur l'agrégat legacy
     * Produit::qte_stock, qui ne renseigne sur aucune agence en particulier. Point d'entrée
     * UNIQUE de ce calcul, réutilisé par toute la chaîne de vente (CommandeVenteService::
     * verifierDisponibiliteLignes(), ProduitController::ajusterStock(),
     * TransfertLogistiqueService::checkDisponibiliteStockSource()) — jamais dupliqué en logique.
     * PdvCheckoutService::buildLignes() reste une exception : verrouillage groupé de plusieurs
     * lignes en une seule requête (concurrence PDV), mais applique le même calcul physique
     * moins réservé.
     */
    public static function quantiteDisponible(string $varianteId, string $siteId): int
    {
        $stock = VarianteStock::where('produit_variante_id', $varianteId)
            ->where('site_id', $siteId)
            ->first(['qte_stock', 'qte_reservee']);

        return (int) ($stock->qte_stock ?? 0) - (int) ($stock->qte_reservee ?? 0);
    }
}
