<?php

namespace Tests\Concerns;

use App\Models\Employe;
use App\Models\Organization;
use App\Models\Personne;

/**
 * Contrat de travail généré (ADR 0048) : aucun contrat ne se crée sans PDG désigné, signataire de
 * tous les contrats, et la période d'essai et les heures par semaine sont obligatoires à la
 * création.
 */
trait DesigneUnPdg
{
    protected function designerPdg(Organization|string $organisation, string $prenom = 'Alpha', string $nom = 'CONDÉ'): Employe
    {
        $orgId = $organisation instanceof Organization ? $organisation->id : $organisation;

        $personne = Personne::create([
            'organization_id' => $orgId,
            'prenom' => $prenom,
            'nom' => $nom,
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);
        $pdg = Employe::create([
            'organization_id' => $orgId,
            'personne_id' => $personne->id,
            'matricule' => 'PDG'.fake()->unique()->numerify('####'),
            'type_employe' => 'interne',
            'statut' => 'actif',
        ]);
        Organization::whereKey($orgId)->update(['pdg_employe_id' => $pdg->id]);

        return $pdg;
    }

    /** @return array<string, mixed> */
    protected function conditionsContrat(array $surcharges = []): array
    {
        return [
            'periode_essai_duree' => 3,
            'periode_essai_unite' => 'mois',
            'heures_hebdomadaires' => 40,
            'motif_cdd' => 'accroissement temporaire d’activité',
            ...$surcharges,
        ];
    }
}
