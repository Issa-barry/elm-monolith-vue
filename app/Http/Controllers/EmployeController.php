<?php

namespace App\Http\Controllers;

use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeContrat;
use App\Enums\TypeEmploye;
use App\Models\Employe;
use App\Models\EmployeCoordonneesPaiement;
use App\Models\FonctionRh;
use App\Models\Personne;
use App\Models\ServiceRh;
use App\Models\Site;
use App\Models\User;
use App\Services\MatriculeService;
use App\Services\PhoneCountryInfo;
use App\Services\Rh\EmployeAffectationService;
use App\Services\SiteScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeController extends Controller
{
    /**
     * Résout un téléphone saisi via PhoneCountryInput.vue (numéro complet, ex: "+224613855281")
     * en composantes pays — même mécanisme que InstallationService::resolveTelephone(), pas de
     * second champ `code_pays` à valider séparément (à la différence de PhoneHandlerTrait, conçu
     * pour des formulaires à deux champs comme ProprietaireController). Lève une erreur de
     * validation explicite plutôt que de laisser passer un numéro non exploitable.
     *
     * @return array{telephone: string, code_pays: string, pays: string, code_phone_pays: string}
     */
    private function resolveTelephone(string $telephone): array
    {
        $info = PhoneCountryInfo::resolve($telephone);

        if ($info === null) {
            throw ValidationException::withMessages([
                'telephone' => 'Numéro invalide ou indicatif non reconnu. Utilisez le format international (ex : +224613855281).',
            ]);
        }

        return [
            'telephone' => $info['telephone'],
            'code_pays' => $info['code_pays'],
            'pays' => $info['pays'],
            'code_phone_pays' => $info['indicatif'],
        ];
    }

    /**
     * Un numéro déjà utilisé par une AUTRE Personne de l'organisation ferait échouer
     * `$employe->personne->update()` sur la contrainte unique (organization_id,
     * telephone_normalise) avec une QueryException brute (500) plutôt qu'une erreur de formulaire
     * — cf. personnes table. Pas de garde équivalente côté store() : Personne::resoudreOuCreer()
     * gère déjà ce cas en RATTACHANT la Personne existante (une même personne pouvant cumuler
     * plusieurs rôles), seul un second Employe sur cette Personne est rejeté
     * (cf. assertPasDejaEmploye()).
     */
    private function assertTelephoneUniqueInOrg(string $telephone, string $orgId, string $ignorePersonneId): void
    {
        $exists = Personne::where('organization_id', $orgId)
            ->where('telephone_normalise', Personne::normaliserTelephone($telephone))
            ->where('id', '!=', $ignorePersonneId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro de téléphone est déjà utilisé par une autre personne de votre organisation.',
            ]);
        }
    }

    private function siteOptions(string $orgId): array
    {
        return Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->nom} ({$s->code})"])
            ->values()
            ->all();
    }

    private function fonctionOptions(string $orgId): array
    {
        return FonctionRh::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('libelle')
            ->get(['id', 'libelle', 'code'])
            ->map(fn (FonctionRh $f) => ['value' => $f->id, 'label' => $f->libelle])
            ->values()
            ->all();
    }

    private function serviceOptions(string $orgId, ?string $serviceActuelId = null): array
    {
        // Services actifs, plus le service actuel de l'employé même s'il a été désactivé depuis.
        return ServiceRh::where('organization_id', $orgId)
            ->where(fn ($q) => $q->where('is_active', true)->when($serviceActuelId, fn ($q2) => $q2->orWhere('id', $serviceActuelId)))
            ->orderBy('libelle')
            ->get(['id', 'libelle'])
            ->map(fn (ServiceRh $s) => ['value' => $s->id, 'label' => $s->libelle])
            ->values()
            ->all();
    }

    /** Agences proposées par le filtre Agence : toutes, ou celles de l'utilisateur. */
    private function siteFiltreOptions(User $user, string $orgId): array
    {
        $sites = $user->voitToutesLesAgences()
            ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
            : $user->sites()->orderBy('sites.nom')->get(['sites.id', 'sites.nom']);

        return $sites->map(fn (Site $s) => ['id' => $s->id, 'nom' => $s->nom])->values()->all();
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employe::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filters = $request->only(['statut', 'type_employe', 'type_contrat', 'service_rh_id', 'search']);
        $filtreSiteIds = $user->voitToutesLesAgences()
            ? array_values(array_filter((array) $request->input('site_ids', [])))
            : [];

        $query = Employe::with(['personne', 'site:id,nom,code', 'fonction:id,libelle', 'service:id,libelle', 'contratActif'])
            ->where('organization_id', $orgId);

        // Périmètre de consultation : ses agences, sauf vision de toutes les agences (ADR 0025).
        app(SiteScopeService::class)->applyToQuery($query, $user);

        if ($filtreSiteIds !== []) {
            $query->whereIn('site_id', $filtreSiteIds);
        }

        if (! empty($filters['service_rh_id'])) {
            $query->where('service_rh_id', $filters['service_rh_id']);
        }

        if (! empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }

        if (! empty($filters['type_employe'])) {
            $query->where('type_employe', $filters['type_employe']);
        }

        if (! empty($filters['type_contrat'])) {
            $query->whereHas('contrats', function ($q) use ($filters) {
                $q->where('type_contrat', $filters['type_contrat'])
                    ->where('statut_contrat', StatutContrat::ACTIF->value);
            });
        }

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('matricule', $s)
                    ->orWhereHas('personne', function ($q2) use ($s) {
                        $q2->where('nom', 'like', "%{$s}%")
                            ->orWhere('prenom', 'like', "%{$s}%")
                            ->orWhere('telephone', 'like', "%{$s}%")
                            ->orWhere('email', 'like', "%{$s}%");
                    });
            });
        }

        $employes = $query->get()
            ->sortBy(['nom', 'prenom'])
            ->map(fn (Employe $e) => $this->toRow($e))
            ->values();

        return Inertia::render('Employes/Index', [
            'employes' => $employes,
            'filters' => [...$filters, 'site_ids' => $filtreSiteIds],
            'sites' => $this->siteFiltreOptions($user, $orgId),
            'statut_options' => StatutEmploye::options(),
            'type_employe_options' => TypeEmploye::options(),
            'type_contrat_options' => TypeContrat::options(),
            'service_options' => $this->serviceOptions($orgId),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Employe::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Employes/Create', [
            'type_employe_options' => TypeEmploye::options(),
            'statut_options' => StatutEmploye::options(),
            'sites' => $this->siteOptions($orgId),
            'fonctions' => $this->fonctionOptions($orgId),
            'services' => $this->serviceOptions($orgId),
            'mode_paiement_options' => $this->modePaiementOptions(),
        ]);
    }

    /** Remplace unique:employes,email — email vit désormais sur personnes. */
    private function assertEmailUniqueInOrg(?string $email, string $orgId, ?string $ignoreEmployeId = null): void
    {
        if (! $email) {
            return;
        }

        // Même forme que Personne::setEmailAttribute() — ne pas dépendre de la collation SGBD.
        $email = strtolower(trim($email));

        $exists = Employe::where('organization_id', $orgId)
            ->whereHas('personne', fn ($q) => $q->where('email', $email))
            ->when($ignoreEmployeId, fn ($q) => $q->where('id', '!=', $ignoreEmployeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => 'Cette adresse e-mail est déjà utilisée.',
            ]);
        }
    }

    /**
     * Une Personne porte au plus un Employe actif (Personne::employe() est un hasOne, et
     * AccountValidationService rattache le compte au premier trouvé) : un second dossier RH
     * dédoublerait la paie et l'historique d'affectation. Un employé supprimé (soft delete)
     * ne bloque pas une réembauche.
     */
    private function assertPasDejaEmploye(Personne $personne, string $orgId): void
    {
        $existant = Employe::where('organization_id', $orgId)
            ->where('personne_id', $personne->id)
            ->first();

        if ($existant !== null) {
            throw ValidationException::withMessages([
                'telephone' => "Cette personne est déjà enregistrée comme employé ({$existant->nom_complet}, matricule {$existant->matricule}).",
            ]);
        }
    }

    /**
     * Personne réutilisée depuis un autre rôle (livreur créé au seul téléphone, client avec
     * nom_complet uniquement...) : on complète seulement les champs vides avec la saisie RH,
     * jamais d'écrasement d'une valeur déjà connue — l'employé n'existerait sinon que par un
     * nom vide.
     */
    private function completerIdentiteManquante(Personne $personne, array $identite): void
    {
        $manquants = array_filter(
            $identite,
            fn ($valeur, $champ) => filled($valeur) && blank($personne->getAttribute($champ)),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($manquants !== []) {
            $personne->update($manquants);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Employe::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403);

        $data = $request->validate($this->regles($orgId), $this->messages());

        $this->assertEmailUniqueInOrg($data['email'] ?? null, $orgId);

        $telephoneInfo = filled($data['telephone'] ?? null) ? $this->resolveTelephone($data['telephone']) : null;

        $identite = [
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'email' => $data['email'] ?? null,
            ...$this->identiteCivile($data),
        ];

        $employe = DB::transaction(function () use ($orgId, $data, $identite, $telephoneInfo) {
            $personne = Personne::resoudreOuCreer($orgId, [
                ...$identite,
                'telephone' => $telephoneInfo['telephone'] ?? null,
                'code_pays' => $telephoneInfo['code_pays'] ?? null,
                'pays' => $telephoneInfo['pays'] ?? null,
                'code_phone_pays' => $telephoneInfo['code_phone_pays'] ?? null,
            ]);

            // Verrou sur la Personne : deux créations simultanées avec le même téléphone ne
            // peuvent pas toutes les deux passer assertPasDejaEmploye().
            $personne = Personne::whereKey($personne->id)->lockForUpdate()->firstOrFail();
            $this->assertPasDejaEmploye($personne, $orgId);
            $this->completerIdentiteManquante($personne, $identite);

            $employe = Employe::create([
                'organization_id' => $orgId,
                'personne_id' => $personne->id,
                'matricule' => app(MatriculeService::class)->generate($orgId, Employe::class),
                'type_employe' => $data['type_employe'],
                'statut' => $data['statut'],
                ...$this->dossierEmploye($data),
            ]);

            $this->enregistrerDonneesSensibles($employe, $data);

            // Le site/la fonction passent TOUJOURS par EmployeAffectationService — jamais une
            // écriture directe de employes.site_id ici, sans quoi le cache et l'historique
            // divergeraient dès la création (cf. plan §2).
            $site = isset($data['site_id']) ? Site::find($data['site_id']) : null;
            $fonction = isset($data['fonction_rh_id']) ? FonctionRh::find($data['fonction_rh_id']) : null;
            if ($site !== null) {
                app(EmployeAffectationService::class)->definir($employe, $site, $fonction, auth()->user());
            }

            return $employe;
        });

        return redirect()->route('employes.edit', $employe)
            ->with('success', "{$employe->nom_complet} a été créé avec succès.");
    }

    /**
     * Fiche employé en lecture seule : identité, fonction/site actuels, contrat + historique,
     * historique d'affectation, compte applicatif et profil d'accès CLAIREMENT séparés de la
     * fonction RH (jamais la même notion) — aucune commission n'intervient plus ici (la
     * commission est désormais attribuée directement au site, jamais à un employé/gérant).
     */
    public function show(Employe $employe): Response
    {
        $this->authorize('view', $employe);

        $employe->load(['personne.user.roles']);

        return Inertia::render('Employes/Show', [
            'employe' => array_merge($this->toDetail($employe), [
                'compte' => $this->compteInfo($employe),
            ]),
        ]);
    }

    /**
     * null = aucun compte applicatif rattaché (cas normal : un employé n'a jamais besoin d'un
     * compte pour exister côté RH, cf. mission §"jamais un compte utilisateur requis") — la vue
     * affiche alors explicitement « Aucun compte », jamais une section vide silencieuse.
     */
    private function compteInfo(Employe $employe): ?array
    {
        $user = $employe->personne?->user;
        if ($user === null) {
            return null;
        }

        $role = $user->roles->first();
        $pending = $user->isPendingValidation();

        return [
            'id' => $user->id,
            'email' => $user->email,
            // Profil d'accès (rôle Spatie) — toujours affiché séparément de la fonction RH,
            // jamais fusionné : deux notions distinctes (cf. mission).
            'role_label' => $role ? ($role->label ?? $role->name) : null,
            'statut' => $pending ? 'pending_validation' : ($user->is_active ? 'actif' : 'inactif'),
            'statut_label' => $pending ? 'En attente de validation' : ($user->is_active ? 'Actif' : 'Inactif'),
        ];
    }

    public function edit(Employe $employe): Response
    {
        $this->authorize('update', $employe);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Employes/Edit', [
            'employe' => $this->toDetail($employe),
            'type_employe_options' => TypeEmploye::options(),
            'statut_options' => StatutEmploye::options(),
            'sites' => $this->siteOptions($orgId),
            'fonctions' => $this->fonctionOptions($orgId),
            'services' => $this->serviceOptions($orgId, $employe->service_rh_id),
            'mode_paiement_options' => $this->modePaiementOptions(),
        ]);
    }

    public function update(Request $request, Employe $employe): RedirectResponse
    {
        $this->authorize('update', $employe);

        // Organisation de l'employé, pas celle de l'acteur : le Gate::before du super
        // administrateur laisse passer la Policy.
        $orgId = $employe->organization_id;

        $data = $request->validate($this->regles($orgId), $this->messages());

        $this->assertEmailUniqueInOrg($data['email'] ?? null, $orgId, $employe->id);

        $telephoneInfo = filled($data['telephone'] ?? null) ? $this->resolveTelephone($data['telephone']) : null;
        if ($telephoneInfo !== null) {
            $this->assertTelephoneUniqueInOrg($telephoneInfo['telephone'], $orgId, $employe->personne_id);
        } else {
            $this->assertTelephonePeutEtreEfface($employe->personne);
        }

        DB::transaction(function () use ($employe, $data, $telephoneInfo) {
            $employe->personne->update([
                'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
                'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
                'email' => $data['email'] ?? null,
                'telephone' => $telephoneInfo['telephone'] ?? null,
                'telephone_normalise' => $telephoneInfo !== null ? Personne::normaliserTelephone($telephoneInfo['telephone']) : null,
                'code_pays' => $telephoneInfo['code_pays'] ?? null,
                'pays' => $telephoneInfo['pays'] ?? null,
                'code_phone_pays' => $telephoneInfo['code_phone_pays'] ?? null,
                ...$this->identiteCivile($data),
            ]);

            $employe->update([
                'type_employe' => $data['type_employe'],
                'statut' => $data['statut'],
                ...$this->dossierEmploye($data),
            ]);

            $this->enregistrerDonneesSensibles($employe, $data);

            // cf. store() : jamais d'écriture directe de employes.site_id/fonction_rh_id, toujours
            // via EmployeAffectationService (cache + historique synchronisés en un seul appel).
            $site = isset($data['site_id']) ? Site::find($data['site_id']) : null;
            $fonction = isset($data['fonction_rh_id']) ? FonctionRh::find($data['fonction_rh_id']) : null;
            app(EmployeAffectationService::class)->definir($employe, $site, $fonction, auth()->user());
        });

        return redirect()->route('employes.edit', $employe)
            ->with('success', "{$employe->nom_complet} a été mis à jour.");
    }

    /**
     * Règles communes à la création et à la modification. Les champs du dossier sont tous
     * facultatifs : les dossiers existants restent valides sans saisie complémentaire.
     */
    private function regles(string $orgId): array
    {
        return [
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => 'nullable|string|max:50',
            'type_employe' => ['required', Rule::in(TypeEmploye::values())],
            'site_id' => ['nullable', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'fonction_rh_id' => ['nullable', Rule::exists('fonctions_rh', 'id')->where('organization_id', $orgId)],
            'service_rh_id' => ['nullable', Rule::exists('services_rh', 'id')->where('organization_id', $orgId)],
            'statut' => ['required', Rule::in(StatutEmploye::values())],
            // Identité civile (portée par Personne)
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'lieu_naissance' => ['nullable', 'string', 'max:100'],
            'sexe' => ['nullable', Rule::in(['M', 'F'])],
            'nationalite' => ['nullable', 'string', 'max:60'],
            // Dossier
            'date_embauche' => ['nullable', 'date'],
            'date_sortie' => ['nullable', 'date', 'after_or_equal:date_embauche'],
            'motif_sortie' => ['nullable', 'string', 'max:255'],
            'contact_urgence_nom' => ['nullable', 'string', 'max:150'],
            'contact_urgence_lien' => ['nullable', 'string', 'max:50'],
            'contact_urgence_telephone' => ['nullable', 'string', 'max:30'],
            // Données sensibles : ignorées sans `rh-employes.donnees_sensibles` (cf. enregistrerDonneesSensibles())
            'numero_cnss' => ['nullable', 'string', 'max:30'],
            'paiement_mode' => ['nullable', Rule::in(array_keys(EmployeCoordonneesPaiement::MODES))],
            'paiement_etablissement' => ['nullable', 'string', 'max:100'],
            'paiement_numero_compte' => ['nullable', 'string', 'max:60'],
            'paiement_titulaire' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @return array<string, mixed> */
    private function identiteCivile(array $data): array
    {
        return [
            'date_naissance' => $data['date_naissance'] ?? null,
            'lieu_naissance' => $data['lieu_naissance'] ?? null,
            'sexe' => $data['sexe'] ?? null,
            'nationalite' => $data['nationalite'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function dossierEmploye(array $data): array
    {
        return [
            'service_rh_id' => $data['service_rh_id'] ?? null,
            'date_embauche' => $data['date_embauche'] ?? null,
            'date_sortie' => $data['date_sortie'] ?? null,
            'motif_sortie' => $data['motif_sortie'] ?? null,
            'contact_urgence_nom' => $data['contact_urgence_nom'] ?? null,
            'contact_urgence_lien' => $data['contact_urgence_lien'] ?? null,
            'contact_urgence_telephone' => $data['contact_urgence_telephone'] ?? null,
        ];
    }

    /**
     * N° CNSS et coordonnées de paiement : seulement avec `rh-employes.donnees_sensibles`. Sans cette
     * permission, les valeurs envoyées sont ignorées et les données existantes restent intactes.
     * Mode de paiement vide = coordonnées supprimées.
     */
    private function enregistrerDonneesSensibles(Employe $employe, array $data): void
    {
        if (! auth()->user()->can('rh-employes.donnees_sensibles')) {
            return;
        }

        $employe->update(['numero_cnss' => $data['numero_cnss'] ?? null]);

        if (blank($data['paiement_mode'] ?? null)) {
            $employe->coordonneesPaiement()->delete();

            return;
        }

        $employe->coordonneesPaiement()->updateOrCreate(
            ['employe_id' => $employe->id],
            [
                'organization_id' => $employe->organization_id,
                'mode' => $data['paiement_mode'],
                'etablissement' => $data['paiement_etablissement'] ?? null,
                'numero_compte' => $data['paiement_numero_compte'] ?? null,
                'titulaire' => $data['paiement_titulaire'] ?? null,
            ],
        );
    }

    /**
     * Effacer le téléphone depuis la fiche employé est refusé quand la personne a aussi un autre rôle
     * (compte, client, livreur…) : ce numéro sert à la reconnaître partout dans l'application.
     */
    private function assertTelephonePeutEtreEfface(Personne $personne): void
    {
        if (blank($personne->telephone)) {
            return;
        }

        $autresRoles = collect([
            'user' => 'compte utilisateur', 'client' => 'client', 'livreur' => 'livreur',
            'proprietaire' => 'propriétaire', 'fournisseur' => 'fournisseur',
            'prestataire' => 'prestataire', 'parrain' => 'parrain',
        ])->filter(fn (string $libelle, string $relation) => $personne->{$relation}()->exists());

        if ($autresRoles->isNotEmpty()) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro identifie aussi cette personne comme '.$autresRoles->implode(', ')
                    .' : il ne peut pas être effacé depuis la fiche employé (vous pouvez le remplacer).',
            ]);
        }
    }

    private function modePaiementOptions(): array
    {
        return collect(EmployeCoordonneesPaiement::MODES)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * Transfert explicite de site depuis l'écran RH (distinct de update() : action ciblée, avec
     * son propre motif "transfert", conserve la fonction actuelle de l'employé inchangée).
     */
    public function transfererSite(Request $request, Employe $employe): RedirectResponse
    {
        $this->authorize('update', $employe);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'site_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
        ], [
            'site_id.required' => 'Le site est obligatoire.',
        ]);

        $site = Site::findOrFail($data['site_id']);

        app(EmployeAffectationService::class)->definir($employe, $site, $employe->fonction, auth()->user(), 'transfert');

        return redirect()->route('employes.edit', $employe)
            ->with('success', "{$employe->nom_complet} a été transféré vers {$site->nom}.");
    }

    public function destroy(Employe $employe): RedirectResponse
    {
        $this->authorize('delete', $employe);

        $employe->delete();

        return redirect()->route('employes.index')
            ->with('success', "{$employe->nom_complet} a été supprimé.");
    }

    private function toRow(Employe $e): array
    {
        $contrat = $e->contratActif;

        return [
            'id' => $e->id,
            'matricule' => $e->matricule,
            'nom_complet' => $e->nom_complet,
            'nom' => $e->nom,
            'prenom' => $e->prenom,
            'email' => $e->email,
            'telephone' => $e->telephone,
            'type_employe' => $e->type_employe->value,
            'type_employe_label' => $e->type_employe->label(),
            'statut' => $e->statut->value,
            'statut_label' => $e->statut->label(),
            'site_id' => $e->site_id,
            'site' => $e->site ? "{$e->site->nom} ({$e->site->code})" : null,
            'fonction_rh_id' => $e->fonction_rh_id,
            'fonction_libelle' => $e->fonction?->libelle,
            'service_rh_id' => $e->service_rh_id,
            'service_libelle' => $e->service?->libelle,
            'date_embauche' => $e->date_embauche?->format('d/m/Y'),
            'contrat_actif' => $contrat ? [
                'id' => $contrat->id,
                'type_contrat' => $contrat->type_contrat->value,
                'type_contrat_label' => $contrat->type_contrat->label(),
                'date_debut' => $contrat->date_debut?->format('d/m/Y'),
                'date_fin' => $contrat->date_fin?->format('d/m/Y'),
            ] : null,
        ];
    }

    private function toDetail(Employe $employe): array
    {
        $employe->load([
            'personne', 'site:id,nom,code', 'fonction:id,libelle', 'service:id,libelle', 'contratActif',
            'contrats' => fn ($q) => $q->orderByDesc('date_debut'),
            'affectations.site:id,nom,code', 'affectations.fonction:id,libelle',
        ]);

        $user = auth()->user();
        $voitSalaires = $user->can('rh-remunerations.read');
        $personne = $employe->personne;

        return array_merge($this->toRow($employe), [
            // Identité civile
            'date_naissance' => $personne?->date_naissance?->format('Y-m-d'),
            'lieu_naissance' => $personne?->lieu_naissance,
            'sexe' => $personne?->sexe,
            'nationalite' => $personne?->nationalite,
            // Dossier (date au format du champ de saisie ; l'affichage la reformate)
            'date_embauche_iso' => $employe->date_embauche?->format('Y-m-d'),
            'date_sortie' => $employe->date_sortie?->format('Y-m-d'),
            'motif_sortie' => $employe->motif_sortie,
            'contact_urgence_nom' => $employe->contact_urgence_nom,
            'contact_urgence_lien' => $employe->contact_urgence_lien,
            'contact_urgence_telephone' => $employe->contact_urgence_telephone,
            // Absent (null) sans la permission : jamais envoyé au navigateur.
            'donnees_sensibles' => $user->can('rh-employes.donnees_sensibles') ? $this->donneesSensibles($employe) : null,
            'contrats' => $employe->contrats->map(fn ($c) => [
                'id' => $c->id,
                'type_contrat' => $c->type_contrat->value,
                'type_contrat_label' => $c->type_contrat->label(),
                'statut_contrat' => $c->statut_contrat->value,
                'statut_contrat_label' => $c->statut_contrat->label(),
                'date_debut' => $c->date_debut?->format('d/m/Y'),
                'date_fin' => $c->date_fin?->format('d/m/Y'),
                // Salaire : seulement avec `rh-remunerations.read`.
                'salaire_base' => $voitSalaires ? $c->salaire_base : null,
            ])->values(),
            // Historique d'affectation (site + fonction) — le plus récent en premier, cf.
            // Employe::affectations() (orderByDesc('debut_at')).
            'affectations' => $employe->affectations->map(fn ($a) => [
                'id' => $a->id,
                'site' => $a->site ? "{$a->site->nom} ({$a->site->code})" : null,
                'fonction_libelle' => $a->fonction?->libelle,
                'debut_at' => $a->debut_at?->format('d/m/Y H:i'),
                'fin_at' => $a->fin_at?->format('d/m/Y H:i'),
                'active' => $a->fin_at === null,
                'motif' => $a->motif,
            ])->values(),
        ]);
    }

    /** @return array{numero_cnss: ?string, paiement_mode: ?string, paiement_mode_label: ?string, paiement_etablissement: ?string, paiement_numero_compte: ?string, paiement_titulaire: ?string} */
    private function donneesSensibles(Employe $employe): array
    {
        $coordonnees = $employe->coordonneesPaiement;

        return [
            'numero_cnss' => $employe->numero_cnss,
            'paiement_mode' => $coordonnees?->mode,
            'paiement_mode_label' => $coordonnees ? (EmployeCoordonneesPaiement::MODES[$coordonnees->mode] ?? $coordonnees->mode) : null,
            'paiement_etablissement' => $coordonnees?->etablissement,
            'paiement_numero_compte' => $coordonnees?->numero_compte,
            'paiement_titulaire' => $coordonnees?->titulaire,
        ];
    }

    private function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email' => "L'adresse e-mail est invalide.",
            'type_employe.required' => 'Le type d\'employé est obligatoire.',
            'type_employe.in' => 'Type d\'employé invalide.',
            'statut.required' => 'Le statut est obligatoire.',
            'statut.in' => 'Statut invalide.',
            'site_id.exists' => 'Site invalide.',
            'service_rh_id.exists' => 'Service invalide.',
            'date_naissance.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
            'date_sortie.after_or_equal' => 'La date de sortie ne peut pas précéder la date d\'embauche.',
            'sexe.in' => 'Sexe invalide.',
            'paiement_mode.in' => 'Mode de paiement invalide.',
        ];
    }
}
