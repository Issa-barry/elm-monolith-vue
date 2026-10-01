<?php

namespace App\Services\Agents;

use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Onglet Dépenses de la fiche agent (docs/fiche-agent.md) : dépenses SAISIES par l'agent
 * (depenses.user_id, renseigné à la création par StoreDepenseController), tous statuts.
 * Les dépenses dont l'agent serait bénéficiaire (via une fiche Employé) n'en font pas partie.
 * Même périmètre que l'écran Dépenses pour `depenses.read` : toute l'organisation.
 */
class AgentDepensesService
{
    /** Nombre de lignes affichées ; les totaux portent toujours sur toutes les dépenses. */
    public const LIMITE_LIGNES = 300;

    /**
     * @return array{resume: array, lignes: list<array>, total_lignes: int}
     */
    public function pourAgent(User $agent): array
    {
        $base = fn (): Builder => Depense::query()
            ->where('organization_id', $agent->organization_id)
            ->where('user_id', $agent->id);

        $parStatut = $base()
            ->groupBy('statut')
            ->selectRaw('statut, COUNT(*) as nombre, COALESCE(SUM(montant), 0) as montant')
            ->get()
            ->keyBy(fn (Depense $d) => $d->statut->value);

        $somme = fn (StatutDepense $s) => [
            'montant' => round((float) ($parStatut[$s->value]->montant ?? 0), 2),
            'nombre' => (int) ($parStatut[$s->value]->nombre ?? 0),
        ];
        $nombre = (int) $parStatut->sum('nombre');

        $lignes = $base()
            ->with('depenseType:id,libelle,categorie')
            ->orderByDesc('date_depense')
            ->orderByDesc('created_at')
            ->limit(self::LIMITE_LIGNES)
            ->get()
            ->map(fn (Depense $d) => [
                'id' => $d->id,
                'date_depense' => $d->date_depense?->toDateString(),
                'type' => $d->depenseType?->libelle,
                'categorie' => $d->depenseType?->categorie?->label(),
                'montant' => (float) $d->montant,
                'statut' => $d->statut->value,
                'statut_label' => $d->statut->label(),
            ])
            ->all();

        return [
            'resume' => [
                'validees' => $somme(StatutDepense::VALIDE),
                'en_attente' => $somme(StatutDepense::SOUMIS),
                'nombre' => $nombre,
            ],
            'lignes' => $lignes,
            'total_lignes' => $nombre,
        ];
    }
}
