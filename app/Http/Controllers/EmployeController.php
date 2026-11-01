<?php

namespace App\Http\Controllers;

use App\Enums\Civilite;
use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeContrat;
use App\Enums\TypeEmploye;
use App\Models\AffectationRemuneration;
use App\Models\Employe;
use App\Models\EmployeCoordonneesPaiement;
use App\Models\FonctionRh;
use App\Models\Personne;
use App\Models\ServiceRh;
use App\Models\Site;
use App\Models\User;
use App\Services\MatriculeService;
use App\Services\Rh\AffectationRemunerationService;
use App\Services\Rh\DepartEmployeService;
use App\Services\Rh\EmployeAffectationService;
use App\Services\Rh\PdgService;
use App\Services\Rh\RechercheEmployeParTelephone;
use App\Services\SiteScopeService;
use App\Support\Rh\AffectationRemunerationPresenter;
use App\Support\Rh\LiensContactUrgence;
use App\Support\Rh\Nationalites;
use App\Support\Rh\PerimetreEmploye;
use Illuminate\Database\UniqueConstraintViolationException;
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
        // Même normalisation que « Rechercher » (espaces, « 00 » initial…) : même clé, même résultat.
        $info = RechercheEmployeParTelephone::normaliser($telephone);

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

    /** Pays de référence quand l'employé n'a pas de téléphone : le premier pays du sélecteur (Guinée). */
    private const INDICATIF_PAR_DEFAUT = '+224';

    /**
     * Téléphone du contact d'urgence : même pays que le téléphone de l'employé (non modifiable dans
     * le formulaire, revérifié ici), enregistré au format international. Une valeur déjà
     * enregistrée et renvoyée telle quelle est conservée sans être revalidée : un dossier ancien
     * reste modifiable.
     */
    private function telephoneUrgence(array $data, ?string $indicatifEmploye, ?string $actuel = null): ?string
    {
        $saisi = trim((string) ($data['contact_urgence_telephone'] ?? ''));
        if ($saisi === '') {
            return null;
        }
        if ($actuel !== null && $saisi === $actuel) {
            return $actuel;
        }

        $indicatif = $indicatifEmploye ?: self::INDICATIF_PAR_DEFAUT;
        $info = RechercheEmployeParTelephone::normaliser($saisi, $indicatif);
        if ($info === null) {
            throw ValidationException::withMessages([
                'contact_urgence_telephone' => 'Numéro du contact d’urgence invalide : vérifiez le nombre de chiffres.',
            ]);
        }
        if ($info['indicatif'] !== $indicatif) {
            throw ValidationException::withMessages([
                'contact_urgence_telephone' => "Le numéro du contact d’urgence doit être du même pays que celui de l’employé ({$indicatif}).",
            ]);
        }

        return $info['telephone'];
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

        // Modifier / supprimer : agences de rattachement seulement (PerimetreEmploye), même avec la
        // vision de toutes les agences en lecture. Calculé une fois pour toute la liste.
        $sitesEcriture = $user->agitSurToutesLesAgences() ? null : app(SiteScopeService::class)->assignedSiteIds($user);
        $employes = $query->get()
            ->sortBy(['nom', 'prenom'])
            ->map(fn (Employe $e) => [
                ...$this->toRow($e),
                'dans_perimetre_ecriture' => $sitesEcriture === null || ($e->site_id !== null && $sitesEcriture->contains($e->site_id)),
            ])
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
            'statut_options' => $this->statutsSaisissables(),
            'sites' => $this->siteOptions($orgId),
            'fonctions' => $this->fonctionOptions($orgId),
            'services' => $this->serviceOptions($orgId),
            'civilite_options' => Civilite::options(),
            'nationalite_options' => Nationalites::options(),
            'lien_contact_options' => LiensContactUrgence::options(),
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

        // La date d'embauche n'est pas saisie à la création : elle vient du premier contrat
        // (ContratService::creer()) ou se renseigne ensuite dans la fiche.
        unset($data['date_embauche']);

        $telephoneInfo = filled($data['telephone'] ?? null) ? $this->resolveTelephone($data['telephone']) : null;
        $data['contact_urgence_telephone'] = $this->telephoneUrgence($data, $telephoneInfo['code_phone_pays'] ?? null);

        $identite = [
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'email' => $data['email'] ?? null,
            ...$this->identiteCivile($data),
        ];

        try {
            $employe = $this->creerEmploye($orgId, $data, $identite, $telephoneInfo);
        } catch (UniqueConstraintViolationException) {
            // Même numéro enregistré au même instant par quelqu'un d'autre : la contrainte
            // (organization_id, telephone_normalise) a refusé la seconde personne.
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro vient d’être enregistré par quelqu’un d’autre. Cliquez de nouveau sur « Rechercher » pour voir le dossier existant.',
            ]);
        }

        return redirect()->route('employes.edit', $employe)
            ->with('success', "{$employe->nom_complet} a été créé avec succès.");
    }

    /**
     * Contrôle anti-doublon qui fait foi (la recherche préalable n'est qu'une aide) : personne
     * retrouvée par téléphone normalisé, verrouillée, et refus d'un second dossier employé.
     */
    private function creerEmploye(string $orgId, array $data, array $identite, ?array $telephoneInfo): Employe
    {
        return DB::transaction(function () use ($orgId, $data, $identite, $telephoneInfo) {
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
        abort_unless(PerimetreEmploye::peutConsulter(auth()->user(), $employe), 403);

        $employe->load(['personne.user.roles']);
        $pdgActuel = app(PdgService::class)->actuel($employe->organization_id);

        return Inertia::render('Employes/Show', [
            'employe' => array_merge($this->toDetail($employe), [
                'compte' => $this->compteInfo($employe),
                // Bouton « Enregistrer / Annuler le départ » : affiché seulement si le serveur l'accepterait.
                'peut_gerer_depart' => DepartEmployeService::peutGerer(auth()->user(), $employe),
                // Modifier : permission + agence de rattachement (PerimetreEmploye).
                'dans_perimetre_ecriture' => PerimetreEmploye::peutModifier(auth()->user(), $employe),
                // PDG de l'organisation (ADR 0048) : signataire des contrats de travail.
                'est_pdg' => $pdgActuel?->id === $employe->id,
                'pdg_actuel' => $pdgActuel ? PdgService::nom($pdgActuel) : null,
                'peut_designer_pdg' => $employe->statut === StatutEmploye::ACTIF && PdgService::peutDesigner(auth()->user(), $employe),
            ]),
            'primes' => $this->primes($employe),
        ]);
    }

    /**
     * Primes et avantages du salarié (hors annulées) : seulement avec
     * `rh-affectations-remuneration.read` et pour un salarié d'une agence consultable — sinon
     * null, jamais envoyé au navigateur.
     *
     * @return list<array<string, mixed>>|null
     */
    private function primes(Employe $employe): ?array
    {
        $user = auth()->user();
        if (! $user->can('rh-affectations-remuneration.read') || ! AffectationRemunerationService::peutConsulter($employe, $user)) {
            return null;
        }

        $affectations = AffectationRemuneration::where('employe_id', $employe->id)
            ->where('statut', '!=', StatutAffectationRemuneration::ANNULEE->value)
            ->with(AffectationRemunerationPresenter::RELATIONS)
            ->orderByDesc('date_debut')
            ->get();

        return (new AffectationRemunerationPresenter($user))->liste($affectations);
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
        abort_unless(PerimetreEmploye::peutModifier(auth()->user(), $employe), 403);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Employes/Edit', [
            'employe' => $this->toDetail($employe),
            'type_employe_options' => TypeEmploye::options(),
            // Un salarié sorti garde son statut (lecture seule) : seul « Annuler le départ » le rétablit.
            'statut_options' => $employe->statut === StatutEmploye::SORTI ? StatutEmploye::options() : $this->statutsSaisissables(),
            'sites' => $this->siteOptions($orgId),
            'fonctions' => $this->fonctionOptions($orgId),
            'services' => $this->serviceOptions($orgId, $employe->service_rh_id),
            'civilite_options' => Civilite::options(),
            'nationalite_options' => Nationalites::options($employe->personne?->nationalite),
            'lien_contact_options' => LiensContactUrgence::options(),
        ]);
    }

    public function update(Request $request, Employe $employe): RedirectResponse
    {
        $this->authorize('update', $employe);
        abort_unless(PerimetreEmploye::peutModifier(auth()->user(), $employe), 403);

        // Organisation de l'employé, pas celle de l'acteur : le Gate::before du super
        // administrateur laisse passer la Policy.
        $orgId = $employe->organization_id;

        $data = $request->validate($this->regles($orgId, $employe), $this->messages());

        // Départ : seulement par « Enregistrer / Annuler le départ » (DepartEmployeService).
        if ($employe->statut === StatutEmploye::SORTI) {
            $data['statut'] = StatutEmploye::SORTI->value;
        }

        $this->assertEmailUniqueInOrg($data['email'] ?? null, $orgId, $employe->id);

        $telephoneInfo = filled($data['telephone'] ?? null) ? $this->resolveTelephone($data['telephone']) : null;
        if ($telephoneInfo !== null) {
            $this->assertTelephoneUniqueInOrg($telephoneInfo['telephone'], $orgId, $employe->personne_id);
        } else {
            $this->assertTelephonePeutEtreEfface($employe->personne);
        }
        $data['contact_urgence_telephone'] = $this->telephoneUrgence($data, $telephoneInfo['code_phone_pays'] ?? null, $employe->contact_urgence_telephone);

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

    /** Statuts choisis dans le formulaire : « Sorti » s'obtient par l'action de départ. */
    private function statutsSaisissables(): array
    {
        return array_values(array_filter(
            StatutEmploye::options(),
            fn (array $o) => $o['value'] !== StatutEmploye::SORTI->value,
        ));
    }

    /**
     * Règles communes à la création et à la modification. Les champs du dossier sont tous
     * facultatifs : les dossiers existants restent valides sans saisie complémentaire. La date de
     * sortie et son motif ne passent jamais par ce formulaire (DepartEmployeService).
     */
    private function regles(string $orgId, ?Employe $employe = null): array
    {
        $estSorti = $employe?->statut === StatutEmploye::SORTI;
        // Nationalité : liste fermée ; une valeur enregistrée avant la liste reste acceptée telle quelle.
        $nationalites = array_filter([...Nationalites::libelles(), $employe?->personne?->nationalite]);

        return [
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:255'],
            // Obligatoire à la création : « Nouvel employé » passe toujours par la recherche du
            // numéro (anti-doublon). Facultatif en modification : une fiche ancienne sans numéro
            // reste modifiable.
            'telephone' => [$employe === null ? 'required' : 'nullable', 'string', 'max:50'],
            'type_employe' => ['required', Rule::in(TypeEmploye::values())],
            'site_id' => ['nullable', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'fonction_rh_id' => ['nullable', Rule::exists('fonctions_rh', 'id')->where('organization_id', $orgId)],
            'service_rh_id' => ['nullable', Rule::exists('services_rh', 'id')->where('organization_id', $orgId)],
            'statut' => $estSorti
                ? ['nullable']
                : ['required', Rule::in(array_column($this->statutsSaisissables(), 'value'))],
            // Identité civile (portée par Personne)
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'lieu_naissance' => ['nullable', 'string', 'max:100'],
            'civilite' => ['nullable', Rule::in(Civilite::values())],
            'nationalite' => ['nullable', 'string', 'max:60', Rule::in($nationalites)],
            // Dossier
            'date_embauche' => array_filter([
                'nullable', 'date',
                $employe?->date_sortie ? 'before_or_equal:'.$employe->date_sortie->toDateString() : null,
            ]),
            'contact_urgence_nom' => ['nullable', 'string', 'max:150'],
            'contact_urgence_lien' => ['nullable', Rule::in([...LiensContactUrgence::LISTE, LiensContactUrgence::AUTRE])],
            'contact_urgence_lien_precision' => ['nullable', 'required_if:contact_urgence_lien,'.LiensContactUrgence::AUTRE, 'string', 'max:50'],
            'contact_urgence_telephone' => ['nullable', 'string', 'max:30'],
            // Donnée sensible : ignorée sans `rh-employes.donnees_sensibles` (cf. enregistrerDonneesSensibles())
            'numero_cnss' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * Le sexe n'est plus saisi : il est déduit de la civilité pour garder `personnes.sexe` cohérent.
     *
     * @return array<string, mixed>
     */
    private function identiteCivile(array $data): array
    {
        $civilite = Civilite::tryFrom((string) ($data['civilite'] ?? ''));

        return [
            'date_naissance' => $data['date_naissance'] ?? null,
            'lieu_naissance' => $data['lieu_naissance'] ?? null,
            'civilite' => $civilite?->value,
            'sexe' => $civilite?->sexe(),
            'nationalite' => $data['nationalite'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function dossierEmploye(array $data): array
    {
        return [
            'service_rh_id' => $data['service_rh_id'] ?? null,
            // Absente à la création (premier contrat) : la date existante n'est alors pas touchée.
            ...(array_key_exists('date_embauche', $data) ? ['date_embauche' => $data['date_embauche']] : []),
            'contact_urgence_nom' => $data['contact_urgence_nom'] ?? null,
            'contact_urgence_lien' => LiensContactUrgence::valeur($data['contact_urgence_lien'] ?? null, $data['contact_urgence_lien_precision'] ?? null),
            'contact_urgence_telephone' => $data['contact_urgence_telephone'] ?? null,
        ];
    }

    /**
     * N° CNSS : seulement avec `rh-employes.donnees_sensibles`. Sans cette permission, la valeur
     * envoyée est ignorée et la donnée existante reste intacte.
     *
     * Le mode de paiement du salaire n'est plus saisi dans le dossier RH (choisi par la Comptabilité
     * au paiement) : les coordonnées déjà enregistrées ne sont ni modifiées ni supprimées ici.
     */
    private function enregistrerDonneesSensibles(Employe $employe, array $data): void
    {
        if (! auth()->user()->can('rh-employes.donnees_sensibles')) {
            return;
        }

        $employe->update(['numero_cnss' => $data['numero_cnss'] ?? null]);
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

    /**
     * Transfert explicite de site depuis l'écran RH (distinct de update() : action ciblée, avec
     * son propre motif "transfert", conserve la fonction actuelle de l'employé inchangée).
     */
    public function transfererSite(Request $request, Employe $employe): RedirectResponse
    {
        $this->authorize('update', $employe);
        abort_unless(PerimetreEmploye::peutModifier(auth()->user(), $employe), 403);

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
        abort_unless(PerimetreEmploye::peutModifier(auth()->user(), $employe), 403);

        app(PdgService::class)->retirerSiPdg($employe);
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
            'civilite' => $personne?->civilite?->value,
            'civilite_label' => $personne?->civilite?->label(),
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

    /**
     * Les coordonnées de paiement saisies avant le 30/10/2026 restent consultables sur la fiche
     * (lecture seule) : elles ne sont plus modifiables depuis le dossier RH.
     *
     * @return array{numero_cnss: ?string, paiement_mode: ?string, paiement_mode_label: ?string, paiement_etablissement: ?string, paiement_numero_compte: ?string, paiement_titulaire: ?string} */
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
            'telephone.required' => 'Le numéro de téléphone est obligatoire : il permet de vérifier que l’employé n’est pas déjà enregistré.',
            'email.email' => "L'adresse e-mail est invalide.",
            'type_employe.required' => 'Le type d\'employé est obligatoire.',
            'type_employe.in' => 'Type d\'employé invalide.',
            'statut.required' => 'Le statut est obligatoire.',
            'statut.in' => 'Statut invalide. Le départ d’un salarié s’enregistre avec « Enregistrer le départ » sur sa fiche.',
            'site_id.exists' => 'Site invalide.',
            'service_rh_id.exists' => 'Service invalide.',
            'date_naissance.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
            'date_embauche.before_or_equal' => 'La date d\'embauche ne peut pas suivre la date de sortie du salarié.',
            'civilite.in' => 'Civilité invalide.',
            'nationalite.in' => 'Choisissez une nationalité dans la liste.',
            'contact_urgence_lien.in' => 'Choisissez un lien dans la liste.',
            'contact_urgence_lien_precision.required_if' => 'Précisez le lien avec le contact.',
            'contact_urgence_lien_precision.max' => 'La précision du lien est limitée à 50 caractères.',
        ];
    }
}
