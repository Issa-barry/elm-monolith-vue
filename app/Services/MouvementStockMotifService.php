<?php

namespace App\Services;

use App\Enums\MotifAjustementStock;
use App\Models\CommandeAchatLigne;
use App\Models\CommandeVenteLigne;
use App\Models\CommandeVenteRetour;
use App\Models\CommandeVenteRetourLigne;
use App\Models\MouvementStock;
use App\Models\ReceptionAchatLigne;
use App\Models\TransfertLigne;
use App\Models\TransfertRetourLigne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Résout le "motif" affiché/filtrable d'un MouvementStock à partir de sa véritable
 * origine (source_type/source_id, notes) — jamais du signe du mouvement (une sortie
 * peut être une vente, un transfert ou un ajustement manuel).
 *
 * Deux familles de motifs :
 *  - Ajustement manuel (AjusterStockProduitController) : `notes` contient déjà le
 *    libellé humain (MotifAjustementStock::toNotesString()) — on le fait juste
 *    correspondre à sa valeur d'enum pour obtenir une clé de filtre stable.
 *  - Mouvement automatique (vente, transfert...) : `notes` est vide, on classe via
 *    `source_type` : CommandeVenteLigne (Vente), CommandeVenteRetourLigne (Retour livraison),
 *    TransfertLigne (Transfert), TransfertRetourLigne (Retour transfert, ADR 0029), et
 *    ReceptionAchatLigne / CommandeAchatLigne (Réception achat — réceptions multiples depuis
 *    ADR 0021, et entrées historiques de la réception unique).
 */
class MouvementStockMotifService
{
    public const KEY_VENTE = 'vente';

    public const KEY_TRANSFERT = 'transfert';

    public const KEY_RETOUR_LIVRAISON = 'retour_livraison';

    public const KEY_RETOUR_TRANSFERT = 'retour_transfert';

    public const KEY_RECEPTION_ACHAT = 'reception_achat';

    public const KEY_INCONNU = 'inconnu';

    /**
     * Motif d'un mouvement automatique selon sa source (`source_type`). Seule table de
     * correspondance, lue par classify() (affichage) et expressionSql() (agrégations Stat-Stock).
     */
    private const SOURCES = [
        CommandeVenteLigne::class => self::KEY_VENTE,
        TransfertLigne::class => self::KEY_TRANSFERT,
        CommandeVenteRetourLigne::class => self::KEY_RETOUR_LIVRAISON,
        TransfertRetourLigne::class => self::KEY_RETOUR_TRANSFERT,
        ReceptionAchatLigne::class => self::KEY_RECEPTION_ACHAT,
        CommandeAchatLigne::class => self::KEY_RECEPTION_ACHAT,
    ];

    private const LIBELLES_SOURCES = [
        self::KEY_VENTE => 'Vente',
        self::KEY_TRANSFERT => 'Transfert',
        self::KEY_RETOUR_LIVRAISON => 'Retour livraison',
        self::KEY_RETOUR_TRANSFERT => 'Retour transfert',
        self::KEY_RECEPTION_ACHAT => 'Réception achat',
    ];

    /**
     * Le motif de classify() en SQL, pour agréger les mouvements par motif sans les charger
     * (tableau de bord Stat-Stock) : mêmes règles, même ordre — libellé d'ajustement manuel dans
     * `notes` d'abord, puis la source.
     *
     * @return array{0: string, 1: list<string>} expression et liaisons
     */
    public static function expressionSql(string $alias = 'm'): array
    {
        $notes = "TRIM(COALESCE({$alias}.notes, ''))";
        $cas = [];
        $liaisons = [];

        foreach (MotifAjustementStock::cases() as $case) {
            if ($case === MotifAjustementStock::AUTRE) {
                $cas[] = "WHEN {$notes} LIKE ? THEN ?";
                $liaisons[] = 'Autre%';
            } else {
                $cas[] = "WHEN {$notes} = ? THEN ?";
                $liaisons[] = $case->label();
            }
            $liaisons[] = $case->value;
        }
        // Notes présentes mais non reconnues (contre-mouvements d'annulation, texte libre legacy).
        $cas[] = "WHEN {$notes} <> '' THEN ?";
        $liaisons[] = self::KEY_INCONNU;

        foreach (self::SOURCES as $source => $key) {
            $cas[] = "WHEN {$alias}.source_type = ? THEN ?";
            $liaisons[] = $source;
            $liaisons[] = $key;
        }

        return ['CASE '.implode(' ', $cas).' ELSE ? END', [...$liaisons, self::KEY_INCONNU]];
    }

