<?php

namespace App\Http\Controllers\Achats\Receptions;

use App\Enums\StatutCommandeAchat;
use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Models\CommandeAchatLigne;
use App\Models\Fournisseur;
use App\Models\Site;
use App\Models\User;
use App\Services\Achats\PerimetreReceptionsAchat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logistique → Réceptions fournisseurs (ADR 0021) : bons de commande validés à réceptionner dans
 * les agences de l'utilisateur et celles que son rôle peut réceptionner. Rattaché au module Achats (pas au module Logistique) : l'écran
 * reste disponible quand seuls les Achats sont activés.
 */
class IndexReceptionAchatController extends Controller
{
    private const STATUTS_AFFICHES = [
        StatutCommandeAchat::VALIDEE,
        StatutCommandeAchat::PARTIELLEMENT_RECEPTIONNEE,
        StatutCommandeAchat::RECEPTIONNEE,
        StatutCommandeAchat::CLOTUREE,
    ];

    public function __invoke(Request $request, PerimetreReceptionsAchat $perimetre): Response
    {
        $user = $request->user();
        abort_unless($user->can('receptions.read'), 403);

        $orgId = $user->organization_id;
        $filters = $request->only(['statut', 'fournisseur_id', 'reference']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));
        $statutsAffiches = array_map(fn ($s) => $s->value, self::STATUTS_AFFICHES);

        $statut = in_array($filters['statut'] ?? null, $statutsAffiches, true) ? $filters['statut'] : null;
        $statuts = $statut !== null
            ? [$statut]
            : array_map(fn ($s) => $s->value, StatutCommandeAchat::receptionnables());

        $query = CommandeAchat::query()
            ->where('organization_id', $orgId)
            ->whereNotNull('site_id')
            ->whereIn('statut', $statuts)
            ->with(['fournisseur.personne', 'fournisseur.entrepriseTierce', 'site:id,nom', 'lignes']);

        // Réceptionnaire : les agences auxquelles il est rattaché, plus celles que couvre la règle
        // « Peut réceptionner pour » de son rôle (PerimetreReceptionsAchat) — sans passe-droit de rôle.
        // La permission de consultation de toutes les agences (ADR 0025) élargit seulement la LISTE :
        // réceptionner reste réservé aux agences couvertes (`peut_receptionner`).
        $receptionnables = $perimetre->sitesCouverts($user);
        $toutesAgences = $receptionnables === null || $user->can(User::PERMISSION_LECTURE_TOUTES_AGENCES);
        if (! $toutesAgences) {
            $query->whereIn('site_id', $receptionnables);
        }
        $peutCreer = $user->can('receptions.create');

        $query
            ->when($siteIds !== [], fn (Builder $q) => $q->whereIn('site_id', $siteIds))
            ->when($filters['fournisseur_id'] ?? null, fn (Builder $q, string $id) => $q->where('fournisseur_id', $id))
            ->when(trim((string) ($filters['reference'] ?? '')) !== '', fn (Builder $q) => $q->where('reference', 'like', '%'.trim($filters['reference']).'%'));

        $paginator = $query->orderBy('validee_at')->orderBy('created_at')->paginate(20)->withQueryString();

        $sites = Site::where('organization_id', $orgId)
            ->when(! $toutesAgences, fn (Builder $q) => $q->whereIn('id', $receptionnables))
            ->orderBy('nom')
            ->get(['id', 'nom']);

        $fournisseurs = Fournisseur::where('organization_id', $orgId)
            ->with(['personne', 'entrepriseTierce'])
            ->get()
            ->sortBy('nom_complet')
            ->values()
            ->map(fn (Fournisseur $f) => ['value' => $f->id, 'label' => $f->nom_complet]);

        return Inertia::render('Logistique/ReceptionsFournisseurs', [
            'commandes' => $paginator->through(fn (CommandeAchat $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'statut' => $c->statut?->value,
                'statut_label' => $c->statut_label,
                'fournisseur_nom' => $c->fournisseurNom(),
                'site_nom' => $c->siteNom(),
                'validee_at' => $c->validee_at?->format('d/m/Y'),
                'qte_commandee' => (int) $c->lignes->sum('qte'),
                'qte_recue' => (int) $c->lignes->sum('qte_recue'),
                'lignes' => $c->lignes->map(fn (CommandeAchatLigne $l) => [
                    'id' => $l->id,
                    'produit_nom' => $l->libelle_snapshot ?? '—',
                    'qte' => (int) $l->qte,
                    'qte_recue' => (int) $l->qte_recue,
                    'reliquat' => $l->reliquat(),
                ])->values(),
                // Même règle que le serveur à l'enregistrement, calculée une fois pour la page.
                'peut_receptionner' => $c->isReceptionnable() && $peutCreer
                    && ($receptionnables === null || in_array($c->site_id, $receptionnables, true)),
            ]),
            'filters' => array_merge($filters, ['site_ids' => $siteIds]),
            'statuts' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], self::STATUTS_AFFICHES),
            'fournisseurs' => $fournisseurs,
            'sites' => $sites->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom])->values(),
        ]);
    }
}
