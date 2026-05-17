<?php

namespace App\Http\Controllers\Client;

use App\Enums\StatutCommission;
use App\Enums\StatutPropositionVehicule;
use App\Enums\TypeVehicule;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPart;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PropositionVehicule;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\ImageService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer as QrWriter;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientDashboardController extends Controller
{
    protected function resolveQrPayload(User $user): string
    {
        [, , $proprietaire] = $this->resolveActorContext($user);

        return $proprietaire
            ? route('proprietaires.show', $proprietaire->id)
            : route('dashboard');
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

        $validated = $request->validate([
            'nom_vehicule' => ['nullable', 'string', 'max:100'],
            'marque' => ['nullable', 'string', 'max:100'],
            'modele' => ['nullable', 'string', 'max:100'],
            'immatriculation' => ['required', 'string', 'max:30'],
            'type_vehicule' => ['required', Rule::in(TypeVehicule::allowedValues())],
            'commentaire' => ['nullable', 'string', 'max:500'],
            'photo' => ['required', 'image', 'max:5120'],
        ], [
            'immatriculation.required' => "L'immatriculation est obligatoire.",
            'type_vehicule.required' => 'Le type de vehicule est obligatoire.',
            'type_vehicule.in' => 'Le type de vehicule est invalide.',
            'photo.required' => 'La photo du vehicule est obligatoire.',
            'photo.image' => 'Le fichier doit etre une image.',
            'photo.max' => 'La photo ne doit pas depasser 5 Mo.',
        ]);

        $immatriculation = mb_strtoupper(trim((string) $validated['immatriculation']), 'UTF-8');
        $photoPath = (new ImageService)->storeAsWebp($request->file('photo'), 'propositions-vehicules');

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
            return back()
                ->withErrors([
                    'immatriculation' => 'Une proposition en attente existe deja pour cette immatriculation.',
                ])
                ->withInput();
        }

        PropositionVehicule::create([
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
            'capacite_packs' => TypeVehicule::from($validated['type_vehicule'])->defaultCapacitePacks(),
            'commentaire' => $this->nullableTrim($validated['commentaire'] ?? null),
            'photo_path' => $photoPath,
            'statut' => StatutPropositionVehicule::PENDING->value,
        ]);

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
        $partsVentes = $this->partsVentes(
            $organizationId,
            $proprietaire,
            $livreur,
            $dateDebut,
            $dateFin,
            $statut,
            $vehiculeIdsContrainte
        );
        $partsLogistiques = $this->partsLogistiques(
            $organizationId,
            $proprietaire,
            $livreur,
            $dateDebut,
            $dateFin,
            $statut,
            $vehiculeIdsContrainte
        );
        $fraisParVehicule = $this->fraisDepensesParVehicule(
            $organizationId,
            $proprietaire,
            $dateDebut,
            $dateFin,
            $vehiculeIdsContrainte
        );
        $fraisTotal = (float) array_sum($fraisParVehicule);

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
                'capacite_packs' => $vehicule->capacite_packs,
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
                'capacite_packs' => $vehicule->capacite_packs,
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
            'type_vehicule_options' => TypeVehicule::options(),
            'vehicules' => $mappedVehicules,
            'owner_vehicules' => $mappedOwnerVehicules,
            'earnings' => $this->calculateEarnings($partsVentes, $partsLogistiques, $fraisTotal),
            'earnings_by_vehicule' => $this->earningsByVehicule($vehicules, $partsVentes, $partsLogistiques, $fraisParVehicule),
            'statement' => $this->releve($partsVentes, $partsLogistiques),
            'vehicle_proposals' => $this->userProposals($user->id, $organizationId),
        ];
    }

    private function userProposals(string $userId, ?string $organizationId): array
    {
        return PropositionVehicule::query()
            ->where('user_id', $userId)
            ->when(
                $organizationId !== null,
                fn ($query) => $query->where('organization_id', $organizationId),
                fn ($query) => $query->whereNull('organization_id')
            )
            ->latest()
            ->limit(20)
            ->get()
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

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{0:?int,1:?Client,2:?Proprietaire,3:?Livreur}
     */
    private function resolveActorContext(User $user): array
    {
        $organizationId = $user->organization_id;
        $telephone = $user->telephone;

        $client = Client::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->where(function ($query) use ($user, $telephone) {
                $query->where('user_id', $user->id);
                if ($telephone) {
                    $query->orWhere('telephone', $telephone);
                }
            })
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->first();

        if ($organizationId === null && $client !== null) {
            $organizationId = $client->organization_id;
        }

        $proprietaire = Proprietaire::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->where(function ($query) use ($user, $telephone) {
                $query->where('user_id', $user->id);
                if ($telephone) {
                    $query->orWhere('telephone', $telephone);
                }
            })
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->first();

        if ($organizationId === null && $proprietaire !== null) {
            $organizationId = $proprietaire->organization_id;
        }

        $livreur = Livreur::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when(
                $telephone !== null,
                fn ($query) => $query->where('telephone', $telephone),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->first();

        if ($organizationId === null && $livreur !== null) {
            $organizationId = $livreur->organization_id;
        }

        if ($organizationId !== null) {
            if ($client && $client->organization_id !== $organizationId) {
                $client = null;
            }
            if ($proprietaire && $proprietaire->organization_id !== $organizationId) {
                $proprietaire = null;
            }
            if ($livreur && $livreur->organization_id !== $organizationId) {
                $livreur = null;
            }
        }

        return [$organizationId, $client, $proprietaire, $livreur];
    }

    /**
     * @return Collection<int, Vehicule>
     */
    private function vehiculesPartenaires(?string $organizationId, ?Proprietaire $proprietaire, ?Livreur $livreur): Collection
    {
        if ($organizationId === null) {
            return collect();
        }

        if ($proprietaire === null && $livreur === null) {
            return collect();
        }

        return Vehicule::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $query->orWhere('proprietaire_id', $proprietaire->id);
                }
                if ($livreur !== null) {
                    $query->orWhereHas('equipe.membres', fn ($sq) => $sq->where('livreur_id', $livreur->id));
                }
            })
            ->orderBy('nom_vehicule')
            ->get();
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
            ->where('organization_id', $organizationId)
            ->where('proprietaire_id', $proprietaire->id)
            ->orderBy('nom_vehicule')
            ->get();
    }

    /**
     * @return Collection<int, CommissionPart>
     */
    private function partsVentes(
        ?string $organizationId,
        ?Proprietaire $proprietaire,
        ?Livreur $livreur,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $statut = null,
        ?array $vehiculeIds = null
    ): Collection {
        if ($organizationId === null || ($proprietaire === null && $livreur === null)) {
            return collect();
        }

        return CommissionPart::query()
            ->with([
                'commission.commande:id,reference,validated_at,created_at',
                'commission.vehicule:id,nom_vehicule,immatriculation',
            ])
            ->whereHas('commission', fn ($query) => $query->where('organization_id', $organizationId))
            // tous les statuts sont actifs (impaye/partiel/paye)
            ->when($dateDebut, fn ($q) => $q->whereDate('created_at', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->whereDate('created_at', '<=', $dateFin))
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->when($vehiculeIds !== null, function ($q) use ($vehiculeIds) {
                if ($vehiculeIds === []) {
                    $q->whereRaw('1 = 0');

                    return;
                }
                $q->whereHas('commission', fn ($sq) => $sq->whereIn('vehicule_id', $vehiculeIds));
            })
            ->where(function ($query) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $query->orWhere(function ($sq) use ($proprietaire) {
                        $sq->where('type_beneficiaire', 'proprietaire')
                            ->where('proprietaire_id', $proprietaire->id);
                    });
                }

                if ($livreur !== null) {
                    $query->orWhere(function ($sq) use ($livreur) {
                        $sq->where('type_beneficiaire', 'livreur')
                            ->where('livreur_id', $livreur->id);
                    });
                }
            })
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, CommissionLogistiquePart>
     */
    private function partsLogistiques(
        ?string $organizationId,
        ?Proprietaire $proprietaire,
        ?Livreur $livreur,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $statut = null,
        ?array $vehiculeIds = null
    ): Collection {
        if ($organizationId === null || ($proprietaire === null && $livreur === null)) {
            return collect();
        }

        return CommissionLogistiquePart::query()
            ->with([
                'commission.transfert:id,reference,date_arrivee_reelle,created_at',
                'commission.vehicule:id,nom_vehicule,immatriculation',
            ])
            ->whereHas('commission', fn ($query) => $query->where('organization_id', $organizationId))
            // tous les statuts sont actifs (impaye/partiel/paye)
            ->when($dateDebut, fn ($q) => $q->whereDate('created_at', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->whereDate('created_at', '<=', $dateFin))
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->when($vehiculeIds !== null, function ($q) use ($vehiculeIds) {
                if ($vehiculeIds === []) {
                    $q->whereRaw('1 = 0');

                    return;
                }
                $q->whereHas('commission', fn ($sq) => $sq->whereIn('vehicule_id', $vehiculeIds));
            })
            ->where(function ($query) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $query->orWhere(function ($sq) use ($proprietaire) {
                        $sq->where('type_beneficiaire', 'proprietaire')
                            ->where('proprietaire_id', $proprietaire->id);
                    });
                }

                if ($livreur !== null) {
                    $query->orWhere(function ($sq) use ($livreur) {
                        $sq->where('type_beneficiaire', 'livreur')
                            ->where('livreur_id', $livreur->id);
                    });
                }
            })
            ->latest('id')
            ->get();
    }

    private function calculateEarnings(Collection $partsVentes, Collection $partsLogistiques, float $fraisDepensesTotal = 0.0): array
    {
        $totalEarned = round(
            (float) $partsVentes->sum('montant_net') + (float) $partsLogistiques->sum('montant_net'),
            2
        );
        $totalPaid = round(
            (float) $partsVentes->sum('montant_verse') + (float) $partsLogistiques->sum('montant_verse'),
            2
        );
        $frais = round($fraisDepensesTotal, 2);

        return [
            'total_earned' => $totalEarned,
            'total_paid' => $totalPaid,
            'frais_depenses_total' => $frais,
            'balance' => max(0, round($totalEarned - $frais - $totalPaid, 2)),
            'operations_count' => $partsVentes->count() + $partsLogistiques->count(),
        ];
    }

    private function earningsByVehicule(Collection $vehicules, Collection $partsVentes, Collection $partsLogistiques, array $fraisParVehicule = []): array
    {
        $stats = [];

        foreach ($vehicules as $vehicule) {
            $stats[$vehicule->id] = [
                'vehicule_id' => $vehicule->id,
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'frais_depenses' => (float) ($fraisParVehicule[$vehicule->id] ?? 0.0),
                'total_earned' => 0.0,
                'total_paid' => 0.0,
                'balance' => 0.0,
            ];
        }

        foreach ($partsVentes as $part) {
            $vehicule = $part->commission?->vehicule;
            if ($vehicule === null) {
                continue;
            }
            if (! isset($stats[$vehicule->id])) {
                $stats[$vehicule->id] = [
                    'vehicule_id' => $vehicule->id,
                    'nom_vehicule' => $vehicule->nom_vehicule,
                    'immatriculation' => $vehicule->immatriculation,
                    'frais_depenses' => (float) ($fraisParVehicule[$vehicule->id] ?? 0.0),
                    'total_earned' => 0.0,
                    'total_paid' => 0.0,
                    'balance' => 0.0,
                ];
            }
            $stats[$vehicule->id]['total_earned'] += (float) $part->montant_net;
            $stats[$vehicule->id]['total_paid'] += (float) $part->montant_verse;
        }

        foreach ($partsLogistiques as $part) {
            $vehicule = $part->commission?->vehicule;
            if ($vehicule === null) {
                continue;
            }
            if (! isset($stats[$vehicule->id])) {
                $stats[$vehicule->id] = [
                    'vehicule_id' => $vehicule->id,
                    'nom_vehicule' => $vehicule->nom_vehicule,
                    'immatriculation' => $vehicule->immatriculation,
                    'frais_depenses' => (float) ($fraisParVehicule[$vehicule->id] ?? 0.0),
                    'total_earned' => 0.0,
                    'total_paid' => 0.0,
                    'balance' => 0.0,
                ];
            }
            $stats[$vehicule->id]['total_earned'] += (float) $part->montant_net;
            $stats[$vehicule->id]['total_paid'] += (float) $part->montant_verse;
        }

        return collect($stats)
            ->map(function (array $row) {
                $row['total_earned'] = round((float) $row['total_earned'], 2);
                $row['total_paid'] = round((float) $row['total_paid'], 2);
                $row['frais_depenses'] = round((float) $row['frais_depenses'], 2);
                $row['balance'] = max(0, round($row['total_earned'] - $row['frais_depenses'] - $row['total_paid'], 2));

                return $row;
            })
            ->sortByDesc('total_earned')
            ->values()
            ->all();
    }

    /**
     * @return array<string, float> vehicule_id => frais total approuvé
     */
    private function fraisDepensesParVehicule(
        ?string $organizationId,
        ?Proprietaire $proprietaire,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?array $vehiculeIds = null
    ): array {
        if ($organizationId === null || $proprietaire === null) {
            return [];
        }

        $vehiculeIdsOwner = Vehicule::where('proprietaire_id', $proprietaire->id)
            ->where('organization_id', $organizationId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();
        $vehiculeIdsCibles = $vehiculeIdsOwner;

        if ($vehiculeIds !== null) {
            $vehiculeIdsCibles = $vehiculeIdsOwner->intersect($vehiculeIds)->values();
        }

        if ($vehiculeIdsCibles->isEmpty()) {
            return [];
        }

        return Depense::whereIn('vehicule_id', $vehiculeIdsCibles->all())
            ->where('statut', 'approuve')
            ->where('organization_id', $organizationId)
            ->when($dateDebut, fn ($q) => $q->whereDate('date_depense', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->whereDate('date_depense', '<=', $dateFin))
            ->selectRaw('vehicule_id, SUM(montant) as total')
            ->groupBy('vehicule_id')
            ->pluck('total', 'vehicule_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();
    }

    private function resolveDashboardFilters(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7j', '30j', 'ce_mois', 'mois_passe', 'custom'])],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'vehicule_id' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in(array_map(fn (StatutCommission $s) => $s->value, StatutCommission::cases()))],
        ]);

        $period = $validated['period'] ?? 'ce_mois';
        $dateDebut = $validated['date_debut'] ?? null;
        $dateFin = $validated['date_fin'] ?? null;

        if ($period !== 'custom') {
            [$dateDebut, $dateFin] = $this->periodToDates($period);
        }

        return [
            'period' => $period,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'vehicule_id' => $validated['vehicule_id'] ?? null,
            'statut' => $validated['statut'] ?? null,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function periodToDates(string $period): array
    {
        $today = Carbon::today();

        return match ($period) {
            '7j' => [
                $today->copy()->subDays(6)->toDateString(),
                $today->toDateString(),
            ],
            'ce_mois' => [
                $today->copy()->startOfMonth()->toDateString(),
                $today->toDateString(),
            ],
            'mois_passe' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            default => [
                $today->copy()->subDays(29)->toDateString(),
                $today->toDateString(),
            ],
        };
    }

    private function releve(Collection $partsVentes, Collection $partsLogistiques): array
    {
        $lignesVentes = $partsVentes->map(function (CommissionPart $part) {
            $commande = $part->commission?->commande;
            $vehicule = $part->commission?->vehicule;
            $date = $commande?->validated_at ?? $commande?->created_at ?? $part->created_at;

            return [
                'id' => 'vente-'.$part->id,
                'source' => 'Vente',
                'reference' => $commande?->reference ?? '-',
                'vehicule_id' => $vehicule?->id,
                'vehicule_nom' => $vehicule?->nom_vehicule ?? '-',
                'immatriculation' => $vehicule?->immatriculation,
                'date_label' => $date?->format('d/m/Y'),
                'date_sort' => $date?->timestamp ?? 0,
                'frais' => (float) $part->frais_supplementaires,
                'montant_net' => (float) $part->montant_net,
                'montant_verse' => (float) $part->montant_verse,
                'montant_restant' => max(0, (float) $part->montant_net - (float) $part->montant_verse),
                'statut' => $part->statut?->value ?? (string) $part->getRawOriginal('statut'),
                'statut_label' => $part->statut_label,
            ];
        });

        $lignesLogistiques = $partsLogistiques->map(function (CommissionLogistiquePart $part) {
            $transfert = $part->commission?->transfert;
            $vehicule = $part->commission?->vehicule;
            $date = $part->earned_at ?? $transfert?->date_arrivee_reelle ?? $transfert?->created_at ?? $part->created_at;

            return [
                'id' => 'log-'.$part->id,
                'source' => 'Logistique',
                'reference' => $transfert?->reference ?? '-',
                'vehicule_id' => $vehicule?->id,
                'vehicule_nom' => $vehicule?->nom_vehicule ?? '-',
                'immatriculation' => $vehicule?->immatriculation,
                'date_label' => $date?->format('d/m/Y'),
                'date_sort' => $date?->timestamp ?? 0,
                'frais' => (float) $part->frais_supplementaires,
                'montant_net' => (float) $part->montant_net,
                'montant_verse' => (float) $part->montant_verse,
                'montant_restant' => max(0, (float) $part->montant_net - (float) $part->montant_verse),
                'statut' => $part->statut?->value ?? (string) $part->getRawOriginal('statut'),
                'statut_label' => $part->statut_label,
            ];
        });

        return $lignesVentes
            ->concat($lignesLogistiques)
            ->sortByDesc('date_sort')
            ->values()
            ->take(100)
            ->map(function (array $row) {
                unset($row['date_sort']);

                return $row;
            })
            ->all();
    }
}
