<?php

namespace App\Services\Depenses;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutDepense;
use App\Enums\StatutTransfert;
use App\Models\CommandeVente;
use App\Models\Depense;
use App\Models\Site;
use App\Models\TransfertLogistique;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Commande de référence d'une dépense de carburant (ADR 0031, cf. docs/depenses-carburant-commande.md).
 *
 * Une commande est rattachable à une dépense quand elle est de la même organisation, livrée par le
 * véhicule de la dépense, non annulée, du jour de la dépense (créée ce jour-là, ou livrée / partie
 * ce jour-là — une précommande créée la veille reste trouvable le jour du voyage) et, si la dépense
 * porte une agence, de cette agence (vente : son agence ; transfert : agence source ou destination).
 * candidats() liste exactement ces commandes pour la liste déroulante de l'écran ; verifier() repose
 * les mêmes critères à chaque enregistrement, la sélection faite à l'écran n'étant jamais crue
 * telle quelle.
 *
 * Unicité (DEPCARB-004, 17/10/2026) : une commande n'est rattachée qu'à une seule dépense. Toute
 * dépense non rejetée ni annulée la détient, brouillon compris ; la dépense en cours de
 * modification ne se bloque pas elle-même. verrouillerUnicite() repose ce contrôle sous verrou de
 * la commande, dans la transaction d'enregistrement, contre deux saisies simultanées.
 */
final class CommandeCarburantService
{
    /**
     * @return array{candidats: list<array<string, mixed>>, message: ?string}
     */
    public function candidats(string $orgId, string $vehiculeId, CarbonInterface $dateDepense, ?string $siteId, ?string $exceptDepenseId = null): array
    {
        $jour = $dateDepense->toDateString();

        $ventes = $this->ventesRattachables($orgId, $vehiculeId)
            ->where(fn (Builder $q) => $q->whereDate('created_at', $jour)->orWhereDate('livree_at', $jour))
            ->when($siteId, fn (Builder $q) => $q->where('site_id', $siteId))
            ->get();
        $transferts = $this->transfertsRattachables($orgId, $vehiculeId)
            ->where(fn (Builder $q) => $q->whereDate('created_at', $jour)->orWhereDate('date_depart_reelle', $jour))
            ->when($siteId, fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('site_source_id', $siteId)
                ->orWhere('site_destination_id', $siteId)))
            ->get();

        $candidats = $this->serialiser($ventes, $transferts, $exceptDepenseId);

