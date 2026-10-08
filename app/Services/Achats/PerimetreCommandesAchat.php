<?php

namespace App\Services\Achats;

use App\Models\CommandeAchat;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Models\User;
use App\Services\Validation\ValidationParPlafondService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Périmètre « Peut acheter pour » (ADR 0021) : les agences couvertes par les règles des rôles de
 * l'utilisateur (`regles_validation_roles`, domaine achats). Il gouverne la CRÉATION (agences
 * proposées et acceptées), la LECTURE et la VALIDATION — un seul système, sans passe-droit :
 * ni admin_entreprise ni super_admin n'ont d'accès automatique sans règle. Le créateur et le
 * validateur d'un bon le voient toujours.
 *
 * Vérifié explicitement par les contrôleurs, pas seulement par la policy : le Gate::before du
 * super administrateur court-circuite les policies.
 */
class PerimetreCommandesAchat
{
    public function __construct(private readonly ValidationParPlafondService $regles) {}

    /** @return list<string>|null null = toutes les agences de l'organisation */
    public function sitesCouverts(User $user): ?array
    {
        return $this->regles->sitesCouverts($user, RegleValidationRole::DOMAINE_ACHATS);
    }

    public function couvreSite(User $user, ?string $siteId): bool
    {
        return $this->regles->couvreSite($user, RegleValidationRole::DOMAINE_ACHATS, $siteId);
    }

    /** Agences pour lesquelles l'utilisateur peut créer un bon (formulaire, filtre Agence). */
    public function sites(User $user): Collection
    {
        $couverts = $this->sitesCouverts($user);

        return Site::where('organization_id', $user->organization_id)
            ->when($couverts !== null, fn (Builder $q) => $q->whereIn('id', $couverts))
            ->orderBy('nom')
            ->get(['id', 'nom']);
    }

    public function appliquer(Builder $query, User $user): Builder
    {
        $query->where('organization_id', $user->organization_id);
        $couverts = $this->sitesCouverts($user);

        if ($couverts === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn('site_id', $couverts === [] ? [''] : $couverts)
            ->orWhere('created_by', $user->id)
            ->orWhere('validee_par', $user->id));
    }

    public function estVisible(CommandeAchat $commande, User $user): bool
    {
        if ($commande->organization_id !== $user->organization_id) {
            return false;
        }
        if (($commande->created_by !== null && $commande->created_by === $user->id)
            || ($commande->validee_par !== null && $commande->validee_par === $user->id)) {
            return true;
        }

        return $this->sitesCouverts($user) === null || $this->couvreSite($user, $commande->site_id);
    }

    /** 403 si le bon est hors du périmètre de l'utilisateur — à appeler après authorize(). */
    public function autoriser(CommandeAchat $commande, User $user): void
    {
        abort_unless($this->estVisible($commande, $user), 403, "Ce bon de commande n'est pas dans votre périmètre d'achat.");
    }
}
