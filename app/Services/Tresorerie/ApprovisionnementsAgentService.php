<?php

namespace App\Services\Tresorerie;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Models\MouvementFonds;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Approvisionnements de la caisse d'un agent en attente de SA confirmation (ADR 0018) : affichés
 * dans « Ma situation », accessible à tous les rôles — l'agent bénéficiaire est le seul à pouvoir
 * confirmer, même sans aucune permission de trésorerie. Lecture seule ; la confirmation passe par
 * MouvementFondsService::recevoir() / contester(), qui revérifient la règle.
 */
class ApprovisionnementsAgentService
{
    /**
     * Envoyés ou contestés, vers une caisse dont l'utilisateur est titulaire — y compris ceux qu'il
     * a remis lui-même si son rôle lui permet de les confirmer (MouvementFonds::receptionReserveeA()).
     * Un approvisionnement contesté reste confirmable (l'argent a finalement été reçu) mais ne se
     * conteste plus.
     *
     * @return list<array<string, mixed>>
     */
    public function enAttentePour(User $agent): array
    {
        return self::requete($agent)
            ->whereIn('statut', [StatutMouvementFonds::ENVOYE->value, StatutMouvementFonds::CONTESTE->value])
            ->with(['compteTresorerieOrigine:id,libelle', 'compteTresorerieDestination:id,libelle,agent_id', 'siteOrigine:id,nom', 'expediteur.personne'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (MouvementFonds $m) => [
                'id' => $m->id,
                'reference' => $m->reference,
                'montant' => (float) $m->montant,
                'statut' => $m->statut->value,
                'statut_label' => $m->statut->label(),
                'agence' => $m->siteOrigine?->nom,
                'caisse_origine' => $m->compteTresorerieOrigine?->libelle,
                'caisse_destination' => $m->compteTresorerieDestination?->libelle,
                'remis_par' => $m->expediteur?->name,
                'envoye_le' => ($m->sent_at ?? $m->created_at)?->toIso8601String(),
                'motif' => $m->commentaire,
                'peut_contester' => $m->isEnvoye() && $m->receptionReserveeA($agent, 'tresorerie.rejeter'),
            ])
            ->values()
            ->all();
    }

    /** Approvisionnements envoyés, pas encore confirmés, que l'utilisateur doit confirmer (badge de menu). */
    public function nombreAConfirmer(User $agent): int
    {
        return self::requete($agent)->where('statut', StatutMouvementFonds::ENVOYE->value)->count();
    }

    /** @return Builder<MouvementFonds> */
    private static function requete(User $agent): Builder
    {
        $peutConfirmerSesRemises = $agent->can('tresorerie.recevoir');

        return MouvementFonds::query()
            ->where('organization_id', $agent->organization_id)
            ->where('nature', NatureMouvementFonds::APPROVISIONNEMENT_CAISSE->value)
            ->when(! $peutConfirmerSesRemises, fn ($q) => $q->where(fn ($q) => $q->whereNull('sent_by')->orWhere('sent_by', '!=', $agent->id)))
            ->whereHas('compteTresorerieDestination', fn ($q) => $q->where('agent_id', $agent->id));
    }
}
