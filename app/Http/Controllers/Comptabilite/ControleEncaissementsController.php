<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\EvenementComptable;
use App\Enums\GraviteAnomalieEncaissement;
use App\Enums\StatutAnomalieEncaissement;
use App\Enums\StatutFactureVente;
use App\Enums\TypeAnomalieEncaissement;
use App\Http\Controllers\Controller;
use App\Models\AnomalieEncaissement;
use App\Models\AnomalieEncaissementNotification as Envoi;
use App\Models\AuditLog;
use App\Models\ControleEncaissements;
use App\Models\EncaissementVente;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AnomalieEncaissementNotification;
use App\Services\AuditLogService;
use App\Services\Ventes\ControleEncaissements\AlertesAnomaliesEncaissement;
use App\Services\Ventes\ControleEncaissements\SurveillanceEncaissements;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

/**
 * Comptabilité → Trésorerie → Contrôle des encaissements (ADR 0036, docs/controle-encaissements.md).
 *
 * Lecture : `encaissements.surveiller`, limitée aux agences de l'utilisateur (vision toutes agences
 * sinon). Traitement (prise en charge, commentaire, résolution, fausse alerte, analyse manuelle) :
 * `encaissements.traiter_anomalies`. L'annulation d'un encaissement en doublon passe par la route
 * existante `encaissements.destroy` (`ventes.annuler_exceptionnel`, contrepassation, audit) : ce
 * contrôleur ne modifie jamais une donnée financière.
 */
class ControleEncaissementsController extends Controller
{
    private const PAR_PAGE = 50;

    private const URL = '/backoffice/comptabilite/tresorerie/controle-encaissements';

    public function __construct(
        private readonly SurveillanceEncaissements $surveillance,
        private readonly AlertesAnomaliesEncaissement $alertes,
        private readonly AuditLogService $audit,
    ) {}

    // ── Liste ────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('encaissements.surveiller'), 403);
        $orgId = $user->organization_id;

        $filtres = [
            'site_ids' => array_values(array_filter((array) $request->input('site_ids', []), 'is_string')),
            'statut' => $this->texte($request, 'statut') ?: 'ouvertes',
            'reference' => $this->texte($request, 'reference'),
            'type' => $this->texte($request, 'type'),
            'gravite' => $this->texte($request, 'gravite'),
            'agent' => $this->texte($request, 'agent'),
            'detection_debut' => $this->texte($request, 'detection_debut'),
            'detection_fin' => $this->texte($request, 'detection_fin'),
        ];

        $anomalies = $this->anomaliesVisibles($user)->with('site:id,nom')->get();
        $agents = User::whereIn('id', $anomalies->pluck('agent_ids')->flatten()->filter()->unique())->get()->keyBy('id');
        $lignes = $this->filtrer($anomalies->map(fn (AnomalieEncaissement $a) => $this->ligne($a, $agents)), $filtres);

        $kpis = [
            'nouvelles' => $lignes->where('statut', StatutAnomalieEncaissement::NOUVELLE->value)->count(),
            'critiques' => $lignes->where('ouverte', true)->where('gravite', GraviteAnomalieEncaissement::CRITIQUE->value)->count(),
            'en_cours' => $lignes->where('statut', StatutAnomalieEncaissement::EN_COURS->value)->count(),
            'resolues' => $lignes->where('statut', StatutAnomalieEncaissement::RESOLUE->value)->count(),
            'surplus' => round((float) $lignes->where('ouverte', true)->where('ecart_present', true)->sum('surplus'), 2),
        ];

        $affichees = $lignes->filter(fn (array $l) => match ($filtres['statut']) {
            'toutes' => true,
            'ouvertes' => $l['ouverte'],
            default => $l['statut'] === $filtres['statut'],
        })->sortBy([['ouverte', 'desc'], ['rang_gravite', 'asc'], ['detectee_le_iso', 'desc']])->values();

