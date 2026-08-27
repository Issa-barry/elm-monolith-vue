<?php

namespace App\Services\Client;

use App\Enums\StatutPropositionVehicule;
use App\Exceptions\Client\DuplicateVehicleProposalException;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\PropositionVehicule;
use App\Models\Proprietaire;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Moteur unique de "proposer un véhicule" — SOURCE DE VÉRITÉ PARTAGÉE entre
 * l'espace client Inertia (`ClientDashboardController::storeVehicleProposal()`)
 * et l'API (`Api\Client\PropositionsVehiculeController`). Extrait le
 * 26/08/2026, comportement préservé à l'identique (même règle anti-doublon,
 * même normalisation d'immatriculation, même stockage image WebP) — seule la
 * mise en forme de la réponse (redirect Inertia vs JSON) reste propre à
 * chaque contrôleur.
 */
class VehicleProposalService
{
    public function __construct(private readonly ImageService $imageService) {}

    public static function validationRules(): array
    {
        return [
            'nom_vehicule' => ['nullable', 'string', 'max:100'],
            'marque' => ['nullable', 'string', 'max:100'],
            'modele' => ['nullable', 'string', 'max:100'],
            'immatriculation' => ['required', 'string', 'max:30'],
            'type_vehicule' => ['required', 'string', 'max:30'],
            'commentaire' => ['nullable', 'string', 'max:500'],
            'photo' => ['required', 'image', 'max:5120'],
        ];
    }

    public static function validationMessages(): array
    {
        return [
            'immatriculation.required' => "L'immatriculation est obligatoire.",
            'type_vehicule.required' => 'Le type de vehicule est obligatoire.',
            'photo.required' => 'La photo du vehicule est obligatoire.',
            'photo.image' => 'Le fichier doit etre une image.',
            'photo.max' => 'La photo ne doit pas depasser 5 Mo.',
        ];
    }

    /**
     * @param  array  $validated  Déjà passé par validationRules() ci-dessus.
     *
     * @throws DuplicateVehicleProposalException
     */
    public function store(
        User $user,
        ?string $organizationId,
        ?Client $client,
        ?Proprietaire $proprietaire,
        ?Livreur $livreur,
        array $validated,
        UploadedFile $photo,
    ): PropositionVehicule {
        $immatriculation = mb_strtoupper(trim((string) $validated['immatriculation']), 'UTF-8');

        $duplicate = PropositionVehicule::query()
            ->where('immatriculation', $immatriculation)
            ->where('statut', StatutPropositionVehicule::PENDING->value)
            ->when(
                $organizationId !== null,
                fn ($query) => $query->where('organization_id', $organizationId),
                fn ($query) => $query->whereNull('organization_id')
            )
            ->exists();

        if ($duplicate) {
            throw new DuplicateVehicleProposalException($immatriculation);
        }

        $photoPath = $this->imageService->storeAsWebp($photo, 'propositions-vehicules');

        return PropositionVehicule::create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'client_id' => $client?->id,
            'proprietaire_id' => $proprietaire?->id,
            'livreur_id' => $livreur?->id,
            'nom_contact' => $user->name,
            'telephone_contact' => $user->telephone,
            'nom_vehicule' => $this->nullableTrim($validated['nom_vehicule'] ?? null),
            'marque' => $this->nullableTrim($validated['marque'] ?? null),
            'modele' => $this->nullableTrim($validated['modele'] ?? null),
            'immatriculation' => $immatriculation,
            'type_vehicule' => $validated['type_vehicule'],
            'commentaire' => $this->nullableTrim($validated['commentaire'] ?? null),
            'photo_path' => $photoPath,
            'statut' => StatutPropositionVehicule::PENDING->value,
        ]);
    }

    /**
     * @return Collection<int, PropositionVehicule>
     */
    public function mine(string $userId, ?string $organizationId, int $limit = 20): Collection
    {
        return PropositionVehicule::query()
            ->where('user_id', $userId)
            ->when(
                $organizationId !== null,
                fn ($query) => $query->where('organization_id', $organizationId),
                fn ($query) => $query->whereNull('organization_id')
            )
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