        return [
            'candidats' => $candidats,
            'message' => $candidats === [] ? $this->messageAucune($orgId, $dateDepense, $siteId) : null,
        ];
    }

    /** Message d'erreur si le rattachement est refusé, null s'il est valide. */
    public function verifier(string $orgId, string $vehiculeId, CarbonInterface $dateDepense, ?string $siteId, ?string $commandeVenteId, ?string $transfertLogistiqueId, ?string $exceptDepenseId = null): ?string
    {
        $commande = $commandeVenteId
            ? CommandeVente::where('organization_id', $orgId)->find($commandeVenteId)
            : TransfertLogistique::where('organization_id', $orgId)->find($transfertLogistiqueId);

        if (! $commande) {
            return 'La commande sélectionnée est introuvable.';
        }

        return $this->motifRefus($commande, $vehiculeId, $dateDepense->toDateString(), $siteId)
            ?? $this->motifDejaRattachee($commandeVenteId, $transfertLogistiqueId, $exceptDepenseId, false);
    }

    /**
     * Unicité sous verrou, à appeler dans la transaction qui enregistre la dépense : verrouille la
     * ligne de la commande (deux enregistrements simultanés sur la même commande s'attendent), puis
     * relit les dépenses qui la détiennent par une lecture verrouillante — sous MySQL REPEATABLE
     * READ, une lecture simple pourrait ne pas voir la dépense que l'autre transaction vient de
     * valider. Sans commande, rien à verrouiller.
     */
    public function verrouillerUnicite(?string $commandeVenteId, ?string $transfertLogistiqueId, ?string $exceptDepenseId = null): ?string
    {
        if ($commandeVenteId) {
            CommandeVente::whereKey($commandeVenteId)->lockForUpdate()->first(['id']);
        } elseif ($transfertLogistiqueId) {
            TransfertLogistique::whereKey($transfertLogistiqueId)->lockForUpdate()->first(['id']);
        } else {
            return null;
        }

        return $this->motifDejaRattachee($commandeVenteId, $transfertLogistiqueId, $exceptDepenseId, true);
    }

    private function motifDejaRattachee(?string $commandeVenteId, ?string $transfertLogistiqueId, ?string $exceptDepenseId, bool $verrouiller): ?string
    {
        $detentrice = $this->depensesDetentrices()
            ->where($commandeVenteId ? 'commande_vente_id' : 'transfert_logistique_id', $commandeVenteId ?? $transfertLogistiqueId)
            ->when($exceptDepenseId, fn (Builder $q) => $q->whereKeyNot($exceptDepenseId))
            ->when($verrouiller, fn (Builder $q) => $q->lockForUpdate())
            ->orderBy('created_at')
            ->first(['id', 'date_depense']);

        return $detentrice
            ? 'cette commande est déjà rattachée à une autre dépense de carburant (du '.$detentrice->date_depense->format('d/m/Y').').'
            : null;
    }

    /** Dépenses qui détiennent leur commande : toutes sauf rejetées et annulées. */
    private function depensesDetentrices(): Builder
    {
        return Depense::query()->whereNotIn('statut', [StatutDepense::REJETE->value, StatutDepense::ANNULE->value]);
    }

    /**
     * Commande rattachée à une dépense, pour l'affichage — relations `commandeVente` /
     * `transfertLogistique` à précharger par l'appelant en liste.
     *
     * @return array{type: string, id: string, reference: string, nature_label: string}|null
     */
    public function resume(Depense $depense): ?array
    {
        if ($depense->commande_vente_id && $depense->commandeVente) {
            return [
                'type' => 'vente',
                'id' => $depense->commandeVente->id,
                'reference' => $depense->commandeVente->reference,
                'nature_label' => $depense->commandeVente->nature_operation?->label() ?? 'Vente',
            ];
        }

        if ($depense->transfert_logistique_id && $depense->transfertLogistique) {
            return [
                'type' => 'transfert',
                'id' => $depense->transfertLogistique->id,
                'reference' => $depense->transfertLogistique->reference,
                'nature_label' => 'Transfert logistique',
            ];
        }

        return null;
    }

    private function motifRefus(CommandeVente|TransfertLogistique $commande, string $vehiculeId, string $jour, ?string $siteId): ?string
    {
        if ($commande->vehicule_id !== $vehiculeId) {
            return 'cette commande n\'a pas été livrée par le véhicule de la dépense.';
        }

        if ($this->estAnnulee($commande)) {
            return 'cette commande est annulée.';
        }

        if ($siteId && ! $this->concerneSite($commande, $siteId)) {
            return 'cette commande ne concerne pas l\'agence de la dépense.';
        }

        if (! $this->duJour($commande, $jour)) {
            return 'cette commande n\'est pas du jour de la dépense.';
        }

        return null;
    }

    private function duJour(CommandeVente|TransfertLogistique $commande, string $jour): bool
    {
        $voyage = $commande instanceof CommandeVente ? $commande->livree_at : $commande->date_depart_reelle;

        return $commande->created_at->toDateString() === $jour || $voyage?->toDateString() === $jour;
    }

    private function concerneSite(CommandeVente|TransfertLogistique $commande, string $siteId): bool
    {
        return $commande instanceof CommandeVente
            ? $commande->site_id === $siteId
            : in_array($siteId, [$commande->site_source_id, $commande->site_destination_id], true);
    }

    private function estAnnulee(CommandeVente|TransfertLogistique $commande): bool
    {
        return in_array($commande->statut, [
            StatutCommandeVente::ANNULEE,
            StatutCommandeVente::ANNULEE_ERREUR_SAISIE,
            StatutTransfert::ANNULE,
        ], true);
    }

    private function messageAucune(string $orgId, CarbonInterface $dateDepense, ?string $siteId): string
    {
        $agence = $siteId ? Site::where('organization_id', $orgId)->whereKey($siteId)->value('nom') : null;

        return 'Aucune commande de ce véhicule le '.$dateDepense->format('d/m/Y')
            .($agence ? " pour l'agence {$agence}" : '')
            .' (commandes annulées exclues).';
    }

    /** `VTE-161026-007` → `16-007` : ce que l'agent lit sur le bon et tape dans le filtre. */
    private function codeAbrege(string $reference): ?string
    {
        return preg_match('/^[A-Z]{3}-(\d{2})\d{4}-(\d{3})$/', $reference, $m) ? "{$m[1]}-{$m[2]}" : null;
    }

    private function ventesRattachables(string $orgId, string $vehiculeId): Builder
    {
        return CommandeVente::where('organization_id', $orgId)
            ->where('vehicule_id', $vehiculeId)
            ->whereNotIn('statut', [StatutCommandeVente::ANNULEE->value, StatutCommandeVente::ANNULEE_ERREUR_SAISIE->value]);
    }

    private function transfertsRattachables(string $orgId, string $vehiculeId): Builder
    {
        return TransfertLogistique::where('organization_id', $orgId)
            ->where('vehicule_id', $vehiculeId)
            ->where('statut', '!=', StatutTransfert::ANNULE->value);
    }

    /**
     * @param  Collection<int, CommandeVente>  $ventes
     * @param  Collection<int, TransfertLogistique>  $transferts
     * @return list<array<string, mixed>>
     */
    private function serialiser(Collection $ventes, Collection $transferts, ?string $exceptDepenseId): array
    {
        $ventes->load('client');
        $transferts->load(['siteSource:id,nom', 'siteDestination:id,nom']);

        $liees = fn (string $colonne, Collection $ids) => $ids->isEmpty() ? collect() : $this->depensesDetentrices()
            ->whereIn($colonne, $ids)
            ->when($exceptDepenseId, fn (Builder $q) => $q->whereKeyNot($exceptDepenseId))
            ->selectRaw("{$colonne} as cle, COUNT(*) as n")
            ->groupBy($colonne)
            ->pluck('n', 'cle');

        $lieesVentes = $liees('commande_vente_id', $ventes->pluck('id'));
        $lieesTransferts = $liees('transfert_logistique_id', $transferts->pluck('id'));

        $lignes = $ventes->map(fn (CommandeVente $c) => [
            'type' => 'vente',
            'id' => $c->id,
            'reference' => $c->reference,
            'code_abrege' => $this->codeAbrege($c->reference),
            'nature_label' => $c->nature_operation?->label() ?? 'Vente',
            'date' => $c->created_at->toDateString(),
            'statut' => $c->statut?->value,
            'statut_label' => $c->statut?->label(),
            'detail' => $c->client?->nom_complet ?? 'Vente directe',
            'depenses_liees' => (int) ($lieesVentes[$c->id] ?? 0),
        ])->concat($transferts->map(fn (TransfertLogistique $t) => [
            'type' => 'transfert',
            'id' => $t->id,
            'reference' => $t->reference,
            'code_abrege' => $this->codeAbrege($t->reference),
            'nature_label' => 'Transfert logistique',
            'date' => $t->created_at->toDateString(),
            'statut' => $t->statut?->value,
            'statut_label' => $t->statut?->label(),
            'detail' => ($t->siteSource?->nom ?? '—').' → '.($t->siteDestination?->nom ?? '—'),
            'depenses_liees' => (int) ($lieesTransferts[$t->id] ?? 0),
        ]));

        return $lignes->sortByDesc(fn (array $l) => $l['date'].$l['reference'])->values()->all();
    }
}
