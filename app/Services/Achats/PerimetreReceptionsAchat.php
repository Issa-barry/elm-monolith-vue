<?php

namespace App\Services\Achats;

use App\Models\CommandeAchat;
use App\Models\RegleValidationRole;
use App\Models\User;
use App\Services\Validation\ValidationParPlafondService;

/**
 * Pour quelles agences un utilisateur peut réceptionner les bons de commande fournisseurs
 * (ADR 0021, révisé le 12/10/2026) :
 * - toujours celles auxquelles il est rattaché (règle d'origine, inchangée) ;
 * - plus celles que couvre une règle « Peut réceptionner pour » d'un de ses rôles
 *   (Paramètres → Achats : agences sélectionnées ou toutes les agences).
 *
 * Aucune exception de rôle dans le code : le super administrateur réceptionne partout parce qu'une
 * règle de départ le lui donne, que l'organisation peut modifier. La permission `receptions.create`
 * reste nécessaire et se vérifie à part.
 */
class PerimetreReceptionsAchat
{
    public function __construct(private readonly ValidationParPlafondService $regles) {}

    /** @return list<string>|null null = toutes les agences de l'organisation */
    public function sitesCouverts(User $user): ?array
    {
        $parRegle = $this->regles->sitesCouverts($user, RegleValidationRole::DOMAINE_RECEPTIONS_ACHATS);
        if ($parRegle === null) {
            return null;
        }

        return array_values(array_unique([...$user->sites()->pluck('sites.id')->all(), ...$parRegle]));
    }

    public function couvre(User $user, CommandeAchat $commande): bool
    {
        if ($commande->site_id === null || $user->organization_id !== $commande->organization_id) {
            return false;
        }

        return $user->sites()->where('sites.id', $commande->site_id)->exists()
            || $this->regles->couvreSite($user, RegleValidationRole::DOMAINE_RECEPTIONS_ACHATS, $commande->site_id);
    }
}
