<?php

namespace App\Services;

use App\Enums\MotifRetourTransfert;
use App\Enums\StatutTransfert;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\TransfertRetour;
use App\Models\TransfertRetourLigne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retour au site source de tout ou partie de la marchandise partie sur un transfert logistique
 * (ADR 0029, cf. docs/retour-transfert.md). Un retour est un mouvement métier tracé : les quantités
 * chargée et reçue d'origine ne changent jamais, seule `quantite_retournee` est cumulée.
 *
 * Un retour enregistré (par un utilisateur habilité, cf. TransfertLogistiquePolicy::
 * enregistrerRetour() — l'enregistrement vaut validation) :
 *  - réintègre la marchandise au stock du SITE SOURCE par une ENTRÉE distincte, rattachée à la
 *    ligne de retour — la sortie source du départ reste intacte (cf. MouvementStockService::
 *    reintegrerRetour()) ;
 *  - ne recalcule jamais la commission (COMM-007 maintenue) : sous CHARGEMENT_VALIDE elle reste due
 *    sur la quantité chargée, même en cas de retour total ; sous RECEPTION_EFFECTUEE elle portera
 *    sur la quantité reçue, déjà nette des retours.
 * Quand toute la marchandise chargée est revenue avant la réception, le transfert passe en
 * RETOURNE (statut terminal). Plusieurs retours successifs sont possibles, jusqu'à épuisement des
 * quantités retournables (cf. TransfertLigne::quantite_retournable).
 */
class TransfertRetourService
{
    /**
     * @param  array<int, array{id?: string|null, quantite?: int|string|null}>  $lignesData  Quantité à retourner par ligne de transfert ; les quantités nulles ou à 0 sont ignorées
     *
     * @throws ValidationException si le retour n'est pas permis (statut) ou si une quantité dépasse ce qui reste retournable
     */
    public static function enregistrer(
        TransfertLogistique $transfert,
        array $lignesData,
        MotifRetourTransfert $motif,
        ?string $commentaire = null,
    ): TransfertRetour {
        $commentaire = $commentaire !== null && trim($commentaire) !== '' ? trim($commentaire) : null;

        if ($motif === MotifRetourTransfert::AUTRE && $commentaire === null) {
            throw ValidationException::withMessages([
                'commentaire' => 'Précisez la raison du retour.',
            ]);
        }

        return DB::transaction(function () use ($transfert, $lignesData, $motif, $commentaire) {
            // Même verrou que la réception (TransfertLogistiqueService::avancerStatut()) et
            // l'invalidation : un retour et une réception concurrents se sérialisent, le second
            // relit les quantités écrites par le premier.
            $transfert = TransfertLogistique::whereKey($transfert->id)->lockForUpdate()->firstOrFail();
            $transfert->load('lignes.variante.produit');
            $transfert->lignes->each->setRelation('transfert', $transfert);

            $raison = $transfert->raisonRetourImpossible();
            if ($raison !== null) {
                throw ValidationException::withMessages(['retour' => $raison]);
            }

            $demandes = self::normaliserDemandes($transfert, $lignesData);
            $userId = Auth::id();

            $retour = TransfertRetour::create([
                'organization_id' => $transfert->organization_id,
                'transfert_logistique_id' => $transfert->id,
                'site_id' => $transfert->site_source_id,
                'motif' => $motif,
                'commentaire' => $commentaire,
                'quantite_totale' => array_sum($demandes),
                'retour_total' => false,
                'created_by' => $userId,
            ]);

            foreach ($demandes as $ligneId => $quantite) {
                /** @var TransfertLigne $ligne */
                $ligne = $transfert->lignes->firstWhere('id', $ligneId);

                $retourLigne = $retour->lignes()->create([
                    'transfert_ligne_id' => $ligne->id,
                    'variante_id' => $ligne->variante_id,
                    'quantite_retournee' => $quantite,
                    'libelle_snapshot' => self::libelle($ligne),
                ]);

                $ligne->update(['quantite_retournee' => (int) $ligne->quantite_retournee + $quantite]);

                self::reintegrerStock($transfert, $ligne, $retourLigne, $userId);
            }

            $retourTotal = $transfert->isTransit()
                && $transfert->lignes->every(fn (TransfertLigne $l) => (int) $l->quantite_chargee - (int) $l->quantite_retournee <= 0);

            if ($retourTotal) {
                $retour->update(['retour_total' => true]);
                $transfert->update(['statut' => StatutTransfert::RETOURNE->value]);
            }

            TransfertActiviteService::log(
                $transfert,
                $retourTotal ? 'retourne' : 'retour_enregistre',
                [
                    'retour_id' => $retour->id,
                    'motif' => $motif->label(),
                    'commentaire' => $commentaire,
                    'quantite' => $retour->quantite_totale,
                ],
            );

            return $retour->load('lignes');
        });
    }

    /**
     * Fusionne les lignes reçues (une quantité par ligne de transfert), écarte les quantités nulles
     * et refuse toute quantité supérieure à ce qui reste retournable.
     *
     * @param  array<int, array{id?: string|null, quantite?: int|string|null}>  $lignesData
     * @return array<string, int> quantité à retourner, indexée par id de ligne de transfert
     *
     * @throws ValidationException
     */
    private static function normaliserDemandes(TransfertLogistique $transfert, array $lignesData): array
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
            /** @var TransfertLigne|null $ligne */
            $ligne = $transfert->lignes->firstWhere('id', $ligneId);

            if (! $ligne) {
                throw ValidationException::withMessages([
                    'lignes' => 'Une ligne du retour n\'appartient pas à ce transfert.',
                ]);
            }

            $retournable = $ligne->quantite_retournable;
            if ($quantite > $retournable) {
                $libelle = self::libelle($ligne);
                $detail = $transfert->isReception()
                    ? "chargé : {$ligne->quantite_chargee}, reçu : {$ligne->quantite_recue}, déjà retourné : {$ligne->quantite_retournee}"
                    : "chargé : {$ligne->quantite_chargee}, déjà retourné : {$ligne->quantite_retournee}";

                throw ValidationException::withMessages([
                    'lignes' => "« {$libelle} » : {$quantite} à retourner, mais seulement {$retournable} peuvent encore l'être ({$detail}).",
                ]);
            }
        }

        return $demandes;
    }

    /**
     * ENTRÉE au site source rattachée à la ligne de retour. Aucun filtre sur le type de produit :
     * symétrique de MouvementStockService::enregistrerSortieSource(), qui sort toute ligne chargée
     * au départ — on ne réintègre que ce qui est réellement sorti.
     */
    private static function reintegrerStock(
        TransfertLogistique $transfert,
        TransfertLigne $ligne,
        TransfertRetourLigne $retourLigne,
        ?string $userId,
    ): void {
        MouvementStockService::reintegrerRetour(
            varianteId: $ligne->variante_id,
            siteId: $transfert->site_source_id,
            orgId: $transfert->organization_id,
            quantite: $retourLigne->quantite_retournee,
            sourceType: TransfertRetourLigne::class,
            sourceId: $retourLigne->id,
            userId: $userId,
        );
    }

    private static function libelle(TransfertLigne $ligne): string
    {
        return $ligne->variante?->produit?->nom ?? 'Produit';
    }
}