    /** Libellé d'une clé de motif (ajustement manuel ou source), « Autre motif » si inconnue. */
    public static function libelle(string $key): string
    {
        return MotifAjustementStock::tryFrom($key)?->label()
            ?? self::LIBELLES_SOURCES[$key]
            ?? 'Autre motif';
    }

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

        $ligneRetourIds = $mouvements
            ->where('source_type', CommandeVenteRetourLigne::class)
            ->pluck('source_id')
            ->filter()
            ->unique();

        $referencesRetour = $ligneRetourIds->isEmpty()
            ? collect()
            : CommandeVenteRetourLigne::whereIn('id', $ligneRetourIds)
                ->with('retour.commande:id,reference')
                ->get()
                // Régularisation après encaissement (ADR 0043) : même mouvement qu'un retour, signalée.
                ->mapWithKeys(fn (CommandeVenteRetourLigne $l) => [$l->id => $l->retour?->commande?->reference
                    .($l->retour?->nature === CommandeVenteRetour::NATURE_REGULARISATION ? ' (régularisation)' : '')]);

        $ligneRetourTransfertIds = $mouvements
            ->where('source_type', TransfertRetourLigne::class)
            ->pluck('source_id')
            ->filter()
            ->unique();

        $referencesRetourTransfert = $ligneRetourTransfertIds->isEmpty()
            ? collect()
            : TransfertRetourLigne::whereIn('id', $ligneRetourTransfertIds)
                ->with('retour.transfert:id,reference')
                ->get()
                ->mapWithKeys(fn (TransfertRetourLigne $l) => [$l->id => $l->retour?->transfert?->reference]);

        $referencesAchat = self::referencesAchat($mouvements);

        return $mouvements->each(function (MouvementStock $m) use ($referencesVente, $referencesTransfert, $referencesRetour, $referencesRetourTransfert, $referencesAchat) {
            [$key, $baseLabel] = self::classify($m->source_type, $m->notes);

            $label = match ($key) {
                self::KEY_VENTE => self::avecReference($baseLabel, $referencesVente->get($m->source_id)),
                self::KEY_TRANSFERT => self::avecReference($baseLabel, $referencesTransfert->get($m->source_id)),
                self::KEY_RETOUR_LIVRAISON => self::avecReference($baseLabel, $referencesRetour->get($m->source_id)),
                self::KEY_RETOUR_TRANSFERT => self::avecReference($baseLabel, $referencesRetourTransfert->get($m->source_id)),
                self::KEY_RECEPTION_ACHAT => self::avecReference($baseLabel, $referencesAchat->get($m->source_type.'|'.$m->source_id)),
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
            $motif === self::KEY_RETOUR_LIVRAISON => $query->where('source_type', CommandeVenteRetourLigne::class),
            $motif === self::KEY_RETOUR_TRANSFERT => $query->where('source_type', TransfertRetourLigne::class),
            $motif === self::KEY_RECEPTION_ACHAT => $query->whereIn('source_type', [ReceptionAchatLigne::class, CommandeAchatLigne::class]),
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

        $key = self::SOURCES[(string) $sourceType] ?? null;

        return $key === null ? [self::KEY_INCONNU, '—'] : [$key, self::LIBELLES_SOURCES[$key]];
    }

    /**
     * Référence du bon de commande, indexée par « source_type|source_id » : les entrées récentes
     * pointent vers une ligne de réception, les historiques vers la ligne de commande.
     */
    private static function referencesAchat(Collection $mouvements): Collection
    {
        $receptionIds = $mouvements->where('source_type', ReceptionAchatLigne::class)->pluck('source_id')->filter()->unique();
        $commandeIds = $mouvements->where('source_type', CommandeAchatLigne::class)->pluck('source_id')->filter()->unique();

        $parReception = $receptionIds->isEmpty()
            ? collect()
            : ReceptionAchatLigne::whereIn('id', $receptionIds)
                ->with('reception.commande:id,reference')
                ->get()
                ->mapWithKeys(fn (ReceptionAchatLigne $l) => [ReceptionAchatLigne::class.'|'.$l->id => $l->reception?->commande?->reference]);

        $parCommande = $commandeIds->isEmpty()
            ? collect()
            : CommandeAchatLigne::whereIn('id', $commandeIds)
                ->with('commande:id,reference')
                ->get()
                ->mapWithKeys(fn (CommandeAchatLigne $l) => [CommandeAchatLigne::class.'|'.$l->id => $l->commande?->reference]);

        return $parReception->merge($parCommande);
    }

    private static function avecReference(string $base, ?string $reference): string
    {
        return $reference ? "{$base} — {$reference}" : $base;
    }
}
