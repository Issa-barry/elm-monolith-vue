<?php

namespace App\Services;

use App\Enums\MotifAjustementStock;
use App\Models\CommandeVenteLigne;
use App\Models\MouvementStock;
use App\Models\TransfertLigne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Résout le "motif" affiché/filtrable d'un MouvementStock à partir de sa véritable
 * origine (source_type/source_id, notes) — jamais du signe du mouvement (une sortie
 * peut être une vente, un transfert ou un ajustement manuel).
 *
 * Deux familles de motifs :
 *  - Ajustement manuel (ProduitController::ajusterStock()) : `notes` contient déjà le
 *    libellé humain (MotifAjustementStock::toNotesString()) — on le fait juste
 *    correspondre à sa valeur d'enum pour obtenir une clé de filtre stable.
 *  - Mouvement automatique (vente, transfert...) : `notes` est vide, on classe via
 *    `source_type`. Seuls CommandeVenteLigne (Vente) et TransfertLigne (Transfert) sont
 *    résolus explicitement pour l'instant — cf. rapport d'implémentation (réception
 *    achat/CommandeAchatLigne reste non étiquetée, comportement inchangé).
 */
class MouvementStockMotifService
{
    public const KEY_VENTE = 'vente';

    public const KEY_TRANSFERT = 'transfert';

    public const KEY_INCONNU = 'inconnu';

    /**
     * Annote une collection de MouvementStock avec motif_type (clé stable, pour filtrer)
     * et motif_label (texte affiché, avec référence de commande/transfert si résolue).
     * Résout les références par lot pour éviter le N+1.
     */
    public static function annoter(Collection $mouvements): Collection
    {
        $ligneVenteIds = $mouvements
            ->where('source_type', CommandeVenteLigne::class)
            ->pluck('source_id')
            ->filter()
            ->unique();

        $referencesVente = $ligneVenteIds->isEmpty()
            ? collect()
            : CommandeVenteLigne::whereIn('id', $ligneVenteIds)
                ->with('commande:id,reference')
                ->get()
                ->mapWithKeys(fn (CommandeVenteLigne $l) => [$l->id => $l->commande?->reference]);

        $ligneTransfertIds = $mouvements
            ->where('source_type', TransfertLigne::class)
            ->pluck('source_id')
            ->filter()
            ->unique();

        $referencesTransfert = $ligneTransfertIds->isEmpty()
            ? collect()
            : TransfertLigne::whereIn('id', $ligneTransfertIds)
                ->with('transfert:id,reference')
                ->get()
                ->mapWithKeys(fn (TransfertLigne $l) => [$l->id => $l->transfert?->reference]);

        return $mouvements->each(function (MouvementStock $m) use ($referencesVente, $referencesTransfert) {
            [$key, $baseLabel] = self::classify($m->source_type, $m->notes);

            $label = match ($key) {
                self::KEY_VENTE => self::avecReference($baseLabel, $referencesVente->get($m->source_id)),
                self::KEY_TRANSFERT => self::avecReference($baseLabel, $referencesTransfert->get($m->source_id)),
                // Motifs manuels : le texte brut des notes (ex: "Autre : détail saisi")
                // est plus précis que le libellé générique du cas — jamais tronqué ici.
                default => (string) $m->notes !== '' ? (string) $m->notes : $baseLabel,
            };

            $m->setAttribute('motif_type', $key);
            $m->setAttribute('motif_label', $label);
        });
    }

    /**
     * Options de filtre réellement présentes pour la requête donnée (pas de valeur
     * inventée) — calculées sur le périmètre AVANT filtrage/take(), pour que le menu
     * reste stable quel que soit le motif actuellement sélectionné.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function optionsDisponibles(Builder $query): array
    {
        $distincts = (clone $query)
            ->select('source_type', 'notes')
            ->distinct()
            ->get();

        $options = [];
        foreach ($distincts as $row) {
            [$key, $label] = self::classify($row->source_type, $row->notes);
            if ($key === self::KEY_INCONNU) {
                continue;
            }
            $options[$key] = $label;
        }

        ksort($options);

        return collect($options)
            ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    /** Restreint la requête aux mouvements correspondant à la clé de motif donnée. */
    public static function appliquerFiltre(Builder $query, string $motif): Builder
    {
        return match (true) {
            $motif === self::KEY_VENTE => $query->where('source_type', CommandeVenteLigne::class),
            $motif === self::KEY_TRANSFERT => $query->where('source_type', TransfertLigne::class),
            MotifAjustementStock::tryFrom($motif) !== null => self::whereNotesMotif($query, MotifAjustementStock::from($motif)),
            // Clé inconnue (jamais proposée par optionsDisponibles()) : filtre ignoré
            // plutôt qu'un "aucun résultat" silencieux si le menu et les données divergent.
            default => $query,
        };
    }

    private static function whereNotesMotif(Builder $query, MotifAjustementStock $case): Builder
    {
        if ($case === MotifAjustementStock::AUTRE) {
            return $query->where('notes', 'like', 'Autre%');
        }

        return $query->where('notes', $case->label());
    }

    /** @return array{0: string, 1: string} [clé, libellé de base sans référence] */
    private static function classify(?string $sourceType, ?string $notes): array
    {
        $notes = trim((string) $notes);

        if ($notes !== '') {
            foreach (MotifAjustementStock::cases() as $case) {
                $correspond = $case === MotifAjustementStock::AUTRE
                    ? str_starts_with($notes, 'Autre')
                    : $notes === $case->label();

                if ($correspond) {
                    return [$case->value, $case->label()];
                }
            }

            // Notes présentes mais non reconnues (texte libre legacy, hypothétique — tous
            // les points d'écriture actuels passent par toNotesString()) : non filtrable
            // (cf. optionsDisponibles()), mais affichées telles quelles.
            return [self::KEY_INCONNU, $notes];
        }

        if ($sourceType === CommandeVenteLigne::class) {
            return [self::KEY_VENTE, 'Vente'];
        }

        if ($sourceType === TransfertLigne::class) {
            return [self::KEY_TRANSFERT, 'Transfert'];
        }

        return [self::KEY_INCONNU, '—'];
    }

    private static function avecReference(string $base, ?string $reference): string
    {
        return $reference ? "{$base} — {$reference}" : $base;
    }
}
