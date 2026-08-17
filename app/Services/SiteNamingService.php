<?php

namespace App\Services;

use App\Enums\SiteType;
use App\Models\Site;

/**
 * Nom généré automatiquement pour un site — "Type N Quartier" (ex: "Boutique 2 Kipé"),
 * numéroté par type au sein d'une même organisation. Utilisé par l'onboarding du premier site
 * (cf. InstallationService::creerPremierSite()), qui ne demande plus de nom à l'utilisateur.
 *
 * Le préfixe de nommage vient du premier segment du label affiché (`SiteType::label()`),
 * pas du label complet : "Boutique / Point de vente" doit donner "Boutique 1 ...", pas
 * "Boutique / Point de vente 1 ...". Aucune liste de types dupliquée — toujours la même
 * source de vérité que le reste de l'application.
 */
class SiteNamingService
{
    public function nextName(string $organizationId, SiteType $type, string $quartier): string
    {
        $prefixe = explode(' / ', $type->label())[0];
        $quartier = trim($quartier);

        $numero = Site::where('organization_id', $organizationId)->where('type', $type->value)->count() + 1;
        $nom = "{$prefixe} {$numero} {$quartier}";

        // Filet best-effort (même principe que Site::boot() pour son `code`) : couvre le cas
        // d'un site déjà nommé manuellement à l'identique, sans prétendre à une garantie
        // transactionnelle stricte — le seul appelant actuel (onboarding du tout premier site)
        // ne peut de toute façon jamais s'exécuter deux fois pour la même organisation.
        while (Site::where('organization_id', $organizationId)->where('nom', $nom)->exists()) {
            $numero++;
            $nom = "{$prefixe} {$numero} {$quartier}";
        }

        return $nom;
    }
}
