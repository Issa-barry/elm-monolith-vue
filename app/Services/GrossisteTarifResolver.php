<?php

namespace App\Services;

use App\Enums\ModeRemiseGrossiste;
use App\Enums\PrixOrigine;
use App\Models\CategorieTarifGrossiste;
use App\Models\Client;
use App\Models\ProduitVariante;
use Illuminate\Validation\ValidationException;

/**
 * Point d'entrée unique pour déterminer le prix de vente applicable à une ligne de commande
 * Grossiste — dépend de la catégorie commerciale du produit, du mode de remise de la commande
 * (Enlèvement/Livraison) ET DU CLIENT lui-même (chaque Grossiste négocie son propre tarif,
 * décision produit du 05/09/2026 — jamais une grille partagée par toute l'organisation). Jamais
 * de la variante seule (contrairement à PrixVenteNatureResolver, qui ne s'applique pas à cette
 * nature — cf. son docblock). Toujours source de vérité serveur
 * (CommandeVenteController::buildLignesDataAndTotal()), jamais un prix envoyé par le frontend.
 * Cf. docs/grossiste.md.
 *
 * Le tarif Grossiste est une SURCHARGE facultative du prix normal du produit, jamais une
 * obligation (révision produit du 05/09/2026 — remplace le blocage initial) : un client Grossiste
 * sans tarif spécifique pour (catégorie, mode) — ou un produit sans catégorie — retombe
 * simplement sur `ProduitVariante::prix_vente`, déjà garanti rentable par
 * ProduitService::validerPrixSelonType() au catalogue. Le tarif d'un AUTRE Grossiste n'est en
 * revanche jamais utilisé en repli : seul le tarif propre au client concerné, ou le prix normal.
 * Seul cas encore bloquant : un tarif spécial existe mais ne couvre pas le coût de référence du
 * produit (garde-fou anti-vente-à-perte, cf. validerCoherenceMarge()).
 */
class GrossisteTarifResolver
{
    public static function resolve(ProduitVariante $variante, ModeRemiseGrossiste $mode, Client $client): int
    {
        $tarif = self::tarifSpecial($variante, $mode, $client);

        if ($tarif) {
            self::validerCoherenceMarge($variante, $tarif);

            return (int) $tarif->prix;
        }

        return (int) ($variante->prix_vente ?? 0);
    }

    public static function resolveOrigine(ProduitVariante $variante, ModeRemiseGrossiste $mode, Client $client): PrixOrigine
    {
        return self::tarifSpecial($variante, $mode, $client) ? PrixOrigine::GROSSISTE : PrixOrigine::VENTE;
    }

    private static function tarifSpecial(ProduitVariante $variante, ModeRemiseGrossiste $mode, Client $client): ?CategorieTarifGrossiste
    {
        $categorieId = $variante->produit?->categorie_id;
        if (! $categorieId) {
            return null;
        }

        return CategorieTarifGrossiste::query()
            ->where('client_id', $client->id)
            ->where('categorie_id', $categorieId)
            ->where('mode', $mode->value)
            ->first();
    }

    /**
     * Garde-fou anti-vente-à-perte — même principe que ProduitService::validerPrixSelonType()
     * (prix_vente > prix_reference strict), appliqué ici en défense en profondeur au moment de la
     * vente : un tarif Grossiste catégorie/mode/client a pu être configuré avant l'ajout d'un
     * nouveau produit plus coûteux dans la catégorie. Ignoré si le type de produit n'a pas de champ
     * de référence (ex: SERVICE) — même convention que le contrôle catalogue. Ne s'applique QUE
     * lorsqu'un tarif spécial existe — le repli sur prix_vente est déjà protégé à la source.
     */
    private static function validerCoherenceMarge(ProduitVariante $variante, CategorieTarifGrossiste $tarif): void
    {
        $champReference = $variante->produit?->produitType?->champPrixReference();
        if (! $champReference) {
            return;
        }

        $prixReference = (int) ($variante->{$champReference} ?? 0);

        if ($prixReference > 0 && (int) $tarif->prix <= $prixReference) {
            throw ValidationException::withMessages([
                'lignes' => "Le tarif Grossiste de la catégorie « {$variante->produit?->categorie?->nom} » ({$tarif->prix} GNF) ne couvre pas le coût de référence du produit « {$variante->produit?->nom} » ({$prixReference} GNF).",
            ]);
        }
    }
}
