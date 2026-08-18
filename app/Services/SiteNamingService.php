<?php

namespace App\Services;

use App\Enums\SiteType;

/**
 * Nom généré automatiquement pour le premier site d'une organisation — "Type de Quartier" (ex:
 * "Usine de Matoto"), utilisé par l'onboarding du premier site (cf.
 * InstallationService::creerPremierSite()), qui ne demande plus de nom à l'utilisateur.
 *
 * Aucune numérotation : `sites.nom` n'a pas de contrainte d'unicité en base (seul `code`, généré
 * séparément par Site::boot(), l'a) et le seul appelant actuel ne peut de toute façon créer qu'un
 * seul site par organisation (gardé par EnsureOrganizationHasSite) — un numéro visible n'aurait
 * donc aucune nécessité métier ici.
 *
 * Le nom généré est auto-descriptif ("Usine de Matoto") : voir Site::getLabelAttribute(), qui
 * évite de re-préfixer le type par-dessus pour l'affichage (sites nommés manuellement, eux,
 * restent un simple libellé court comme "Matoto").
 */
class SiteNamingService
{
    public function generateName(SiteType $type, string $quartier): string
    {
        // "Boutique / Point de vente" doit donner "Boutique de ...", pas "Boutique / Point de
        // vente de ..." — même préfixe que Site::getLabelAttribute(), aucune liste de types
        // dupliquée (toujours SiteType::label() comme source de vérité).
        $prefixe = explode(' / ', $type->label())[0];

        return "{$prefixe} de ".trim($quartier);
    }
}
