<?php

namespace App\Traits;

use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Personne;
use App\Models\Prestataire;

/**
 * Résout les champs d'identité soumis par les formulaires Fournisseur/Prestataire (nom, prenom,
 * raison_sociale, email, phone...) vers une Personne (cas physique) ou une EntrepriseTierce (cas
 * moral), au lieu de les stocker directement sur le rôle — cf. addendum "EntrepriseTierce" de
 * l'audit Contacts & Commission V2. À l'édition d'une fiche déjà rattachée, met à jour l'identité
 * existante en place plutôt que de re-résoudre par téléphone : éditer un fournisseur ne doit
 * jamais silencieusement le rattacher à une autre entité existante.
 */
trait ResolutionIdentiteTiersTrait
{
    /**
     * @return array{personne_id: ?string, entreprise_tierce_id: ?string}
     */
    private function resoudreIdentite(string $organizationId, array $data, Fournisseur|Prestataire|null $existant = null): array
    {
        $attributsCommuns = [
            'telephone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'pays' => $data['pays'] ?? null,
            'code_pays' => $data['code_pays'] ?? null,
            'code_phone_pays' => $data['code_phone_pays'] ?? null,
            'ville' => $data['ville'] ?? null,
            'adresse' => $data['adresse'] ?? null,
        ];

        if (! empty($data['raison_sociale'])) {
            $attributs = ['raison_sociale' => $data['raison_sociale'], ...$attributsCommuns];

            if ($existant?->entreprise_tierce_id) {
                $existant->entrepriseTierce->update($attributs);

                return ['personne_id' => null, 'entreprise_tierce_id' => $existant->entreprise_tierce_id];
            }

            $entrepriseTierce = EntrepriseTierce::resoudreOuCreer($organizationId, $attributs);

            return ['personne_id' => null, 'entreprise_tierce_id' => $entrepriseTierce->id];
        }

        $attributs = ['nom' => $data['nom'] ?? null, 'prenom' => $data['prenom'] ?? null, ...$attributsCommuns];

        if ($existant?->personne_id) {
            $existant->personne->update($attributs);

            return ['personne_id' => $existant->personne_id, 'entreprise_tierce_id' => null];
        }

        $personne = Personne::resoudreOuCreer($organizationId, $attributs);

        return ['personne_id' => $personne->id, 'entreprise_tierce_id' => null];
    }
}