        $page = max(1, (int) $request->input('page', 1));
        $paginees = new LengthAwarePaginator(
            $affichees->forPage($page, self::PAR_PAGE)->values(),
            $affichees->count(),
            self::PAR_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $siteIds = $this->sitesVisibles($user);
        $dernierPlanifie = SurveillanceEncaissements::dernierControlePlanifie($orgId);
        $dernier = ControleEncaissements::where('organization_id', $orgId)->latest('debut')->first();

        return Inertia::render('Comptabilite/ControleEncaissements/Index', [
            'anomalies' => $paginees,
            'kpis' => $kpis,
            'filtres' => $filtres,
            'sites' => $siteIds === null
                ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
                : Site::whereIn('id', $siteIds)->orderBy('nom')->get(['id', 'nom']),
            'statut_options' => [
                ['value' => 'ouvertes', 'label' => 'Ouvertes (à traiter)'],
                ...collect(StatutAnomalieEncaissement::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all(),
                ['value' => 'toutes', 'label' => 'Toutes'],
            ],
            'type_options' => collect(TypeAnomalieEncaissement::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])->all(),
            'gravite_options' => collect(GraviteAnomalieEncaissement::cases())->map(fn ($g) => ['value' => $g->value, 'label' => $g->label()])->all(),
            'agent_options' => $agents->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->sortBy('label')->values()->all(),
            'controle' => [
                'dernier_planifie' => $dernierPlanifie?->debut?->format('d/m/Y H:i'),
                'planificateur_en_retard' => ! $dernierPlanifie
                    || $dernierPlanifie->debut->lt(now()->subMinutes(SurveillanceEncaissements::RETARD_PLANIFICATEUR_MINUTES)),
                'dernier' => $dernier?->debut?->format('d/m/Y H:i'),
                'dernier_declencheur' => $dernier?->declencheur,
                'dernier_erreur' => $dernier?->erreur,
            ],
            'can_traiter' => $user->can('encaissements.traiter_anomalies'),
        ]);
    }

    /** Contrôle complet immédiat de l'organisation (sans attendre la tâche planifiée). */
    public function analyser(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('encaissements.traiter_anomalies'), 403);

        $controle = $this->surveillance->controlerOrganisation($user->organization_id, ControleEncaissements::MANUEL, $user);
        defer(fn () => $this->alertes->envoyerEnAttente());

