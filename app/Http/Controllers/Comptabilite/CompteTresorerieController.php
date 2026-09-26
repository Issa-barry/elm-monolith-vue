<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\OperateurMobileMoney;
use App\Enums\TypeSupportTresorerie;
use App\Http\Controllers\Controller;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\CompteTresorerie;
use App\Models\Site;
use App\Models\User;
use App\Services\Comptabilite\SupportTresorerieTypeResolver;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestion des supports de trésorerie (caisses, banques, comptes Mobile Money
 * par site, et caisses dédiées à un agent) avec leur solde actuel — prérequis
 * pour créer un mouvement de fonds ou un solde d'ouverture.
 * Lecture ouverte à `tresorerie.read`, limitée aux agences de l'utilisateur (admins : toutes) —
 * un responsable d'agence y suit les caisses et verse celle d'un agent (permission
 * `tresorerie.verser`, cf. VerserCaisseAgentController). Création, modification et soldes
 * d'ouverture restent réservés à `tresorerie.gerer_soldes_ouverture`.
 *
 * `nature` (agence | dediee) est dérivée d'`agent_id` : un support sans agent
 * appartient à l'agence, une caisse dédiée est toujours de type Caisse et son
 * compte comptable est créé automatiquement (cf. CaisseAgentService).
 *
 * Un support est créé en brouillon (inutilisable) puis validé par `tresorerie.valider_supports`
 * (cf. SupportTresorerieValidationService) : seul un support validé peut être actif.
 */
class CompteTresorerieController extends Controller
{
    public function __construct(
        private readonly CaisseAgentService $caisseAgent,
        private readonly TresorerieDisponibiliteService $disponibilite,
        private readonly SiteScopeService $siteScope,
    ) {}

