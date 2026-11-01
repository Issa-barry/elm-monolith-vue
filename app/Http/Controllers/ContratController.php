<?php

namespace App\Http\Controllers;

use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use App\Http\Controllers\Rh\Contrats\VerifieAccesContrat;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Site;
use App\Services\Rh\ContratService;
use App\Services\Rh\DocumentContratService;
use App\Services\Rh\PdgService;
use App\Services\SiteScopeService;
use App\Support\Rh\PerimetreEmploye;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContratController extends Controller
{
    use VerifieAccesContrat;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contrat::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filters = $request->only(['statut_contrat', 'type_contrat']);
        $filtreSiteIds = $user->voitToutesLesAgences()
            ? array_values(array_filter((array) $request->input('site_ids', [])))
            : [];

        $query = Contrat::with(['employe:id,personne_id,matricule,site_id', 'employe.personne', 'documentCourant'])
            ->where('organization_id', $orgId);

        // Périmètre de consultation : contrats des employés de ses agences (ADR 0025).
        if (! $user->voitToutesLesAgences()) {
            $query->whereHas('employe', fn ($e) => app(SiteScopeService::class)->applyToQuery($e, $user));
        }

        if ($filtreSiteIds !== []) {
            $query->whereHas('employe', fn ($e) => $e->whereIn('site_id', $filtreSiteIds));
        }

        if (! empty($filters['statut_contrat'])) {
            $query->where('statut_contrat', $filters['statut_contrat']);
        }

        if (! empty($filters['type_contrat'])) {
            $query->where('type_contrat', $filters['type_contrat']);
        }

        // Mettre à jour le PDF (ADR 0048) : permissions et agence de rattachement du salarié, mêmes
        // règles que Rh\Contrats\VerifieAccesContrat — le lien n'est jamais affiché pour rien.
        $peutGenerer = $user->can('rh-contrats.update') && $user->can('rh-remunerations.read');
        $sitesEcriture = $user->agitSurToutesLesAgences() ? null : app(SiteScopeService::class)->assignedSiteIds($user);

        $contrats = $query->orderByDesc('date_debut')->get()
            ->map(fn (Contrat $c) => [
                ...$this->toRow($c),
                'peut_generer' => $peutGenerer && ($sitesEcriture === null || ($c->employe?->site_id !== null && $sitesEcriture->contains($c->employe->site_id))),
            ]);

        $sites = $user->voitToutesLesAgences()
            ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
            : $user->sites()->orderBy('sites.nom')->get(['sites.id', 'sites.nom']);

        return Inertia::render('Contrats/Index', [
            'contrats' => $contrats,
            'filters' => [...$filters, 'site_ids' => $filtreSiteIds],
            'sites' => $sites->map(fn (Site $s) => ['id' => $s->id, 'nom' => $s->nom])->values(),
            'type_contrat_options' => TypeContrat::options(),
            'statut_contrat_options' => StatutContrat::options(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Contrat::class);

        $orgId = auth()->user()->organization_id;

        // Salariés actifs des agences de rattachement : ceux pour qui la création serait acceptée.
        $user = auth()->user();
        $employes = Employe::with('personne')
            ->where('organization_id', $orgId)
            ->where('statut', 'actif')
            ->when(! $user->agitSurToutesLesAgences(), fn ($q) => $q->whereIn('site_id', app(SiteScopeService::class)->assignedSiteIds($user)))
            ->get(['id', 'personne_id', 'matricule'])
            ->sortBy('nom')
            ->map(fn ($e) => [
                'value' => $e->id,
                'label' => "{$e->prenom} {$e->nom} ({$e->matricule})",
            ])->values();

        $pdg = app(PdgService::class)->actuel($orgId);

        return Inertia::render('Contrats/Create', [
            'employes' => $employes,
            'type_contrat_options' => TypeContrat::options(),
            'statut_contrat_options' => StatutContrat::options(),
            'unites_periode_essai' => $this->unitesPeriodeEssai(),
            'employe_id_prefill' => $request->query('employe_id'),
            // Signataire repris automatiquement ; sans PDG, la création est refusée (ADR 0048).
            'pdg' => $pdg ? ['nom' => PdgService::nom($pdg), 'qualite' => PdgService::QUALITE] : null,
            'message_pdg_absent' => PdgService::MESSAGE_ABSENT,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Contrat::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403);

        $data = $this->sansSalaireNonAutorise($request->validate($this->rules($request, creation: true), $this->messages()));
        $this->exigerPerimetre(Employe::where('organization_id', $orgId)->find($data['employe_id']));

        // Pas de contrat sans PDG désigné, signataire de tous les contrats (ADR 0048) : le PDF
        // ne pourrait pas être produit, aucun autre salarié n'est pris à sa place.
        app(PdgService::class)->exiger($orgId);

        // Règle « un seul contrat actif », sous verrou de l'employé : commune avec l'import RH.
        $contrat = app(ContratService::class)->creer($this->normaliserConditions($data), $orgId);

        // PDF produit tout de suite ; un échec n'annule pas le contrat (nouvelle tentative possible).
        $erreur = app(DocumentContratService::class)->genererAutomatiquement($contrat, auth()->user());

        return $this->apresEnregistrement($contrat, 'Contrat créé.', $erreur);
    }

    public function edit(Contrat $contrat): Response
    {
        $this->authorize('update', $contrat);
        $this->exigerPerimetre($contrat->employe);

        $orgId = auth()->user()->organization_id;

        $employes = Employe::with('personne')
            ->where('organization_id', $orgId)
            ->get(['id', 'personne_id', 'matricule'])
            ->sortBy('nom')
            ->map(fn ($e) => [
                'value' => $e->id,
                'label' => "{$e->prenom} {$e->nom} ({$e->matricule})",
            ])->values();

        return Inertia::render('Contrats/Edit', [
            'contrat' => $this->toRow($contrat),
            'employes' => $employes,
            'type_contrat_options' => TypeContrat::options(),
            'statut_contrat_options' => StatutContrat::options(),
            'unites_periode_essai' => $this->unitesPeriodeEssai(),
        ]);
    }

    public function update(Request $request, Contrat $contrat): RedirectResponse
    {
        $this->authorize('update', $contrat);
        $this->exigerPerimetre($contrat->employe);

        // Organisation du contrat, pas celle de l'acteur : le Gate::before du super administrateur
        // laisse passer la Policy, l'employé cible doit rester dans l'organisation du contrat.
        $orgId = $contrat->organization_id;

        $data = $this->normaliserConditions($this->sansSalaireNonAutorise($request->validate($this->rules($request), $this->messages())));
        $this->exigerPerimetre(Employe::where('organization_id', $orgId)->find($data['employe_id']));

        // CDI => date_fin null
        if ($data['type_contrat'] === TypeContrat::CDI->value) {
            $data['date_fin'] = null;
        }

        // Statut non envoyé : le statut actuel est conservé (la colonne n'accepte pas null).
        if (($data['statut_contrat'] ?? null) === null) {
            unset($data['statut_contrat']);
        }

        $contrats = app(ContratService::class);

        DB::transaction(function () use ($contrat, $data, $orgId, $contrats) {
            $employe = $contrats->verrouillerEmploye($data['employe_id'], $orgId);

            $statutFinal = $data['statut_contrat'] ?? $contrat->statut_contrat->value;
            if ($statutFinal === StatutContrat::ACTIF->value) {
                $contrats->assertAucunAutreContratActif($employe->id, $contrat->id);
                // Réactiver un contrat d'un salarié sorti équivaudrait à une réembauche non définie.
                if ($contrat->statut_contrat !== StatutContrat::ACTIF) {
                    $contrats->assertPasSorti($employe);
                }
            }
            // Un contrat terminé sans date de fin n'a pas de période connue (ignoré comme dans la paie).
            if ($statutFinal === StatutContrat::ACTIF->value || ($data['date_fin'] ?? null) !== null) {
                $contrats->assertPasDeChevauchement($employe->id, $data['date_debut'], $data['date_fin'] ?? null, $contrat->id);
            }

            $contrat->update($data);
        });

        // PDF remis à jour tout de suite. Après une version signée, une nouvelle version (à faire
        // signer) n'est préparée que si une clause du contrat a réellement changé.
        $erreur = $contrat->statut_contrat === StatutContrat::ACTIF
            ? app(DocumentContratService::class)->genererAutomatiquement($contrat, auth()->user(), apresSignature: $contrat->wasChanged(DocumentContratService::CHAMPS_CONTRACTUELS))
            : null;

        return $this->apresEnregistrement($contrat, 'Contrat mis à jour.', $erreur);
    }

    /**
     * Vers la page du contrat (PDF, versions) pour qui peut la consulter, sinon vers la fiche du
     * salarié comme avant. Un échec du PDF est dit en clair, le contrat reste enregistré.
     */
    private function apresEnregistrement(Contrat $contrat, string $succes, ?string $erreurPdf): RedirectResponse
    {
        $redirection = $this->aAccesContrat(auth()->user(), $contrat)
            ? redirect()->route('contrats.document', $contrat)
            : redirect()->route('employes.edit', $contrat->employe_id);

        if ($erreurPdf !== null) {
            return $redirection->with('success', $succes)->with('error', 'Le PDF du contrat n’a pas pu être préparé. '.$erreurPdf);
        }

        return $redirection->with('success', $contrat->statut_contrat === StatutContrat::ACTIF
            ? $succes.' Le PDF du contrat est prêt ; il reste à le faire signer.'
            : $succes);
    }

    /** Sans période d'essai (0), l'unité n'a pas de sens : elle est vidée. */
    private function normaliserConditions(array $data): array
    {
        if (array_key_exists('periode_essai_duree', $data) && in_array($data['periode_essai_duree'], [null, 0, '0'], true)) {
            $data['periode_essai_unite'] = null;
        }
        if (($data['type_contrat'] ?? null) === TypeContrat::CDI->value) {
            $data['motif_cdd'] = null;
        }

        return $data;
    }

    /** @return list<array{value: string, label: string}> */
    private function unitesPeriodeEssai(): array
    {
        return collect(Contrat::UNITES_PERIODE_ESSAI)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    public function destroy(Contrat $contrat): RedirectResponse
    {
        $this->authorize('delete', $contrat);
        $this->exigerPerimetre($contrat->employe);

        $employeId = $contrat->employe_id;
        $contrat->delete();

        return redirect()->route('employes.edit', $employeId)
            ->with('success', 'Contrat supprimé.');
    }

    /**
     * Créer, modifier ou supprimer un contrat : salarié d'une agence de rattachement (PerimetreEmploye),
     * comme la fiche employé. Un employé introuvable est laissé à ContratService (404).
     */
    private function exigerPerimetre(?Employe $employe): void
    {
        abort_if($employe !== null && ! PerimetreEmploye::peutModifier(auth()->user(), $employe), 403);
    }

    /**
     * L'appartenance de l'employé à l'organisation est contrôlée par ContratService::verrouillerEmploye() (404, comme
     * à la création), pas ici.
     */
    private function rules(Request $request, bool $creation = false): array
    {
        $isCdd = $request->input('type_contrat') === TypeContrat::CDD->value;
        // Conditions reprises dans le PDF (ADR 0048) : obligatoires à la création ; facultatives en
        // modification pour les contrats antérieurs (le PDF signale alors ce qui manque).
        $requis = $creation ? 'required' : 'nullable';

        return [
            'periode_essai_duree' => [$requis, 'integer', 'min:0', 'max:99'],
            'periode_essai_unite' => [
                Rule::requiredIf(fn () => (int) $request->input('periode_essai_duree') > 0),
                'nullable', Rule::in(array_keys(Contrat::UNITES_PERIODE_ESSAI)),
            ],
            'heures_hebdomadaires' => [$requis, 'numeric', 'gt:0', 'max:168', 'decimal:0,2'],
            'motif_cdd' => [$creation && $isCdd ? 'required' : 'nullable', 'string', 'max:255'],
            'employe_id' => ['required', 'exists:employes,id'],
            'type_contrat' => ['required', Rule::in(TypeContrat::values())],
            'date_debut' => 'required|date',
            'date_fin' => $isCdd
                ? ['required', 'date', 'after_or_equal:date_debut']
                : ['nullable', 'date', 'after_or_equal:date_debut'],
            'salaire_base' => 'nullable|numeric|min:0',
            'statut_contrat' => ['nullable', Rule::in(StatutContrat::values())],
        ];
    }

    private function messages(): array
    {
        return [
            'employe_id.required' => "L'employé est obligatoire.",
            'employe_id.exists' => 'Employé introuvable.',
            'type_contrat.required' => 'Le type de contrat est obligatoire.',
            'type_contrat.in' => 'Type de contrat invalide.',
            'date_debut.required' => 'La date de début est obligatoire.',
            'date_fin.required' => 'La date de fin est obligatoire pour un CDD.',
            'date_fin.after_or_equal' => 'La date de fin doit être égale ou postérieure à la date de début.',
            'salaire_base.numeric' => 'Le salaire doit être un nombre.',
            'salaire_base.min' => 'Le salaire ne peut pas être négatif.',
            'periode_essai_duree.required' => 'Indiquez la durée de la période d’essai (0 s’il n’y en a pas).',
            'periode_essai_duree.integer' => 'La durée de la période d’essai doit être un nombre entier, sans lettres.',
            'periode_essai_duree.min' => 'La durée de la période d’essai ne peut pas être négative.',
            'periode_essai_duree.max' => 'La durée de la période d’essai ne peut pas dépasser 99.',
            'periode_essai_unite.required' => 'Choisissez « Semaines » ou « Mois » pour la période d’essai.',
            'periode_essai_unite.in' => 'Choisissez « Semaines » ou « Mois » pour la période d’essai.',
            'heures_hebdomadaires.required' => 'Indiquez le nombre d’heures de travail par semaine.',
            'heures_hebdomadaires.numeric' => 'Le nombre d’heures doit être un nombre, sans lettres.',
            'heures_hebdomadaires.gt' => 'Le nombre d’heures doit être supérieur à 0.',
            'heures_hebdomadaires.max' => 'Le nombre d’heures par semaine ne peut pas dépasser 168.',
            'heures_hebdomadaires.decimal' => 'Le nombre d’heures accepte au plus deux chiffres après la virgule.',
            'motif_cdd.required' => 'Indiquez le motif du CDD (ex. remplacement d’un salarié absent).',
            'motif_cdd.max' => 'Le motif du CDD est limité à 255 caractères.',
        ];
    }

    private function toRow(Contrat $c): array
    {
        $c->loadMissing(['employe:id,personne_id,matricule', 'employe.personne']);

        return [
            'id' => $c->id,
            'employe_id' => $c->employe_id,
            'employe_nom_complet' => $c->employe ? trim("{$c->employe->prenom} {$c->employe->nom}") : null,
            'employe_matricule' => $c->employe?->matricule,
            'type_contrat' => $c->type_contrat->value,
            'type_contrat_label' => $c->type_contrat->label(),
            'statut_contrat' => $c->statut_contrat->value,
            'statut_contrat_label' => $c->statut_contrat->label(),
            'date_debut' => $c->date_debut?->format('Y-m-d'),
            'date_fin' => $c->date_fin?->format('Y-m-d'),
            // Salaire : seulement avec `rh-remunerations.read`.
            'salaire_base' => auth()->user()?->can('rh-remunerations.read') ? $c->salaire_base : null,
            'periode_essai_duree' => $c->periode_essai_duree,
            'periode_essai_unite' => $c->periode_essai_unite,
            'heures_hebdomadaires' => $c->heures_hebdomadaires,
            'motif_cdd' => $c->motif_cdd,
            // Dernière version générée du contrat de travail (ADR 0044), null tant qu'aucune.
            'document_courant' => $c->relationLoaded('documentCourant') && $c->documentCourant ? [
                'version' => $c->documentCourant->version,
                'statut' => $c->documentCourant->statut->value,
                'statut_label' => $c->documentCourant->statut->label(),
            ] : null,
        ];
    }

    /**
     * Le salaire ne se saisit qu'avec `rh-remunerations.read` : sans cette permission, la valeur
     * envoyée est ignorée (le salaire existant reste intact en modification).
     */
    private function sansSalaireNonAutorise(array $data): array
    {
        if (! auth()->user()->can('rh-remunerations.read')) {
            unset($data['salaire_base']);
        }

        return $data;
    }
}
