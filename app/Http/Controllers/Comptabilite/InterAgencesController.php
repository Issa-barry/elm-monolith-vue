<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\DetteInterAgencesService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\ReglementInterAgencesService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trésorerie → Inter-agences (ADR 0012, lot 2) : ce que chaque agence doit aux autres (« À verser »)
 * et ce qu'elle doit en recevoir (« À recevoir »), le détail des encaissements qui composent chaque
 * dette, et le règlement.
 *
 * Interface seulement : la dette vient de DetteInterAgencesService, le règlement de
 * ReglementInterAgencesService puis MouvementFondsService::envoyer() — aucun calcul ni aucune règle
 * n'est refait ici. Le montant d'un règlement n'est jamais reçu du navigateur.
 */
class InterAgencesController extends Controller
{
    public function __construct(
        private readonly DetteInterAgencesService $dettes,
        private readonly SiteScopeService $siteScope,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('tresorerie.read'), 403);

        $user = $request->user();
        $orgId = $user->organization_id;

        $filtre = array_values(array_filter((array) $request->input('site_ids', [])));
        $perimetre = $this->perimetre($user, $filtre);

        $soldes = $this->dettes->soldes($orgId, $perimetre->pluck('id')->all())
            ->filter(fn (array $s) => $s['a_recevoir'] > 0 || $s['verse'] > 0);

        // Une seule ligne par sens de reversement, avec les montants du service métier.
        $reversements = $soldes
            ->sortBy([['site_debiteur_nom', 'asc'], ['site_creancier_nom', 'asc']])
            ->map(fn (array $solde) => [
                'debiteur' => ['id' => $solde['site_debiteur_id'], 'nom' => $solde['site_debiteur_nom']],
                'creancier' => ['id' => $solde['site_creancier_id'], 'nom' => $solde['site_creancier_nom']],
                'a_verser' => $solde['a_verser'],
                'en_cours_versement' => $solde['en_cours_versement'],
                'verse' => $solde['verse'],
                'statut' => $solde['statut'],
                'statut_label' => $solde['statut_label'],
                'detail_url' => $this->detailUrl($solde['site_debiteur_id'], $solde['site_creancier_id']),
            ])->values();