    public function index(Request $request, SupportTresorerieTypeResolver $typeResolver): Response
    {
        $user = auth()->user();
        abort_unless($user->can('tresorerie.read') || $user->can('tresorerie.gerer_soldes_ouverture'), 403);

        $orgId = $user->organization_id;
        $peutGerer = $user->can('tresorerie.gerer_soldes_ouverture');
        // null = toutes les agences (admin) ; sinon uniquement celles de l'utilisateur.
        $sitesAccessibles = $user->isAdmin() ? null : $this->siteScope->accessibleSiteIds($user)->all();
        $typesParCompte = $peutGerer ? $typeResolver->typesParCompte($orgId) : collect();

        $filters = [
            'site_ids' => array_values(array_filter((array) $request->input('site_ids', []))),
            'statut' => (string) $request->input('statut', ''),
            'type' => (string) $request->input('type', ''),
            'nature' => (string) $request->input('nature', ''),
            'agent_id' => (string) $request->input('agent_id', ''),
        ];

        $query = CompteTresorerie::forOrg($orgId)
            ->with(['site:id,nom', 'compte:id,numero,libelle', 'soldeOuverture', 'agent.personne', 'validePar.personne']);

        if ($sitesAccessibles !== null) {
            $query->whereIn('site_id', $sitesAccessibles);
        }
        if ($filters['site_ids'] !== []) {
            $query->whereIn('site_id', $filters['site_ids']);
        }
        if (TypeSupportTresorerie::tryFrom($filters['type']) !== null) {
            $query->where('type', $filters['type']);
        }
        if ($filters['nature'] === 'agence') {
            $query->agence();
        } elseif ($filters['nature'] === 'dediee') {
            $query->dediees();
        }
        if ($filters['agent_id'] !== '') {
            $query->where('agent_id', $filters['agent_id']);
        }
        if ($filters['statut'] === 'actif') {
            $query->actifs();
        } elseif ($filters['statut'] === 'inactif') {
            $query->where('actif', false)->whereNotNull('valide_le');
        } elseif ($filters['statut'] === 'brouillon') {
            $query->whereNull('valide_le');
        }

        // Solde depuis le grand livre (jamais un solde parallèle) — y compris pour les
        // supports désactivés, dont le solde reste à afficher.
        $soldes = $this->disponibilite->situationParSupport($orgId, now(), true)->keyBy('compte_tresorerie_id');

        // Caisses d'agence (type Caisse, actives) pouvant recevoir un versement, par agence.
        $destinationsVersement = CompteTresorerie::forOrg($orgId)->agence()->actifs()
            ->where('type', TypeSupportTresorerie::CAISSE->value)
            ->when($sitesAccessibles !== null, fn ($q) => $q->whereIn('site_id', $sitesAccessibles))
            ->orderBy('libelle')
            ->get(['id', 'site_id', 'libelle']);
        $agencesAvecDestination = $destinationsVersement->pluck('site_id')->flip();

        $supportsListes = $query->get();

        // Argent déjà sorti d'une caisse dédiée (versement Envoyé) mais pas encore reçu : information
        // de suivi, jamais ajoutée au solde (le grand livre reste la source de vérité des soldes).
        $enCours = $this->disponibilite->versementsEnCours($orgId, now(), $supportsListes->pluck('id')->all())
            ->keyBy('compte_tresorerie_id');

        $comptes = $supportsListes
            ->sortBy(fn (CompteTresorerie $c) => mb_strtolower(($c->site?->nom ?? '').'|'.($c->isDediee() ? '1' : '0').'|'.$c->libelle))
            ->values()
            ->map(function (CompteTresorerie $c) use ($soldes, $enCours, $user, $agencesAvecDestination) {
                $solde = (float) ($soldes->get($c->id)['solde'] ?? 0);

                return [
                    'id' => $c->id,
                    'site' => $c->site?->nom,
                    'site_id' => $c->site_id,
                    'type' => $c->type->value,
                    'type_label' => $c->type->label(),
                    'operateur_mobile_money' => $c->operateur_mobile_money?->value,
                    'operateur_label' => $c->operateur_mobile_money?->label(),
                    'libelle' => $c->libelle,
                    'nature' => $c->isDediee() ? 'dediee' : 'agence',
                    'agent' => $c->agent ? ['id' => $c->agent->id, 'nom' => $c->agent->name] : null,
                    'compte_comptable_id' => $c->compte_comptable_id,
                    'compte_numero' => $c->compte?->numero,
                    'moyen_paiement_defaut' => $c->moyen_paiement_defaut,
                    'actif' => $c->actif,
                    'statut' => $c->statut()->value,
                    'statut_label' => $c->statut()->label(),
                    'valide_le' => $c->valide_le?->toIso8601String(),
                    'valide_par' => $c->validePar?->name,
                    // Politique + état vérifiés EXPLICITEMENT (le Gate::before du super admin passe
                    // toujours) ; SupportTresorerieValidationService::valider() revérifie l'état.
                    'peut_valider' => ! $c->estValide() && $user->can('valider', $c),
                    'solde' => $solde,
                    'en_cours_versement' => (float) ($enCours->get($c->id)['montant'] ?? 0),
                    'versements_en_cours' => (int) ($enCours->get($c->id)['nombre'] ?? 0),
                    // Conditions d'état vérifiées EXPLICITEMENT en plus de la policy : le Gate::before
                    // du super admin la passe toujours. MouvementFondsService::verserCaisseAgent()
                    // revérifie de toute façon chacune d'elles.
                    'peut_verser' => $c->isDediee()
                        && $c->actif
                        && $solde > 0
                        && $agencesAvecDestination->has($c->site_id)
                        && $user->can('verser', $c),
                    'solde_ouverture' => $c->soldeOuverture ? [
                        'id' => $c->soldeOuverture->id,
                        'montant' => (float) $c->soldeOuverture->montant,
                        'statut' => $c->soldeOuverture->statut->value,
                    ] : null,
                ];
            });

        return Inertia::render('Comptabilite/Tresorerie/Supports/Index', [
            'comptes' => $comptes,
            'filters' => $filters,
            'sites' => Site::where('organization_id', $orgId)
                ->when($sitesAccessibles !== null, fn ($q) => $q->whereIn('id', $sitesAccessibles))
                ->orderBy('nom')
                ->get(['id', 'nom']),
            'type_options' => TypeSupportTresorerie::options(),
            'operateur_options' => OperateurMobileMoney::optionsAvecWallet(),
            'destinations_versement' => $destinationsVersement->map(fn (CompteTresorerie $c) => [
                'id' => $c->id,
                'site_id' => $c->site_id,
                'libelle' => $c->libelle,
            ])->values(),
            // Données de création : réservées à celui qui peut réellement créer une caisse — un
            // simple lecteur n'a pas à recevoir la liste des utilisateurs de l'organisation.
            'agents' => $peutGerer ? $this->agentsAssignables($orgId, $sitesAccessibles) : collect(),
            'caisses_dediees_actives' => $peutGerer
                ? CompteTresorerie::forOrg($orgId)->dediees()->actifs()
                    ->when($sitesAccessibles !== null, fn ($q) => $q->whereIn('site_id', $sitesAccessibles))
                    ->get(['agent_id', 'site_id'])
                    ->map(fn (CompteTresorerie $c) => ['agent_id' => $c->agent_id, 'site_id' => $c->site_id])
                    ->values()
                : collect(),
            'agents_filtre' => $this->agentsAvecCaisse($orgId, $sitesAccessibles),
            'comptes_comptables' => $peutGerer
                ? CompteComptable::where('organization_id', $orgId)
                    ->whereIn('id', $this->comptesDeTresorerieDisponibles($orgId))
                    ->orderBy('numero')
                    ->get(['id', 'numero', 'libelle'])
                    ->map(fn (CompteComptable $c) => [
                        'id' => $c->id,
                        'numero' => $c->numero,
                        'libelle' => $c->libelle,
                        'type_support' => $typesParCompte->get($c->id)?->value,
                    ])
                : collect(),
        ]);
    }