        return back()->with('success', "Contrôle terminé : {$controle->nb_constats} anomalie(s) constatée(s), "
            ."{$controle->nb_nouvelles} nouvelle(s), {$controle->nb_disparues} écart(s) disparu(s).");
    }

    // ── Fiche ────────────────────────────────────────────────────────────────

    public function show(Request $request, AnomalieEncaissement $anomalie): Response
    {
        $user = $request->user();
        abort_unless($user->can('encaissements.surveiller'), 403);
        $this->autoriserAcces($user, $anomalie);

        // Ouvrir la fiche vaut lecture des alertes correspondantes dans la cloche.
        $user->unreadNotifications()
            ->where('type', AnomalieEncaissementNotification::class)
            ->where('data->anomalie_id', $anomalie->id)
            ->update(['read_at' => now()]);

        $anomalie->load(['site:id,nom', 'facture.commande:id,reference,est_precommande', 'prisEnChargePar', 'cloturePar']);
        $agents = User::whereIn('id', (array) $anomalie->agent_ids)->get()->keyBy('id');
        $facture = $anomalie->facture;
        $peutAnnuler = $this->annulationPossible($anomalie);

        $snapshots = collect($anomalie->details['encaissements'] ?? []);
        $actuels = EncaissementVente::whereIn('id', $snapshots->pluck('id'))->get()->keyBy('id');
        $pieces = PieceComptable::query()
            ->where('organization_id', $anomalie->organization_id)
            ->where('source_type', (new EncaissementVente)->getMorphClass())
            ->whereIn('source_id', array_values(array_filter([...$snapshots->pluck('id')->all(), $anomalie->details['encaissement_supprime_id'] ?? null])))
            ->with('lignes.compte:id,numero,libelle')
            ->orderBy('created_at')
            ->get()
            ->groupBy('source_id');

        $encaisseNet = $facture ? $facture->encaisseNet() : null;

        $encaissements = $snapshots->map(function (array $snap) use ($actuels, $pieces, $facture, $encaisseNet, $peutAnnuler, $anomalie) {
            $actuel = $actuels->get($snap['id']);
            $sesPieces = $pieces->get($snap['id'], collect());
            $piecesValidees = $sesPieces->filter(fn (PieceComptable $p) => $p->isValidee()
                && in_array($p->type_evenement, [EvenementComptable::ENCAISSEMENT_VENTE_RECU->value, EvenementComptable::ENCAISSEMENT_VENTE_POUR_COMPTE->value], true));
            $tresorerie = $sesPieces->firstWhere('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
                ?->lignes->first(fn ($l) => (float) $l->debit > 0)?->compte;

            [$annulable, $raison] = $this->annulable($actuel, $facture, $peutAnnuler);
            $apres = $encaisseNet !== null ? round($encaisseNet - (float) $snap['montant'], 2) : null;

            return [
                ...$snap,
                'existe' => $actuel !== null,
                'suspect' => in_array($snap['id'], $anomalie->details['suspects'] ?? [], true),
                'annulable' => $annulable,
                'raison_non_annulable' => $raison,
                'impact' => $facture && $apres !== null ? [
                    'encaisse_apres' => $apres,
                    'statut_apres' => $this->statutApres($apres, (float) $facture->montant_net),
                    'compte_tresorerie' => $tresorerie ? $tresorerie->numero.' — '.$tresorerie->libelle : null,
                    'pieces_a_contrepasser' => $piecesValidees->pluck('numero')->values()->all(),
                ] : null,
                'pieces' => $sesPieces->map(fn (PieceComptable $p) => $this->piece($p))->values()->all(),
            ];
        })->values();

        return Inertia::render('Comptabilite/ControleEncaissements/Show', [
            'anomalie' => [
                ...$this->ligne($anomalie, $agents),
                'explication' => $anomalie->details['explication'] ?? null,
                'demarche' => $anomalie->type->demarche(),
                'certaine' => $anomalie->type->estCertaine(),
                'reference_mobile_money' => $anomalie->details['reference'] ?? null,
                'piece_numero' => $anomalie->details['piece_numero'] ?? null,
                'occurrence' => $anomalie->occurrence,
                'derniere_detection_le' => $anomalie->derniere_detection_le?->format('d/m/Y H:i'),
                'ecart_disparu_le' => $anomalie->ecart_disparu_le?->format('d/m/Y H:i'),
                'pris_en_charge_par' => $anomalie->prisEnChargePar?->name,
                'pris_en_charge_le' => $anomalie->pris_en_charge_le?->format('d/m/Y H:i'),
                'cloture_par' => $anomalie->cloturePar?->name,
                'cloture_le' => $anomalie->cloture_le?->format('d/m/Y H:i'),
                'commentaire_cloture' => $anomalie->commentaire_cloture,
                'derniere_relance_le' => $anomalie->derniere_relance_le?->format('d/m/Y H:i'),
            ],
            'facture' => $facture ? [
                'reference' => $facture->reference,
                'statut_label' => $facture->statut_facture?->label(),
                'statut' => $facture->statut_facture?->value,
                'montant_net' => round((float) $facture->montant_net, 2),
                'encaisse_net' => $encaisseNet,
                'restant' => $facture->montant_restant,
                'trop_percu' => $facture->tropPercu(),
                'commande_reference' => $facture->commande?->reference,
                'commande_url' => $facture->commande_vente_id ? '/backoffice/ventes/'.$facture->commande_vente_id.'?tab=facturation' : null,
                'est_precommande' => (bool) $facture->commande?->est_precommande,
            ] : null,
            'encaissements' => $encaissements,
            'annulation_possible' => $peutAnnuler,
            'notifications' => $anomalie->notificationsEnvoyees()->with('user')->orderByDesc('created_at')->get()
                ->map(fn (Envoi $n) => [
                    'id' => $n->id,
                    'destinataire' => $n->user?->name ?? '—',
                    'canal' => $n->canal,
                    'canal_label' => $n->canal === Envoi::CANAL_EMAIL ? 'E-mail' : 'Application',
                    'envoi_label' => Envoi::libelleEnvoi($n->envoi),
                    'statut' => $n->statut,
                    'statut_label' => match ($n->statut) {
                        Envoi::ENVOYEE => 'Envoyée',
                        Envoi::ECHEC => $n->tentatives >= Envoi::MAX_TENTATIVES ? 'Échec définitif' : 'Échec (nouvelle tentative prévue)',
                        default => 'En attente',
                    },
                    'tentatives' => $n->tentatives,
                    'erreur' => $n->derniere_erreur,
                    'envoyee_le' => $n->envoyee_le?->format('d/m/Y H:i'),
                    'creee_le' => $n->created_at?->format('d/m/Y H:i'),
                ])->values(),
            'historique' => $anomalie->auditLogs()->orderByDesc('created_at')->get()
                ->map(fn (AuditLog $l) => [
                    'id' => $l->id,
                    'libelle' => $l->event_label,
                    'code' => $l->event_code,
                    'auteur' => $l->actor_name_snapshot ?? 'Contrôle automatique',
                    'date' => $l->created_at?->format('d/m/Y H:i'),
                    'commentaire' => $l->meta['commentaire'] ?? null,
                    'cause' => $l->meta['cause'] ?? null,
                    'statut_avant' => $l->old_values['statut_label'] ?? null,
                    'statut_apres' => $l->new_values['statut_label'] ?? null,
                ])->values(),
            'can_traiter' => $user->can('encaissements.traiter_anomalies'),
        ]);
    }

    // ── Traitement ───────────────────────────────────────────────────────────

    public function prendreEnCharge(Request $request, AnomalieEncaissement $anomalie): RedirectResponse
    {
        $this->autoriserTraitement($request, $anomalie);
        $data = $request->validate(['commentaire' => 'nullable|string|max:2000']);

        $this->changerStatut($request, $anomalie, [StatutAnomalieEncaissement::NOUVELLE], StatutAnomalieEncaissement::EN_COURS, $data['commentaire'] ?? null, fn (AnomalieEncaissement $a, User $u) => [
            'pris_en_charge_par' => $u->id,
            'pris_en_charge_le' => now(),
        ]);

        return back()->with('success', 'Anomalie prise en charge.');
    }

    public function commenter(Request $request, AnomalieEncaissement $anomalie): RedirectResponse
    {
        $user = $this->autoriserTraitement($request, $anomalie);
        $data = $request->validate(['commentaire' => 'required|string|min:3|max:2000'], [
            'commentaire.required' => 'Le commentaire est obligatoire.',
        ]);

        $this->audit->record($anomalie, AuditEvent::COMMENTED, $user, null, null, ['commentaire' => $data['commentaire']]);

        return back()->with('success', 'Commentaire ajouté.');
    }

    /**
     * « Résolue » seulement si un contrôle refait à l'instant ne constate plus l'écart : jamais une
     * résolution de principe pendant que le surplus ou le doublon existe encore.
     */
    public function resoudre(Request $request, AnomalieEncaissement $anomalie): RedirectResponse
    {
        $user = $this->autoriserTraitement($request, $anomalie);
        $data = $request->validate(['commentaire' => 'nullable|string|max:2000']);

        if ($anomalie->facture_vente_id && ! str_starts_with($anomalie->cle, 'compta:piece:')) {
            $this->surveillance->controlerFacture($anomalie->facture_vente_id, 'Vérification avant résolution par '.$user->name);
        } else {
            $this->surveillance->controlerOrganisation($anomalie->organization_id, ControleEncaissements::MANUEL, $user);
        }

        if (! $anomalie->fresh()->ecartDisparu()) {
            throw ValidationException::withMessages([
                'statut' => 'L\'écart est toujours constaté au contrôle effectué à l\'instant : l\'anomalie ne peut pas être classée résolue. '
                    .'Régularisez d\'abord, ou classez-la en fausse alerte avec un motif.',
            ]);
        }

        $this->changerStatut($request, $anomalie, [StatutAnomalieEncaissement::NOUVELLE, StatutAnomalieEncaissement::EN_COURS], StatutAnomalieEncaissement::RESOLUE, $data['commentaire'] ?? null, fn (AnomalieEncaissement $a, User $u) => [
            'cloture_par' => $u->id,
            'cloture_le' => now(),
            'commentaire_cloture' => $data['commentaire'] ?? null,
        ]);

        return back()->with('success', 'Anomalie classée résolue.');
    }

    public function fausseAlerte(Request $request, AnomalieEncaissement $anomalie): RedirectResponse
    {
        $this->autoriserTraitement($request, $anomalie);
        $data = $request->validate(['commentaire' => 'required|string|min:5|max:2000'], [
            'commentaire.required' => 'Le motif est obligatoire pour classer une fausse alerte.',
            'commentaire.min' => 'Précisez le motif (5 caractères au moins).',
        ]);

        $this->changerStatut($request, $anomalie, [StatutAnomalieEncaissement::NOUVELLE, StatutAnomalieEncaissement::EN_COURS], StatutAnomalieEncaissement::FAUSSE_ALERTE, $data['commentaire'], fn (AnomalieEncaissement $a, User $u) => [
            'cloture_par' => $u->id,
            'cloture_le' => now(),
            'commentaire_cloture' => $data['commentaire'],
        ]);

        return back()->with('success', 'Anomalie classée en fausse alerte.');
    }

    /**
     * Transition sous verrou : l'état est relu dans la transaction, deux clics ou deux personnes
     * ne peuvent pas appliquer deux fois la même action.
     *
     * @param  list<StatutAnomalieEncaissement>  $depuis
     * @param  callable(AnomalieEncaissement, User): array<string, mixed>  $champs
     */
    private function changerStatut(Request $request, AnomalieEncaissement $anomalie, array $depuis, StatutAnomalieEncaissement $vers, ?string $commentaire, callable $champs): void
    {
        $user = $this->autoriserTraitement($request, $anomalie);

        DB::transaction(function () use ($anomalie, $depuis, $vers, $commentaire, $champs, $user) {
            $verrouillee = AnomalieEncaissement::whereKey($anomalie->id)->lockForUpdate()->firstOrFail();
            if (! in_array($verrouillee->statut, $depuis, true)) {
                throw ValidationException::withMessages([
                    'statut' => 'Action impossible : l\'anomalie est « '.$verrouillee->statut->label().' ».',
                ]);
            }

            $avant = $verrouillee->statut;
            $verrouillee->update(['statut' => $vers, ...$champs($verrouillee, $user)]);
            $this->audit->record($verrouillee, AuditEvent::STATUS_CHANGED, $user,
                ['statut' => $avant->value, 'statut_label' => $avant->label()],
                ['statut' => $vers->value, 'statut_label' => $vers->label()],
                array_filter(['commentaire' => $commentaire]),
            );
        });
    }

    // ── Autorisations ────────────────────────────────────────────────────────

    private function autoriserTraitement(Request $request, AnomalieEncaissement $anomalie): User
    {
        $user = $request->user();
        abort_unless($user->can('encaissements.traiter_anomalies'), 403);
        $this->autoriserAcces($user, $anomalie);

        return $user;
    }

    private function autoriserAcces(User $user, AnomalieEncaissement $anomalie): void
    {
        abort_unless($anomalie->organization_id === $user->organization_id, 404);
        abort_unless($this->alertes->voitAgence($user, $anomalie->site_id), 403);
    }

    /** @return list<string>|null null = toutes les agences */
    private function sitesVisibles(User $user): ?array
    {
        return $user->voitToutesLesAgences() ? null : $user->sites()->pluck('sites.id')->all();
    }

    private function anomaliesVisibles(User $user)
    {
        $siteIds = $this->sitesVisibles($user);

        return AnomalieEncaissement::query()
            ->where('organization_id', $user->organization_id)
            ->when($siteIds !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('site_id', $siteIds)->orWhereNull('site_id')));
    }

    // ── Régularisation : annulation d'un encaissement en doublon ─────────────

    /**
     * Annulation proposée seulement pour un doublon ou un surplus PRIS EN CHARGE (analyse confirmée)
     * et encore constaté. L'annulation elle-même reste contrôlée par la route encaissements.destroy.
     */
    private function annulationPossible(AnomalieEncaissement $anomalie): bool
    {
        return $anomalie->statut === StatutAnomalieEncaissement::EN_COURS
            && ! $anomalie->ecartDisparu()
            && in_array($anomalie->type, [TypeAnomalieEncaissement::DOUBLE_ENCAISSEMENT, TypeAnomalieEncaissement::SURPLUS_ENCAISSE, TypeAnomalieEncaissement::DOUBLON_SUSPECT], true);
    }

    /** @return array{0: bool, 1: ?string} */
    private function annulable(?EncaissementVente $encaissement, $facture, bool $peutAnnuler): array
    {
        return match (true) {
            $encaissement === null => [false, 'Encaissement déjà supprimé.'],
            ! $peutAnnuler => [false, null],
            $facture?->isAnnulee() => [false, 'La facture est annulée.'],
            $encaissement->ligneReglementActive() !== null => [false, 'Engagé dans un règlement inter-agences : annulez d\'abord ce règlement.'],
            default => [true, null],
        };
    }

    private function statutApres(float $encaisse, float $net): string
    {
        return match (true) {
            $encaisse <= 0 => StatutFactureVente::IMPAYEE->label(),
            $encaisse >= $net => StatutFactureVente::PAYEE->label(),
            default => StatutFactureVente::PARTIEL->label(),
        };
    }

    // ── Présentation ─────────────────────────────────────────────────────────

    /** @param  Collection<string, User>  $agents */
    private function ligne(AnomalieEncaissement $a, Collection $agents): array
    {
        return [
            'id' => $a->id,
            'type' => $a->type->value,
            'type_label' => $a->type->label(),
            'gravite' => $a->gravite->value,
            'gravite_label' => $a->gravite->label(),
            'rang_gravite' => $a->gravite->rang(),
            'statut' => $a->statut->value,
            'statut_label' => $a->statut->label(),
            'ouverte' => $a->estOuverte(),
            'ecart_present' => ! $a->ecartDisparu(),
            'commande_reference' => $a->details['commande_reference'] ?? null,
            'facture_reference' => $a->details['facture_reference'] ?? null,
            'reference' => $a->details['commande_reference'] ?? ($a->details['reference'] ?? ($a->details['piece_numero'] ?? '—')),
            'site_id' => $a->site_id,
            'site_nom' => $a->site?->nom,
            'agent_ids' => array_values((array) $a->agent_ids),
            'agents' => collect((array) $a->agent_ids)->map(fn ($id) => $agents->get($id)?->name)->filter()->values()->all(),
            'montant_facture' => $a->montant_facture !== null ? (float) $a->montant_facture : null,
            'montant_encaisse_net' => $a->montant_encaisse_net !== null ? (float) $a->montant_encaisse_net : null,
            'surplus' => (float) $a->surplus,
            'detectee_le' => $a->detectee_le?->format('d/m/Y H:i'),
            'detectee_le_iso' => $a->detectee_le?->toIso8601String(),
            'nb_relances' => $a->nb_relances,
        ];
    }

    /** @return array<string, mixed> */
    private function piece(PieceComptable $p): array
    {
        return [
            'id' => $p->id,
            'numero' => $p->numero,
            'libelle' => $p->libelle,
            'date' => $p->date_piece?->format('d/m/Y'),
            'statut' => $p->statut->value,
            'contrepassation' => str_starts_with((string) $p->type_evenement, 'contrepassation_de_'),
            'lignes' => $p->lignes->map(fn ($l) => [
                'compte' => trim(($l->compte?->numero ?? '').' — '.($l->compte?->libelle ?? ''), ' —'),
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $f
     * @return Collection<int, array<string, mixed>>
     */
    private function filtrer(Collection $lignes, array $f): Collection
    {
        $debut = $this->date($f['detection_debut'])?->startOfDay();
        $fin = $this->date($f['detection_fin'])?->endOfDay();
        $recherche = mb_strtolower($f['reference']);

        return $lignes->filter(function (array $l) use ($f, $debut, $fin, $recherche) {
            if ($f['site_ids'] !== [] && ! in_array($l['site_id'], $f['site_ids'], true)) {
                return false;
            }
            if ($recherche !== '' && ! collect([$l['reference'], $l['facture_reference'], ...$l['agents']])
                ->contains(fn (?string $v) => $v !== null && str_contains(mb_strtolower($v), $recherche))) {
                return false;
            }
            if ($f['type'] !== '' && $l['type'] !== $f['type']) {
                return false;
            }
            if ($f['gravite'] !== '' && $l['gravite'] !== $f['gravite']) {
                return false;
            }
            if ($f['agent'] !== '' && ! in_array($f['agent'], $l['agent_ids'], true)) {
                return false;
            }
            if (($debut || $fin) && $l['detectee_le_iso']) {
                $date = Carbon::parse($l['detectee_le_iso']);
                if (($debut && $date->lt($debut)) || ($fin && $date->gt($fin))) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    private function texte(Request $request, string $cle): string
    {
        $valeur = $request->input($cle, '');

        return trim(is_array($valeur) ? (string) reset($valeur) : (string) $valeur);
    }

    private function date(string $valeur): ?Carbon
    {
        if ($valeur === '') {
            return null;
        }

        try {
            return Carbon::parse($valeur);
        } catch (\Throwable) {
            return null;
        }
    }
}
