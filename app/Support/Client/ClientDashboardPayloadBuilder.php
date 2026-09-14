<?php

namespace App\Support\Client;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PropositionVehicule;
use App\Models\Proprietaire;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Client\ClientEarningsService;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\VehicleProposalService;
use Illuminate\Support\Collection;

/**
 * Assemble le payload partagé par les pages de l'espace client (Dashboard, Earnings,
 * VehicleBalanceDetail, VehicleProposals, Vehicles, Profile) — extrait de
 * `ClientDashboardController::dashboardPayload()` (et ses méthodes privées), dupliqué à
 * l'identique par 6 des 8 actions du contrôleur d'origine avant cette extraction. Toute la
 * logique métier reste dans les services injectés (ClientIdentityResolver, ClientEarningsService,
 * VehicleProposalService) : cette classe ne fait que composer leurs résultats en un seul payload.
 */
class ClientDashboardPayloadBuilder
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly ClientEarningsService $earningsService,
        private readonly VehicleProposalService $proposalService,
    ) {}

    public function build(
        User $user,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $vehiculeId = null,
        ?string $statut = null
    ): array {
        [$organizationId, $client, $proprietaire, $livreur] = $this->resolveActorContext($user);

        $vehicules = $this->vehiculesPartenaires($organizationId, $proprietaire, $livreur);
        $vehiculeIdsFiltres = $vehicules->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $selectedVehiculeId = $vehiculeId !== null && in_array($vehiculeId, $vehiculeIdsFiltres, true)
            ? $vehiculeId
            : null;
        $vehiculeIdsContrainte = null;
        if ($selectedVehiculeId !== null) {
            $vehiculeIdsContrainte = [$selectedVehiculeId];
        }

        $ownerVehicules = $this->vehiculesDuProprietaire($organizationId, $proprietaire);
        $earnings = $this->earningsService->summary(
            $vehicules,
            $organizationId,
            $proprietaire,
            $livreur,
            $dateDebut,
            $dateFin,
            $statut,
            $vehiculeIdsContrainte
        );

        $profileLabels = collect();
        if ($client !== null) {
            $profileLabels->push('Client');
        }
        if ($proprietaire !== null) {
            $profileLabels->push('Proprietaire');
        }
        if ($livreur !== null) {
            $profileLabels->push('Livreur');
        }
        if ($profileLabels->isEmpty()) {
            $profileLabels->push('Client');
        }

        $mappedVehicules = $vehicules
            ->map(fn (Vehicule $vehicule) => [
                'id' => $vehicule->id,
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_label' => $vehicule->type_label,
                'is_active' => (bool) $vehicule->is_active,
                'capacites' => $this->capacitesPayload($vehicule),
                'photo_url' => $vehicule->photo_url,
            ])
            ->values()
            ->all();

        $mappedOwnerVehicules = $ownerVehicules
            ->map(fn (Vehicule $vehicule) => [
                'id' => $vehicule->id,
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'type_label' => $vehicule->type_label,
                'is_active' => (bool) $vehicule->is_active,
                'capacites' => $this->capacitesPayload($vehicule),
                'photo_url' => $vehicule->photo_url,
            ])
            ->values()
            ->all();

        return [
            'actor' => [
                'organization_name' => $organizationId
                    ? Organization::query()->whereKey($organizationId)->value('name')
                    : null,
                'profiles' => $profileLabels->values()->all(),
                'is_partner' => $proprietaire !== null || $livreur !== null,
                'client_id' => $client?->id,
                'proprietaire_id' => $proprietaire?->id,
                'livreur_id' => $livreur?->id,
            ],
            'type_vehicule_options' => TypeVehicule::where('organization_id', $organizationId)
                ->where('is_active', true)
                ->orderBy('nom')
                ->get()
                ->map(fn (TypeVehicule $t) => ['value' => $t->nom, 'label' => $t->nom])
                ->values()
                ->all(),
            'vehicules' => $mappedVehicules,
            'owner_vehicules' => $mappedOwnerVehicules,
            'earnings' => $earnings['totals'],
            'earnings_by_vehicule' => $earnings['by_vehicule'],
            'statement' => $earnings['statement'],
            'vehicle_proposals' => $this->userProposals($user->id, $organizationId),
        ];
    }

    /**
     * @return array{0:?string,1:?Client,2:?Proprietaire,3:?Livreur}
     */
    public function resolveActorContext(User $user): array
    {
        $identity = $this->identityResolver->resolve($user);

        return [$identity->organizationId, $identity->client, $identity->proprietaire, $identity->livreur];
    }

    private function userProposals(string $userId, ?string $organizationId): array
    {
        return $this->proposalService->mine($userId, $organizationId)
            ->map(fn (PropositionVehicule $p) => [
                'id' => $p->id,
                'nom_vehicule' => $p->nom_vehicule,
                'marque' => $p->marque,
                'modele' => $p->modele,
                'immatriculation' => $p->immatriculation,
                'type_vehicule' => $p->type_vehicule,
                'capacite_packs' => $p->capacite_packs,
                'commentaire' => $p->commentaire,
                'statut' => $p->statut?->value ?? (string) $p->getRawOriginal('statut'),
                'statut_label' => $p->statut_label,
                'decision_note' => $p->decision_note,
                'created_at_label' => $p->created_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{categorie_nom: string, capacite_max: int}> */
    private function capacitesPayload(Vehicule $vehicule): array
    {
        return $vehicule->capacites
            ->map(fn ($c) => [
                'categorie_nom' => $c->categorie->nom,
                'capacite_max' => $c->capacite_max,
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Vehicule>
     */
    private function vehiculesPartenaires(?string $organizationId, ?Proprietaire $proprietaire, ?Livreur $livreur): Collection
    {
        return $this->earningsService->vehiculesAccessibles(
            $organizationId,
            $proprietaire,
            $livreur,
            ['typeVehicule', 'capacites.categorie']
        );
    }

    /**
     * @return Collection<int, Vehicule>
     */
    private function vehiculesDuProprietaire(?string $organizationId, ?Proprietaire $proprietaire): Collection
    {
        if ($organizationId === null || $proprietaire === null) {
            return collect();
        }

        return Vehicule::query()
            ->with(['typeVehicule', 'capacites.categorie'])
            ->where('organization_id', $organizationId)
            ->where('proprietaire_id', $proprietaire->id)
            ->orderBy('nom_vehicule')
            ->get();
    }
}