    public function store(Request $request, SupportTresorerieTypeResolver $typeResolver)
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);

        $orgId = auth()->user()->organization_id;

        $request->validate(['nature' => ['nullable', Rule::in(['agence', 'dediee'])]]);

        if ($request->input('nature') === 'dediee') {
            $data = $request->validate([
                'site_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
                'agent_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
                'libelle' => ['nullable', 'string', 'max:150'],
            ]);

            $support = $this->caisseAgent->creer($orgId, $data['site_id'], $data['agent_id'], $data['libelle'] ?? null);

            return back()->with('success', "Caisse « {$support->libelle} » créée en brouillon : elle devra être validée avant d'être utilisable.");
        }

        $data = $request->validate([
            'site_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'compte_comptable_id' => ['required', Rule::exists('compta_comptes', 'id')->where('organization_id', $orgId)],
            'type' => ['required', Rule::enum(TypeSupportTresorerie::class)],
            // Facultatif : généré automatiquement ("{Type} de {Site}") par
            // CompteTresorerie::boot() si laissé vide — cf. revue du 2026-08-22.
            'libelle' => ['nullable', 'string', 'max:150'],
            'moyen_paiement_defaut' => ['nullable', 'string', 'max:30'],
            ...$this->reglesOperateur(),
        ]);

        $data['operateur_mobile_money'] = $this->verifierMobileMoney($orgId, $data, null);

        // Cohérence type ↔ compte comptable (ex: refuser Caisse + 561300 Mobile
        // Money) — déduite de compta_mappings, jamais d'un numéro codé en dur.
        // Un compte non reconnu (type déduit null) est toléré : on ne bloque que
        // les incompatibilités connues avec certitude.
        $typeAttendu = $typeResolver->typePourCompte($orgId, $data['compte_comptable_id']);
        $typeSaisi = TypeSupportTresorerie::from($data['type']);

        if ($typeAttendu !== null && $typeAttendu !== $typeSaisi) {
            throw ValidationException::withMessages([
                'compte_comptable_id' => "Ce compte comptable correspond au type « {$typeAttendu->label()} », pas « {$typeSaisi->label()} ».",
            ]);
        }

        // Brouillon : inutilisable jusqu'à sa validation (SupportTresorerieValidationService).
        CompteTresorerie::create([...$data, 'organization_id' => $orgId, 'actif' => false]);

        return back()->with('success', "Support de trésorerie créé en brouillon : il devra être validé avant d'être utilisable.");
    }

    public function update(Request $request, CompteTresorerie $compteTresorerie, SupportTresorerieTypeResolver $typeResolver)
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);
        abort_unless($compteTresorerie->organization_id === auth()->user()->organization_id, 403);

        // Caisse dédiée : seuls le libellé et l'activation sont modifiables. Les autres
        // champs éventuellement envoyés sont ignorés (validate() ne renvoie que ces deux
        // clés) : un type ou un compte forgé ne peut jamais être appliqué.
        if ($compteTresorerie->isDediee()) {
            $data = $request->validate([
                'libelle' => ['required', 'string', 'max:150'],
                'actif' => ['required', 'boolean'],
            ]);

            $this->caisseAgent->mettreAJour($compteTresorerie, $data);

            return back()->with('success', 'Caisse mise à jour.');
        }

        $orgId = $compteTresorerie->organization_id;

        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(TypeSupportTresorerie::class)],
            'compte_comptable_id' => ['required', Rule::exists('compta_comptes', 'id')->where('organization_id', $orgId)],
            'moyen_paiement_defaut' => ['nullable', 'string', 'max:30'],
            'actif' => ['required', 'boolean'],
            ...$this->reglesOperateur(),
        ]);

        $data['operateur_mobile_money'] = $this->verifierMobileMoney($orgId, [...$data, 'site_id' => $compteTresorerie->site_id], $compteTresorerie);

        $typeOuCompteChange = $data['type'] !== $compteTresorerie->type->value
            || $data['compte_comptable_id'] !== $compteTresorerie->compte_comptable_id;

        // Une fois un solde d'ouverture saisi, le type et le compte comptable sont
        // figés : les écritures déjà posées référencent ce compte précis, les
        // modifier après coup les rendrait incohérentes silencieusement.
        if ($typeOuCompteChange && $compteTresorerie->soldeOuverture !== null) {
            throw ValidationException::withMessages([
                'type' => "Le type et le compte comptable ne peuvent plus être modifiés : un solde d'ouverture existe déjà pour ce support.",
            ]);
        }

        $typeAttendu = $typeResolver->typePourCompte($orgId, $data['compte_comptable_id']);
        $typeSaisi = TypeSupportTresorerie::from($data['type']);

        if ($typeAttendu !== null && $typeAttendu !== $typeSaisi) {
            throw ValidationException::withMessages([
                'compte_comptable_id' => "Ce compte comptable correspond au type « {$typeAttendu->label()} », pas « {$typeSaisi->label()} ».",
            ]);
        }

        // Un brouillon ne s'active que par sa validation, jamais par un simple basculement.
        if ($data['actif'] && ! $compteTresorerie->estValide()) {
            throw ValidationException::withMessages([
                'actif' => "Ce support n'est pas encore validé : validez-le avant de l'activer.",
            ]);
        }

        $compteTresorerie->update($data);

        return back()->with('success', 'Support de trésorerie mis à jour.');
    }

    /** Brouillon → actif. Les règles d'état sont dans SupportTresorerieValidationService. */
    public function valider(CompteTresorerie $compteTresorerie, SupportTresorerieValidationService $validation)
    {
        $user = auth()->user();

        abort_unless($compteTresorerie->organization_id === $user->organization_id, 403);
        $this->authorize('valider', $compteTresorerie);

        $support = $validation->valider($compteTresorerie, $user);

        return back()->with('success', "« {$support->libelle} » validé : le support est maintenant actif.");
    }

    /**
     * Utilisateurs actifs de l'organisation pouvant se voir attribuer une caisse : ceux
     * qui sont rattachés à au moins un site. Le contrôle réel (rattachement au site
     * choisi, une seule caisse active par agent et par site) reste côté serveur, dans
     * CaisseAgentService — cette liste ne sert qu'à proposer des choix pertinents.
     *
     * @param  list<string>|null  $sitesAccessibles  null = toutes les agences
     * @return Collection<int, array{id:string, nom:string, site_ids:list<string>}>
     */
    private function agentsAssignables(string $orgId, ?array $sitesAccessibles): Collection
    {
        return User::where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereHas('sites', fn ($q) => $sitesAccessibles === null ? $q : $q->whereIn('sites.id', $sitesAccessibles))
            ->with(['personne', 'sites:id'])
            ->get()
            ->sortBy(fn (User $u) => mb_strtolower($u->name))
            ->values()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nom' => $u->name,
                'site_ids' => $u->sites->pluck('id')->values()->all(),
            ]);
    }

    /**
     * @param  list<string>|null  $sitesAccessibles  null = toutes les agences
     * @return Collection<int, array{value:string, label:string}>
     */
    private function agentsAvecCaisse(string $orgId, ?array $sitesAccessibles): Collection
    {
        return CompteTresorerie::forOrg($orgId)->dediees()
            ->when($sitesAccessibles !== null, fn ($q) => $q->whereIn('site_id', $sitesAccessibles))
            ->with('agent.personne')->get()
            ->pluck('agent')
            ->filter()
            ->unique('id')
            ->sortBy(fn (User $u) => mb_strtolower($u->name))
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])
            ->values();
    }

    /**
     * Comptes éligibles à un support de trésorerie : ceux déjà reconnus comme comptes de
     * trésorerie par le paramétrage propre de l'organisation (rôle "tresorerie" dans
     * compta_mappings), jamais une liste de numéros codée en dur (cf. revue Codex du
     * 2026-08-22 — l'ancienne liste ['571000', '521000', ...] contredisait la promesse
     * "aucun numéro codé en dur" du chantier).
     *
     * @return Collection<int, string>
     */
    /** @return array<string, array<int, mixed>> */
    private function reglesOperateur(): array
    {
        return [
            'operateur_mobile_money' => [
                'nullable',
                'required_if:type,'.TypeSupportTresorerie::MOBILE_MONEY->value,
                Rule::in(array_map(fn (OperateurMobileMoney $o) => $o->value, OperateurMobileMoney::avecWallet())),
            ],
        ];
    }

    /**
     * Chaque Mobile Money est un compte à part (décision du 24/09/2026, cf. docs/encaissements.md) :
     * c'est le support qui rend un opérateur proposable à l'encaissement, et son compte reçoit
     * l'argent de CET opérateur seulement. Refuse donc (1) un compte réservé à un autre opérateur
     * (compta_mappings « mobile_money:<detail> » — jamais un numéro codé en dur ; un compte sans
     * opérateur connu reste accepté) et (2) un compte déjà porté par un autre support Mobile Money
     * de la même agence — le solde se calculant par (agence, compte), deux wallets partageant un
     * compte seraient indiscernables. Retourne l'opérateur à enregistrer (null hors Mobile Money).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function verifierMobileMoney(string $orgId, array $data, ?CompteTresorerie $courant): ?string
    {
        if ($data['type'] !== TypeSupportTresorerie::MOBILE_MONEY->value) {
            return null;
        }

        $operateur = OperateurMobileMoney::from($data['operateur_mobile_money']);

        $operateursDuCompte = CompteMapping::where('organization_id', $orgId)
            ->where('compte_comptable_id', $data['compte_comptable_id'])
            ->where('moyen_paiement', 'like', 'mobile_money:%')
            ->pluck('moyen_paiement')
            ->map(fn (string $moyen) => OperateurMobileMoney::fromDetailComptable(substr($moyen, strlen('mobile_money:'))))
            ->filter()
            ->unique();

        if ($operateursDuCompte->isNotEmpty() && ! $operateursDuCompte->contains($operateur)) {
            throw ValidationException::withMessages([
                'compte_comptable_id' => "Ce compte comptable est celui de {$operateursDuCompte->map->label()->implode(', ')}, pas de {$operateur->label()}.",
            ]);
        }

        $dejaPris = CompteTresorerie::forOrg($orgId)
            ->where('site_id', $data['site_id'])
            ->where('type', TypeSupportTresorerie::MOBILE_MONEY->value)
            ->where('compte_comptable_id', $data['compte_comptable_id'])
            ->when($courant, fn ($q) => $q->whereKeyNot($courant->id))
            ->first();

        if ($dejaPris) {
            throw ValidationException::withMessages([
                'compte_comptable_id' => "Ce compte est déjà celui du support « {$dejaPris->libelle} » dans cette agence : chaque Mobile Money doit avoir son propre compte.",
            ]);
        }

        return $operateur->value;
    }

    private function comptesDeTresorerieDisponibles(string $orgId): Collection
    {
        return CompteMapping::where('organization_id', $orgId)
            ->where('role', 'tresorerie')
            ->pluck('compte_comptable_id')
            ->unique();
    }
}
