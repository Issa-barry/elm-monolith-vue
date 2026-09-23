<?php

namespace App\Services;

use App\Enums\ModeTarification;
use App\Enums\MotifRetourCommande;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommandeVenteRetour;
use App\Models\CommandeVenteRetourLigne;
use App\Services\Comptabilite\VenteComptabilisationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Retour de livraison d'une vente standard AVANT tout encaissement (décision produit du
 * 23/09/2026, cf. docs/retour-commande.md) : le livreur revient avec tout ou partie de la
 * marchandise chargée. Un retour est un mouvement métier tracé, jamais une simple modification de
 * quantité — les quantités demandée et chargée d'origine ne changent jamais.
 *
 * Règle centrale : quantité livrée = quantité chargée - quantité retournée. Un retour enregistré
 * (par un utilisateur habilité, cf. CommandeVentePolicy::enregistrerRetour()) :
 *  - diminue la quantité livrée des lignes concernées et recalcule la facture sur ces quantités ;
 *  - réintègre automatiquement la marchandise retournée au stock disponible du site, par une ENTRÉE
 *    distincte de la SORTIE du chargement (cf. MouvementStockService::reintegrerRetour()) ;
 *  - réajuste la commission de vente encore CREEE sur la quantité facturée, ou l'annule en cas de
 *    retour total (cf. CommissionTriggerService::onRetourEnregistre()) ;
 *  - régularise l'écriture comptable de la vente facturée (cf. VenteComptabilisationService::
 *    comptabiliserRetourVente()).
 * Un retour total (plus rien de livré sur aucune ligne) passe la commande en RETOURNEE et annule sa
 * facture. Plusieurs retours partiels successifs sont possibles, jusqu'à épuisement des quantités.
 */
class CommandeVenteRetourService
{
    /**
     * @param  array<int, array{id?: string|null, quantite?: int|string|null}>  $lignesData  Quantité à retourner par ligne de commande ; les quantités nulles ou à 0 sont ignorées
     *
     * @throws ValidationException si le retour n'est pas permis (statut, encaissement, commission figée) ou si une quantité dépasse ce qui reste retournable
     */
    public static function enregistrer(
        CommandeVente $commande,
        array $lignesData,
        MotifRetourCommande $motif,
        ?string $commentaire = null,
    ): CommandeVenteRetour {
        $commentaire = $commentaire !== null && trim($commentaire) !== '' ? trim($commentaire) : null;

        if ($motif === MotifRetourCommande::AUTRE && $commentaire === null) {
            throw ValidationException::withMessages([
                'commentaire' => 'Précisez la raison du retour.',
            ]);
        }

        return DB::transaction(function () use ($commande, $lignesData, $motif, $commentaire) {
            // Relecture sous verrou : deux retours concurrents sur la même commande se
            // sérialisent, le second voit les quantités déjà retournées par le premier.
            $commande = CommandeVente::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            $commande->load('lignes.variante.produit.produitType', 'facture');

            $raison = $commande->raisonRetourImpossible()
                ?? CommissionTriggerService::raisonCommissionsNonRegularisables($commande);
            if ($raison !== null) {
                throw ValidationException::withMessages(['retour' => $raison]);
            }

            $demandes = self::normaliserDemandes($commande, $lignesData);

            $prixField = $commande->mode_tarification_snapshot === ModeTarification::PRIX_USINE
                ? 'prix_usine_snapshot'
                : 'prix_vente_snapshot';
            $userId = Auth::id();

            $retour = CommandeVenteRetour::create([
                'organization_id' => $commande->organization_id,
                'commande_vente_id' => $commande->id,
                'motif' => $motif,
                'commentaire' => $commentaire,
                'quantite_totale' => 0,
                'montant_retourne' => 0,
                'retour_total' => false,
                'created_by' => $userId,
            ]);

            $quantiteTotale = 0;
            $montantTotal = 0.0;

            foreach ($demandes as $ligneId => $quantite) {
                /** @var CommandeVenteLigne $ligne */
                $ligne = $commande->lignes->firstWhere('id', $ligneId);

                $prixUnitaire = (float) $ligne->{$prixField};
                $montant = round($quantite * $prixUnitaire, 2);

                $retourLigne = $retour->lignes()->create([
                    'commande_vente_ligne_id' => $ligne->id,
                    'variante_id' => $ligne->variante_id,
                    'quantite_retournee' => $quantite,
                    'prix_unitaire' => $prixUnitaire,
                    'montant_retourne' => $montant,
                    'libelle_snapshot' => self::libelle($ligne),
                ]);

                $nouvelleRetournee = (int) $ligne->quantite_retournee + $quantite;
                $nouvelleLivree = (int) $ligne->quantite_chargee - $nouvelleRetournee;
                $ligne->update([
                    'quantite_retournee' => $nouvelleRetournee,
                    'quantite_livree' => $nouvelleLivree,
                    'total_ligne' => $nouvelleLivree * $prixUnitaire,
                ]);

                self::reintegrerStock($commande, $ligne, $retourLigne, $userId);

                $quantiteTotale += $quantite;
                $montantTotal += $montant;
            }

            CommandeVenteService::recalculerTotaux($commande);

            $commande->load('lignes', 'facture');
            $retourTotal = $commande->lignes->every(fn (CommandeVenteLigne $l) => $l->quantite_nette_chargee === 0);

            $retour->update([
                'quantite_totale' => $quantiteTotale,
                'montant_retourne' => round($montantTotal, 2),
                'retour_total' => $retourTotal,
            ]);

            if ($retourTotal) {
                $commande->update(['statut' => StatutCommandeVente::RETOURNEE]);

                if ($commande->facture && ! $commande->facture->isAnnulee()) {
                    $commande->facture->update(['statut_facture' => StatutFactureVente::ANNULEE]);
                }
            }

            $comptabilise = self::comptabiliserRetour($retour, $commande);
            CommissionTriggerService::onRetourEnregistre($commande, $retourTotal);

            CommandeVenteActiviteService::log(
                $commande,
                $retourTotal ? 'retournee' : 'retour_enregistre',
                [
                    'motif' => $motif->label(),
                    'commentaire' => $commentaire,
                    'quantite' => $quantiteTotale,
                    'montant' => round($montantTotal, 2),
                ],
            );

            // Attribut transitoire (jamais persisté) : permet à l'appelant d'avertir l'utilisateur
            // que la comptabilisation reste à régulariser, cf. comptabiliserRetour().
            $retour->setAttribute('comptabilisation_echouee', ! $comptabilise);

            return $retour->load('lignes');
        });
    }

