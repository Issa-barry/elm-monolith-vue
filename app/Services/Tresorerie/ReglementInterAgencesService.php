<?php

namespace App\Services\Tresorerie;

use App\Enums\NatureMouvementFonds;
use App\Models\EncaissementVente;
use App\Models\MouvementFonds;
use App\Models\MouvementFondsEncaissement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Règlement inter-agences (ADR 0012) : l'agence qui a encaissé des commandes d'une autre agence lui
 * reverse CES encaissements précis. C'est un mouvement de fonds ordinaire (MouvementFondsService :
 * même brouillon, même envoi sous contrôle de solde, même réception, même contestation/retour), de
 * nature `reglement_agences`, auquel sont rattachées ses lignes (mouvement_fonds_encaissements).
 *
 * Garanties, toutes prises sous verrou des encaissements concernés :
 *  - le montant est TOUJOURS la somme des encaissements sélectionnés — jamais saisi ;
 *  - tous vont dans le même sens (même agence débitrice, même agence créancière) et appartiennent
 *    à l'organisation ;
 *  - aucun n'est déjà engagé dans un règlement actif (index unique `encaissement_actif_id` en
 *    dernier rempart contre une création concurrente).
 *
 * Seule cette nature solde une dette : un mouvement « Entre agences » ordinaire n'a aucune ligne.
 */
class ReglementInterAgencesService
{
    public const MESSAGE_DEJA_ENGAGE = 'Un ou plusieurs encaissements sélectionnés sont déjà dans un autre règlement inter-agences.';

    public function __construct(private readonly MouvementFondsService $mouvements) {}

    /**
     * Crée le règlement en brouillon. L'envoi (et le contrôle du solde du support d'origine) passe
     * ensuite par MouvementFondsService::envoyer(), comme tout mouvement.
     *
     * @param  list<string>  $encaissementIds
     *
     * @throws ValidationException
     */
    public function creerBrouillon(
        string $organizationId,
        string $siteDebiteurId,
        string $siteCreancierId,
        array $encaissementIds,
        string $compteTresorerieOrigineId,
        ?string $userId,
        ?string $commentaire = null,
    ): MouvementFonds {
        $encaissementIds = array_values(array_unique(array_filter($encaissementIds)));

        if ($encaissementIds === []) {
            throw ValidationException::withMessages(['encaissements' => 'Sélectionnez au moins un encaissement à régler.']);
        }

        try {
            return DB::transaction(function () use ($organizationId, $siteDebiteurId, $siteCreancierId, $encaissementIds, $compteTresorerieOrigineId, $userId, $commentaire) {
                $encaissements = EncaissementVente::whereIn('id', $encaissementIds)
                    ->lockForUpdate()
                    ->with('facture')
                    ->get();

                $this->verifierLignes($organizationId, $siteDebiteurId, $siteCreancierId, $encaissementIds, $encaissements);

                $montant = round((float) $encaissements->sum('montant'), 2);

                $mouvement = $this->mouvements->creerBrouillon($organizationId, [
                    'site_origine_id' => $siteDebiteurId,
                    'site_destination_id' => $siteCreancierId,
                    'compte_tresorerie_origine_id' => $compteTresorerieOrigineId,
                    'montant' => $montant,
                    'commentaire' => $commentaire,
                ], $userId, NatureMouvementFonds::REGLEMENT_AGENCES);

                foreach ($encaissements as $encaissement) {
                    MouvementFondsEncaissement::create([
                        'organization_id' => $organizationId,
                        'mouvement_fonds_id' => $mouvement->id,
                        'encaissement_vente_id' => $encaissement->id,
                        'encaissement_actif_id' => $encaissement->id,
                        'montant' => $encaissement->montant,
                    ]);
                }

                return $mouvement->fresh('lignesReglement');
            });
        } catch (QueryException $e) {
            // Création concurrente : l'index unique `encaissement_actif_id` a tranché.
            if (str_contains($e->getMessage(), 'mvt_fonds_enc_actif_unique') || str_contains($e->getMessage(), 'mouvement_fonds_encaissements.encaissement_actif_id')) {
                throw ValidationException::withMessages(['encaissements' => self::MESSAGE_DEJA_ENGAGE]);
            }

            throw $e;
        }
    }

    /**
     * @param  list<string>  $encaissementIds
     * @param  Collection<int, EncaissementVente>  $encaissements
     *
     * @throws ValidationException
     */
    private function verifierLignes(string $organizationId, string $siteDebiteurId, string $siteCreancierId, array $encaissementIds, $encaissements): void
    {
        $refus = fn (string $message) => throw ValidationException::withMessages(['encaissements' => $message]);

        if ($siteDebiteurId === $siteCreancierId) {
            $refus("L'agence qui verse et l'agence qui reçoit doivent être différentes.");
        }

        if ($encaissements->count() !== count($encaissementIds)) {
            $refus('Un ou plusieurs encaissements sélectionnés sont introuvables.');
        }

        foreach ($encaissements as $encaissement) {
            $facture = $encaissement->facture;

            if (! $facture || $facture->organization_id !== $organizationId) {
                $refus('Un ou plusieurs encaissements sélectionnés sont introuvables.');
            }
            if (! $encaissement->estPourAutreAgence()) {
                $refus("L'encaissement de la facture {$facture->reference} a été reçu par l'agence de la commande : il n'y a rien à reverser.");
            }
            if ($encaissement->site_encaissement_id !== $siteDebiteurId || $facture->site_id !== $siteCreancierId) {
                $refus("L'encaissement de la facture {$facture->reference} ne concerne pas ces deux agences : un règlement ne couvre qu'un seul sens (agence qui a encaissé → agence de la commande).");
            }
        }

        if (MouvementFondsEncaissement::whereIn('encaissement_actif_id', $encaissementIds)->exists()) {
            $refus(self::MESSAGE_DEJA_ENGAGE);
        }
    }
}
