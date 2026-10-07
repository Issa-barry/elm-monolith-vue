<?php

namespace App\Services\Achats;

use App\Models\CommandeAchat;
use App\Models\User;
use App\Services\SiteScopeService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qui peut lire quel bon de commande fournisseur (ADR 0021) : les commandes des agences de
 * l'utilisateur (périmètre SiteScopeService), plus celles qu'il a créées ou validées — même hors
 * de ses agences. La permission `achats.read` ne donne jamais, seule, accès à toute
 * l'organisation. Utilisé par la liste ET par la policy (un accès direct par URL suit la même
 * règle).
 */
class PerimetreCommandesAchat
{
    public function __construct(private readonly SiteScopeService $siteScope) {}

    public function appliquer(Builder $query, User $user): Builder
    {
        $query->where('organization_id', $user->organization_id);

        if ($this->siteScope->couvreToutesLesAgences($user)) {
            return $query;
        }

        $sites = $this->siteScope->accessibleSiteIds($user)->all();

        return $query->where(fn (Builder $q) => $q
            ->whereIn('site_id', $sites === [] ? [''] : $sites)
            ->orWhere('created_by', $user->id)
            ->orWhere('validee_par', $user->id));
    }

    public function estVisible(CommandeAchat $commande, User $user): bool
    {
        return $commande->organization_id === $user->organization_id
            && ($this->siteScope->couvreToutesLesAgences($user)
                || $this->siteScope->siteAccessible($user, $commande->site_id)
                || ($commande->created_by !== null && $commande->created_by === $user->id)
                || ($commande->validee_par !== null && $commande->validee_par === $user->id));
    }
}
