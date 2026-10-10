<?php

namespace App\Http\Controllers\Achats;

use App\Enums\StatutCommandeAchat;
use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Models\Fournisseur;
use App\Models\RegleValidationRole;
use App\Services\Achats\CommandeAchatService;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Services\Validation\ValidationParPlafondService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IndexCommandeAchatController extends Controller
{
    public function __construct(
        private readonly PerimetreCommandesAchat $perimetre,
        private readonly ValidationParPlafondService $plafonds,
        private readonly CommandeAchatService $service,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', CommandeAchat::class);

        $user = $request->user();
        $orgId = $user->organization_id;
        $filters = $request->only(['statut', 'fournisseur_id', 'reference', 'a_valider_par_moi']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));

        $query = $this->perimetre->appliquerConsultation(CommandeAchat::query(), $user)
            ->with(['fournisseur.personne', 'fournisseur.entrepriseTierce', 'site:id,nom', 'sitePayeur:id,nom'])
            ->withSum('lignes as qte_commandee', 'qte')
            ->withSum('lignes as qte_recue', 'qte_recue');

        $query
            // Filtre Agence : bons livrés à l'agence OU payés par elle.
            ->when($siteIds !== [], fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereIn('site_id', $siteIds)->orWhereIn('site_payeur_id', $siteIds)))
            ->when($filters['statut'] ?? null, function (Builder $q, string $statut) {
                $statuts = $statut === StatutCommandeAchat::A_VALIDER->value
                    ? array_map(fn ($s) => $s->value, StatutCommandeAchat::aValider())
                    : [$statut];
                $q->whereIn('statut', $statuts);
            })
            ->when($filters['fournisseur_id'] ?? null, fn (Builder $q, string $id) => $q->where('fournisseur_id', $id))
            ->when(trim((string) ($filters['reference'] ?? '')) !== '', fn (Builder $q) => $q->where('reference', 'like', '%'.trim($filters['reference']).'%'));

        if (filter_var($filters['a_valider_par_moi'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->restreindreAValiderPar($query, $user);
        }

        $paginator = $query->orderByDesc('created_at')->paginate(30)->withQueryString();

        $fournisseurs = Fournisseur::where('organization_id', $orgId)
            ->with(['personne', 'entrepriseTierce'])
            ->get()
            ->sortBy('nom_complet')
            ->values()
            ->map(fn (Fournisseur $f) => ['value' => $f->id, 'label' => $f->nom_complet]);

        // Filtre Agence = agences consultables ; création = périmètre « Peut acheter pour » seul.
        $sites = $this->perimetre->sitesConsultables($user);

        return Inertia::render('Achats/Index', [
            'peut_creer' => $user->can('create', CommandeAchat::class) && $this->perimetre->sites($user)->isNotEmpty(),
            'commandes' => $paginator->through(fn (CommandeAchat $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'statut' => $c->statut?->value,
                'statut_label' => $c->statut_label,
                'total_commande' => (float) $c->total_commande,
                'fournisseur_nom' => $c->fournisseurNom(),
                'site_nom' => $c->siteNom(),
                'site_payeur_nom' => $c->estPayeParUneAutreAgence() ? $c->sitePayeurNom() : null,
                'created_at' => $c->created_at?->format('d/m/Y'),
                'date_achat' => $c->dateAchat()?->format('d/m/Y'),
                'qte_commandee' => (int) $c->qte_commandee,
                'qte_recue' => (int) $c->qte_recue,
                'is_annulee' => $c->isAnnulee(),
                'supprimable' => $c->isAnnulee() && $this->perimetre->peutAgir($c, $user),
                // Un bon seulement consultable (vision 360°, ADR 0025) n'est jamais annulable depuis la liste.
                'annulable' => ($c->isAValider() || $c->statut === StatutCommandeAchat::VALIDEE) && (int) $c->qte_recue === 0
                    && $this->perimetre->peutAgir($c, $user),
                // Même règle que la fiche (permission, périmètre, plafond, séparation des tâches) :
                // l'action « Valider » de la liste n'apparaît que si le serveur l'accepterait.
                'peut_valider' => $c->isAValider()
                    && $user->can('valider', $c)
                    && $this->service->motifNonValidable($c, $user) === null,
            ]),
            'filters' => array_merge($filters, ['site_ids' => $siteIds]),
            'statuts' => StatutCommandeAchat::options(),
            'fournisseurs' => $fournisseurs,
            'sites' => $sites->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom])->values(),
        ]);
    }

    /**
     * Commandes à valider que l'utilisateur peut valider : agence payeuse et montant dans au moins
     * une de ses règles de plafond, agence de livraison dans son périmètre, et ni créées ni modifiées en dernier par lui (ADR 0021). Sans la
     * permission `achats.valider`, aucune.
     */
    private function restreindreAValiderPar(Builder $query, $user): void
    {
        $query->whereIn('statut', array_map(fn ($s) => $s->value, StatutCommandeAchat::aValider()))
            ->whereNotNull('site_id');

        $perimetres = $user->checkPermissionTo('achats.valider')
            ? $this->plafonds->perimetresDeValidation($user, RegleValidationRole::DOMAINE_ACHATS)
            : [];

        if ($perimetres === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        // L'agence de livraison doit elle aussi être dans le périmètre de l'utilisateur.
        $couverts = $this->perimetre->sitesCouverts($user);
        if ($couverts !== null) {
            $query->whereIn('site_id', $couverts === [] ? [''] : $couverts);
        }

        $query->where(function (Builder $q) use ($perimetres, $user) {
            foreach ($perimetres as $p) {
                $q->orWhere(function (Builder $q) use ($p, $user) {
                    $q->whereNotNull('site_id');
                    // Ses propres bons (créés ou modifiés en dernier) seulement si la règle l'autorise.
                    if (! $p['ses_propres_bons']) {
                        $q->where(fn (Builder $q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $user->id))
                            ->where(fn (Builder $q) => $q->whereNull('contenu_modifie_par')->orWhere('contenu_modifie_par', '!=', $user->id));
                    }
                    // Plafond et périmètre de la règle : sur l'agence PAYEUSE (trésorerie engagée).
                    if ($p['sites'] !== null) {
                        $q->whereIn(DB::raw('COALESCE(site_payeur_id, site_id)'), $p['sites'] === [] ? [''] : $p['sites']);
                    }
                    if ($p['plafond'] !== null) {
                        $q->where('total_commande', '<=', $p['plafond']);
                    }
                });
            }
        });
    }
}
