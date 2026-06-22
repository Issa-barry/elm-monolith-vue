<?php

namespace App\Services;

use App\Enums\StatutPropositionVehicule;
use App\Models\PropositionVehicule;
use App\Models\Proprietaire;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PropositionConversionService
{
    /**
     * Convertit une proposition en Proprietaire (trouvé ou créé) + Vehicule.
     *
     * @return array{proprietaire: Proprietaire, vehicule: Vehicule, proprietaire_existant: bool}
     *
     * @throws RuntimeException si déjà convertie ou immatriculation en doublon
     */
    public function convertir(PropositionVehicule $proposition, User $agent): array
    {
        if ($proposition->statut === StatutPropositionVehicule::CONVERTIE) {
            throw new RuntimeException('Cette proposition a déjà été convertie.');
        }

        $orgId = $proposition->organization_id;
        $immatriculation = mb_strtoupper(trim((string) $proposition->immatriculation), 'UTF-8');

        $doublon = Vehicule::where('organization_id', $orgId)
            ->where('immatriculation', $immatriculation)
            ->whereNull('deleted_at')
            ->first();

        if ($doublon) {
            throw new RuntimeException(
                "Un véhicule avec l'immatriculation {$immatriculation} existe déjà."
            );
        }

        return DB::transaction(function () use ($proposition, $agent, $orgId, $immatriculation) {
            [$proprietaire, $existait] = $this->resoudreProprietaire($proposition, $orgId);

            $typeVehiculeId = $this->resoudreTypeVehicule($proposition->type_vehicule, $orgId);

            $vehicule = Vehicule::create([
                'organization_id' => $orgId,
                'nom_vehicule' => $proposition->nom_vehicule,
                'marque' => $proposition->marque,
                'modele' => $proposition->modele,
                'immatriculation' => $immatriculation,
                'type_vehicule_id' => $typeVehiculeId,
                'categorie' => 'externe',
                'capacite_packs' => $proposition->capacite_packs,
                'proprietaire_id' => $proprietaire->id,
                'pris_en_charge_par_usine' => false,
                'photo_path' => $proposition->photo_path,
                'is_active' => false,
            ]);

            $proposition->update([
                'statut' => StatutPropositionVehicule::CONVERTIE->value,
                'traitee_par' => $agent->id,
                'traitee_at' => now(),
            ]);

            return [
                'proprietaire' => $proprietaire,
                'vehicule' => $vehicule,
                'proprietaire_existant' => $existait,
            ];
        });
    }

    private function resoudreTypeVehicule(?string $nomType, ?string $orgId): ?string
    {
        if (! $nomType || ! $orgId) {
            return null;
        }

        $type = TypeVehicule::where('organization_id', $orgId)
            ->whereRaw('LOWER(nom) = ?', [mb_strtolower(trim($nomType))])
            ->whereNull('deleted_at')
            ->value('id');

        return $type;
    }

    /**
     * @return array{0: Proprietaire, 1: bool} [proprietaire, existait_deja]
     */
    private function resoudreProprietaire(PropositionVehicule $proposition, ?string $orgId): array
    {
        // 1. Déjà lié à un propriétaire existant
        if ($proposition->proprietaire_id) {
            $prop = $proposition->proprietaire ?? Proprietaire::find($proposition->proprietaire_id);
            if ($prop) {
                return [$prop, true];
            }
        }

        // 2. Recherche par téléphone normalisé
        $telephone = $proposition->telephone_contact;
        if ($telephone && $orgId) {
            $existing = Proprietaire::where('organization_id', $orgId)
                ->where('telephone', $telephone)
                ->first();

            if ($existing) {
                $proposition->update(['proprietaire_id' => $existing->id]);

                return [$existing, true];
            }
        }

        // 3. Création d'un nouveau propriétaire depuis les infos de contact
        $nomParts = preg_split('/\s+/', trim((string) $proposition->nom_contact), 2);
        $nom = mb_strtoupper((string) array_pop($nomParts), 'UTF-8');
        $prenom = $nomParts[0] ?? null;

        $proprietaire = Proprietaire::create([
            'organization_id' => $orgId,
            'user_id' => $proposition->user_id,
            'nom' => $nom ?: 'INCONNU',
            'prenom' => $prenom,
            'telephone' => $telephone,
            'is_active' => true,
        ]);

        $proposition->update(['proprietaire_id' => $proprietaire->id]);

        return [$proprietaire, false];
    }
}
