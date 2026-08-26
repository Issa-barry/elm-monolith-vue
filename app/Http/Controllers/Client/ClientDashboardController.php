<?php

namespace App\Http\Controllers\Client;

use App\Enums\StatutCommission;
use App\Exceptions\Client\DuplicateVehicleProposalException;
use App\Http\Controllers\Controller;
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
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer as QrWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ClientDashboardController extends Controller
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly ClientEarningsService $earningsService,
        private readonly VehicleProposalService $proposalService,
    ) {}

    protected function resolveQrPayload(User $user): string
    {
        [, , $proprietaire, $livreur] = $this->resolveActorContext($user);

        if ($proprietaire) {
            return route('proprietaires.show', $proprietaire->id);
        }
        if ($livreur) {
            return route('livreurs.show', $livreur->id);
        }

        return route('dashboard');
    }

    public function qrCode(Request $request): HttpResponse
    {
        $user = $request->user();
        $payload = $this->resolveQrPayload($user);

        $renderer = new ImageRenderer(
            new RendererStyle(256),
            new SvgImageBackEnd
        );
        $writer = new QrWriter($renderer);
        $svg = $writer->writeString($payload);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function index(Request $request): Response
    {
        $filters = $this->resolveDashboardFilters($request);
        $payload = $this->dashboardPayload(
            $request->user(),
            $filters['date_debut'],
            $filters['date_fin'],
            $filters['vehicule_id'],
            $filters['statut']
        );

        return Inertia::render('client/Dashboard', [
            'actor' => $payload['actor'],
            'earnings' => $payload['earnings'],
            'earnings_by_vehicule' => $payload['earnings_by_vehicule'],
            'vehicules' => $payload['vehicules'],
            'status_options' => StatutCommission::options(),
            'filters' => $filters,
        ]);
    }

    public function earnings(Request $request): Response
    {
        $dateDebut = $request->input('date_debut') ?: null;
        $dateFin = $request->input('date_fin') ?: null;
        $payload = $this->dashboardPayload($request->user(), $dateDebut, $dateFin);

        return Inertia::render('client/Earnings', [
            'actor' => $payload['actor'],
            'vehicules' => $payload['vehicules'],
            'earnings' => $payload['earnings'],
            'earnings_by_vehicule' => $payload['earnings_by_vehicule'],
            'statement' => $payload['statement'],
            'filters' => ['date_debut' => $dateDebut, 'date_fin' => $dateFin],
        ]);
    }

    public function vehicleBalance(Request $request, string $vehiculeId): Response
    {
        $dateDebut = $request->input('date_debut') ?: null;
        $dateFin = $request->input('date_fin') ?: null;
        $payload = $this->dashboardPayload(
            $request->user(),
            $dateDebut,
            $dateFin,
            $vehiculeId
        );

        $vehicule = collect($payload['vehicules'])
            ->first(fn (array $item) => (string) $item['id'] === $vehiculeId);

        if ($vehicule === null) {
            abort(404);
        }

        $summary = collect($payload['earnings_by_vehicule'])
            ->first(fn (array $item) => (string) $item['vehicule_id'] === $vehiculeId);

        if ($summary === null) {
            $summary = [
                'vehicule_id' => $vehicule['id'],
                'nom_vehicule' => $vehicule['nom_vehicule'],
                'immatriculation' => $vehicule['immatriculation'],
                'frais_depenses' => 0.0,
                'total_earned' => 0.0,
                'total_paid' => 0.0,
                'balance' => 0.0,
            ];
        }

        return Inertia::render('client/VehicleBalanceDetail', [
            'vehicule' => $vehicule,
            'summary' => $summary,
            'statement' => $payload['statement'],
            'filters' => ['date_debut' => $dateDebut, 'date_fin' => $dateFin],
        ]);
    }

    public function proposals(Request $request): Response
    {
        $payload = $this->dashboardPayload($request->user());

        return Inertia::render('client/VehicleProposals', [
            'actor' => $payload['actor'],
            'vehicle_proposals' => $payload['vehicle_proposals'],
            'type_vehicule_options' => $payload['type_vehicule_options'],
        ]);
    }

    public function vehicles(Request $request): Response
    {
        $payload = $this->dashboardPayload($request->user());

        return Inertia::render('client/Vehicles', [
            'actor' => $payload['actor'],
            'owner_vehicules' => $payload['owner_vehicules'],
            'type_vehicule_options' => $payload['type_vehicule_options'],
        ]);
    }

    public function profile(Request $request): Response
    {
        $payload = $this->dashboardPayload($request->user());
        $user = $request->user();

        return Inertia::render('client/Profile', [
            'actor' => $payload['actor'],
            'profile' => [
                'full_name' => $user->name,
                'telephone' => $user->telephone,
                'email' => $user->email,
                'member_since_label' => $user->created_at?->translatedFormat('d F Y'),
                'roles' => $user->getRoleNames()->values()->all(),
                'vehicules_count' => count($payload['vehicules']),
                'operations_count' => $payload['earnings']['operations_count'],
            ],
        ]);
    }

    public function storeVehicleProposal(Request $request): RedirectResponse
    {
        $user = $request->user();
        [$organizationId, $client, $proprietaire, $livreur] = $this->resolveActorContext($user);

        $validated = $request->validate(
            VehicleProposalService::validationRules(),
            VehicleProposalService::validationMessages()
        );

        try {
            $this->proposalService->store(
                $user,
                $organizationId,
                $client,
                $proprietaire,
                $livreur,
                $validated,
                $request->file('photo')
            );
        } catch (DuplicateVehicleProposalException) {
            return back()
                ->withErrors([
                    'immatriculation' => 'Une proposition en attente existe deja pour cette immatriculation.',
                ])
                ->withInput();
        }

        return redirect()->route('client.propositions.index')->with('success', 'Votre proposition de vehicule a ete envoyee.');
    }

    private function dashboardPayload(
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

    /**
     * @return array{0:?string,1:?Client,2:?Proprietaire,3:?Livreur}
     */
    private function resolveActorContext(User $user): array
    {
        $identity = $this->identityResolver->resolve($user);

        return [$identity->organizationId, $identity->client, $identity->proprietaire, $identity->livreur];
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

    private function resolveDashboardFilters(Request $request): array
    {
        return $this->earningsService->resolveFilters($request);
    }
}
