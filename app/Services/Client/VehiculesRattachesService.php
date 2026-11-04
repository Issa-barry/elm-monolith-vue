<?php

namespace App\Services\Client;

use App\Enums\ActiviteCompte;
use App\Models\EquipeLivreur;
use App\Models\Vehicule;
use App\Services\Client\Data\VehiculeRattache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Véhicules auxquels un compte est rattaché aujourd'hui (ADR 0054) : propriétaire
 * (`vehicules.proprietaire_id`), livreur (membre de l'équipe actuelle) ou parrain
 * (`vehicules.parrain_id`). Un consultant n'a aucun lien avec un véhicule. Un véhicule quitté (équipe,
 * parrainage retiré) sort de cette liste ; ses commissions restent lisibles par bénéficiaire.
 */
class VehiculesRattachesService
{
    /**
     * @param  array<int|string, mixed>  $with
     * @return Collection<int, VehiculeRattache>
     */
    public function pour(BeneficiairesCompte $beneficiaires, array $with = []): Collection
    {
        $proprietaire = $beneficiaires->proprietaire;
        $livreur = $beneficiaires->livreur;
        $parrain = $beneficiaires->parrain;

        if ($beneficiaires->organizationId === null || ($proprietaire === null && $livreur === null && $parrain === null)) {
            return collect();
        }

        return Vehicule::query()
            ->with([...$with, 'equipe.membres'])
            ->where('organization_id', $beneficiaires->organizationId)
            ->where(function (Builder $query) use ($proprietaire, $livreur, $parrain) {
                if ($proprietaire !== null) {
                    $query->orWhere('proprietaire_id', $proprietaire->id);
                }
                if ($livreur !== null) {
                    $query->orWhereHas('equipe.membres', fn (Builder $sq) => $sq->where('livreur_id', $livreur->id));
                }
                if ($parrain !== null) {
                    $query->orWhere('parrain_id', $parrain->id);
                }
            })
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn (Vehicule $vehicule) => new VehiculeRattache($vehicule, array_values(array_filter([
                $proprietaire !== null && $vehicule->proprietaire_id === $proprietaire->id ? ActiviteCompte::PROPRIETAIRE : null,
                $livreur !== null && $vehicule->equipe?->membres->contains(fn (EquipeLivreur $m) => $m->livreur_id === $livreur->id) ? ActiviteCompte::LIVREUR : null,
                $parrain !== null && $vehicule->parrain_id === $parrain->id ? ActiviteCompte::PARRAIN : null,
            ]))))
            ->values();
    }
}
