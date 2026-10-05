<?php

namespace App\Services\Commission;

use App\Models\EquipeLivraison;
use App\Models\Vehicule;

/**
 * Une équipe créée par l'import de flotte naît inactive (brouillon, cf. ImportFlotteExecutor) : elle
 * ne peut pas faire de distribution tant que son partage Livreur n'est pas configuré. Elle s'active
 * d'elle-même, avec son véhicule, dès que ce partage est conforme pour chaque processus exercé par son véhicule — même
 * juge que la colonne « Partages » de la liste des véhicules et la fiche véhicule
 * (PartageConformiteVehiculesService), pour que l'écran annonce exactement ce qui sera activé.
 *
 * Appelé après chaque écriture de partage (enregistrement de l'équipe, publication d'un barème).
 * Ne désactive jamais une équipe : une équipe active dont le partage devient non conforme reste
 * active, ses commandes étant déjà refusées catégorie par catégorie (COMM-015).
 */
class ActivationEquipesBrouillonService
{
    /**
     * @param  iterable<string>  $equipeIds
     * @return int nombre d'équipes activées
     */
    public static function activerSiPartageConforme(string $organizationId, iterable $equipeIds): int
    {
        $equipeIds = collect($equipeIds)->filter()->unique()->values();
        if ($equipeIds->isEmpty()) {
            return 0;
        }

        $vehicules = Vehicule::with('equipe.membres.livreur')
            ->where('organization_id', $organizationId)
            ->whereHas('equipe', fn ($q) => $q->whereIn('id', $equipeIds)->where('is_active', false))
            ->get();

        if ($vehicules->isEmpty()) {
            return 0;
        }

        $statuts = PartageConformiteVehiculesService::statuts($organizationId, $vehicules);
        $bloquants = [PartageConformiteVehiculesService::A_FAIRE, PartageConformiteVehiculesService::SANS_EQUIPE];

        $prets = $vehicules->reject(
            fn (Vehicule $v) => collect($statuts[$v->id] ?? [])->contains(fn (string $s) => in_array($s, $bloquants, true))
        );

        if ($prets->isEmpty()) {
            return 0;
        }

        // Le véhicule importé neuf attendait lui aussi cette équipe (créé inactif, cf.
        // ImportFlotteExecutor) : activé avec elle, comme le fait déjà chaque enregistrement de
        // l'équipe (EquipeLivraisonController::update()).
        Vehicule::whereIn('id', $prets->pluck('id'))->update(['is_active' => true]);

        return EquipeLivraison::whereIn('id', $prets->pluck('equipe.id'))->update(['is_active' => true]);
    }
}