    /**
     * Fusionne les lignes reçues (une quantité par ligne de commande), écarte les quantités nulles
     * et refuse toute quantité supérieure à ce qu'il reste à retourner — chargé moins déjà retourné.
     *
     * @param  array<int, array{id?: string|null, quantite?: int|string|null}>  $lignesData
     * @return array<string, int> quantité à retourner, indexée par id de ligne de commande
     *
     * @throws ValidationException
     */
    private static function normaliserDemandes(CommandeVente $commande, array $lignesData): array
    {
        $demandes = [];

        foreach ($lignesData as $ligneData) {
            $quantite = (int) ($ligneData['quantite'] ?? 0);
            if ($quantite <= 0) {
                continue;
            }

            $ligneId = (string) ($ligneData['id'] ?? '');
            $demandes[$ligneId] = ($demandes[$ligneId] ?? 0) + $quantite;
        }

        if ($demandes === []) {
            throw ValidationException::withMessages([
                'lignes' => 'Indiquez au moins une quantité retournée.',
            ]);
        }

        foreach ($demandes as $ligneId => $quantite) {
            $ligne = $commande->lignes->firstWhere('id', $ligneId);

            if (! $ligne) {
                throw ValidationException::withMessages([
                    'lignes' => 'Une ligne du retour n\'appartient pas à cette commande.',
                ]);
            }

            $retournable = $ligne->quantite_retournable;
            if ($quantite > $retournable) {
                $libelle = self::libelle($ligne);
                $dejaRetourne = (int) $ligne->quantite_retournee;

                throw ValidationException::withMessages([
                    'lignes' => "« {$libelle} » : {$quantite} à retourner, mais seulement {$retournable} peuvent encore l'être (chargé : {$ligne->quantite_chargee}, déjà retourné : {$dejaRetourne}).",
                ]);
            }
        }

        return $demandes;
    }

    /**
     * ENTRÉE de stock distincte rattachée à la ligne de retour — ignore les lignes dont le produit
     * ne gère pas de stock (type service), même convention que CommandeVenteService::
     * decrementerStock() (la sortie du chargement n'a jamais eu lieu pour elles).
     */
    private static function reintegrerStock(
        CommandeVente $commande,
        CommandeVenteLigne $ligne,
        CommandeVenteRetourLigne $retourLigne,
        ?string $userId,
    ): void {
        if (! $ligne->variante?->produit?->produitType?->gere_stock) {
            return;
        }

        MouvementStockService::reintegrerRetour(
            varianteId: $ligne->variante_id,
            siteId: $commande->site_id,
            orgId: $commande->organization_id,
            quantite: $retourLigne->quantite_retournee,
            sourceType: CommandeVenteRetourLigne::class,
            sourceId: $retourLigne->id,
            userId: $userId,
        );
    }

    /**
     * Comptabilité générale, en aval — ne doit jamais empêcher un retour d'être enregistré
     * (mode shadow, même principe que CommandeVenteService::comptabiliserVenteFacturee()). Un retour
     * est une opération physique déjà survenue : le refuser parce qu'une écriture échoue (mapping
     * manquant, période comptable clôturée) laisserait la marchandise dans le camion sans trace.
     *
     * En revanche l'échec n'est jamais silencieux : journalisé, inscrit dans le journal d'activité
     * de la commande (visible sur sa fiche), signalé à l'utilisateur par l'appelant (retour `false`),
     * et retrouvable ensuite — `comptabilite:auditer` liste les retours non comptabilisés,
     * `comptabilite:rattraper --type=retour` les comptabilise (idempotent, sans double comptage
     * grâce à VenteComptabilisationService::retourARegulariser()).
     *
     * @return bool true si le retour n'appelait aucune écriture ou a été comptabilisé ; false si la
     *              comptabilisation a échoué et reste à régulariser
     */
    private static function comptabiliserRetour(CommandeVenteRetour $retour, CommandeVente $commande): bool
    {
        try {
            app(VenteComptabilisationService::class)->comptabiliserRetourVente($retour);

            return true;
        } catch (\Throwable $e) {
            Log::error('Comptabilisation retour de livraison échouée', [
                'retour_id' => $retour->id,
                'commande_id' => $commande->id,
                'error' => $e->getMessage(),
            ]);

            CommandeVenteActiviteService::log($commande, 'comptabilisation_retour_echouee', [
                'retour_id' => $retour->id,
                'erreur' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function libelle(CommandeVenteLigne $ligne): string
    {
        return $ligne->libelle_snapshot ?? $ligne->variante?->produit?->nom ?? 'Produit';
    }
}