        return Inertia::render('Comptabilite/Tresorerie/InterAgences/Index', [
            'reversements' => $reversements,
            'filters' => ['site_ids' => $filtre],
            'sites' => $this->sitesProposes($user),
        ]);
    }

    public function show(Request $request, Site $debiteur, Site $creancier): Response
    {
        $user = $request->user();
        abort_unless($user->can('tresorerie.read'), 403);
        $this->verifierCouple($user, $debiteur, $creancier, consultation: true);

        $statut = (string) $request->input('statut', '');
        $toutes = $this->dettes->lignes($user->organization_id, $debiteur->id, $creancier->id);
        $lignes = $statut !== '' ? $toutes->where('statut', $statut)->values() : $toutes;

        $somme = fn (array $statuts) => round((float) $toutes->whereIn('statut', $statuts)->sum('montant'), 2);
        $peutRegler = $user->can('regler', [MouvementFonds::class, $debiteur]);

        return Inertia::render('Comptabilite/Tresorerie/InterAgences/Show', [
            'debiteur' => ['id' => $debiteur->id, 'nom' => $debiteur->nom],
            'creancier' => ['id' => $creancier->id, 'nom' => $creancier->nom],
            'lignes' => $lignes->map(fn (array $l) => [
                ...$l,
                'selectionnable' => $l['statut'] === DetteInterAgencesService::A_VERSER,
            ])->values(),
            // Proposées au règlement quel que soit le filtre de statut : seules les lignes « À verser ».
            'lignes_a_regler' => $peutRegler
                ? $toutes->where('statut', DetteInterAgencesService::A_VERSER)
                    ->map(fn (array $l) => [...$l, 'selectionnable' => true])->values()
                : [],
            'resume' => [
                'a_verser' => $somme([DetteInterAgencesService::A_VERSER]),
                'reserve' => $somme([DetteInterAgencesService::RESERVE]),
                'en_cours_versement' => $somme([DetteInterAgencesService::EN_COURS]),
                'verse' => $somme([DetteInterAgencesService::VERSE]),
            ],
            'filters' => ['statut' => $statut],
            'statut_options' => collect(DetteInterAgencesService::LIBELLES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'peut_regler' => $peutRegler,
            // Supports proposés au règlement : seulement si l'utilisateur peut régler (information
            // de solde propre à l'agence débitrice).
            'supports' => $peutRegler ? $this->supportsPourReglement($debiteur) : [],
        ]);
    }

    /**
     * « Régler » : crée le règlement (ReglementInterAgencesService) ET l'envoie
     * (MouvementFondsService::envoyer(), contrôle du solde sous verrou) dans UNE transaction — si
     * l'envoi échoue, aucun règlement ni brouillon ne reste (décision du 29/09/2026).
     */
    public function storeReglement(
        Request $request,
        Site $debiteur,
        Site $creancier,
        ReglementInterAgencesService $reglements,
        MouvementFondsService $mouvements,
    ): RedirectResponse {
        $user = $request->user();
        $this->verifierCouple($user, $debiteur, $creancier);
        abort_unless($user->can('regler', [MouvementFonds::class, $debiteur]), 403, 'Action non autorisée.');

        // Aucun montant n'est accepté : il est toujours la somme des encaissements sélectionnés.
        $data = $request->validate([
            'encaissement_ids' => ['required', 'array', 'min:1'],
            'encaissement_ids.*' => ['string'],
            'compte_tresorerie_origine_id' => ['required', 'string'],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ], [
            'encaissement_ids.required' => 'Sélectionnez au moins un encaissement à régler.',
            'compte_tresorerie_origine_id.required' => "Choisissez le support de trésorerie d'où part l'argent.",
        ]);

        // Jamais une caisse dédiée, ni un support d'une autre agence, inactif ou non validé.
        if (! collect($this->supportsPourReglement($debiteur))->contains('id', $data['compte_tresorerie_origine_id'])) {
            throw ValidationException::withMessages([
                'compte_tresorerie_origine_id' => "Ce support ne peut pas être utilisé : choisissez un support actif et validé de l'agence {$debiteur->nom} (jamais une caisse d'agent).",
            ]);
        }

        try {
            $mouvement = DB::transaction(function () use ($reglements, $mouvements, $user, $debiteur, $creancier, $data) {
                $brouillon = $reglements->creerBrouillon(
                    $user->organization_id,
                    $debiteur->id,
                    $creancier->id,
                    $data['encaissement_ids'],
                    $data['compte_tresorerie_origine_id'],
                    $user->id,
                    $data['commentaire'] ?? null,
                );

                return $mouvements->envoyer($brouillon, $user->id);
            });
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['compte_tresorerie_origine_id' => $e->getMessage()]);
        }

        return back()->with('success', "Règlement {$mouvement->reference} envoyé à {$creancier->nom} : {$creancier->nom} doit maintenant confirmer la réception.");
    }

    /**
     * Supports d'où un règlement peut partir : supports d'AGENCE de l'agence débitrice, actifs et
     * validés, avec leur solde (grand livre) — jamais une caisse dédiée à un agent.
     *
     * @return list<array{id:string, libelle:string, type:string, solde:float}>
     */
    private function supportsPourReglement(Site $debiteur): array
    {
        $disponibilite = app(TresorerieDisponibiliteService::class);

        return CompteTresorerie::forOrg($debiteur->organization_id)
            ->where('site_id', $debiteur->id)
            ->agence()
            ->actifs()
            ->whereNotNull('valide_le')
            ->orderBy('libelle')
            ->get()
            ->map(fn (CompteTresorerie $c) => [
                'id' => $c->id,
                'libelle' => $c->libelle,
                'type' => $c->type->value,
                'solde' => $disponibilite->soldePourSupport($c, now()),
            ])
            ->values()
            ->all();
    }

    /**
     * Couple de l'organisation, et l'utilisateur a accès à l'une des deux agences (admin : toutes).
     * En consultation, la vision 360° (ADR 0025) ouvre tous les couples ; un règlement reste réservé
     * aux agences de rattachement.
     */
    private function verifierCouple(User $user, Site $debiteur, Site $creancier, bool $consultation = false): void
    {
        abort_unless(
            $debiteur->organization_id === $user->organization_id && $creancier->organization_id === $user->organization_id,
            404,
        );

        if ($consultation ? $user->voitToutesLesAgences() : $user->isAdmin()) {
            return;
        }

        $accessibles = $consultation ? $this->siteScope->accessibleSiteIds($user) : $this->siteScope->assignedSiteIds($user);
        abort_unless($accessibles->contains($debiteur->id) || $accessibles->contains($creancier->id), 403, "Vous n'avez pas accès à ces agences.");
    }

    /**
     * Agences affichées : un admin, toute l'organisation (filtre Agence facultatif) ; sinon ses
     * agences, le filtre ne pouvant que les restreindre.
     *
     * @param  list<string>  $filtre
     * @return Collection<int, Site>
     */
    private function perimetre(User $user, array $filtre): Collection
    {
        $query = Site::where('organization_id', $user->organization_id)->orderBy('nom');

        if (! $user->voitToutesLesAgences()) {
            $query->whereIn('id', $this->siteScope->accessibleSiteIds($user));
        }
        if ($filtre !== []) {
            $query->whereIn('id', $filtre);
        }

        return $query->get(['id', 'nom', 'organization_id']);
    }

    /** @return list<array{value:string,label:string}> */
    private function sitesProposes(User $user): array
    {
        return $this->perimetre($user, [])
            ->map(fn (Site $s) => ['value' => $s->id, 'label' => $s->nom])
            ->values()
            ->all();
    }

    private function detailUrl(string $debiteurId, string $creancierId): string
    {
        return route('comptabilite.tresorerie.inter-agences.show', [$debiteurId, $creancierId], false);
    }
}
