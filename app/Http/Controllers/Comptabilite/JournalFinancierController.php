<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\EvenementComptable;
use App\Http\Controllers\Controller;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\JournalComptable;
use App\Models\Site;
use App\Services\SiteScopeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Journal financier — vue de lecture pure sur le grand livre SYSCOHADA
 * (compta_ecritures/compta_pieces), restreinte aux lignes portant sur un
 * compte de trésorerie (caisse/banque/mobile money d'un CompteTresorerie).
 *
 * Remplace l'ancien JournalTresorerie (table/modèle/service supprimés le
 * 2026-08-22) qui était un registre parallèle indépendant du grand livre —
 * cette page ne source plus jamais qu'une seule table de faits : aucune
 * double comptabilité possible. "Entrée"/"sortie" est dérivé du débit/crédit
 * de la ligne (même logique que TresorerieDisponibiliteService), jamais
 * stocké séparément.
 */
class JournalFinancierController extends Controller
{
    public function __construct(
        private readonly SiteScopeService $siteScope,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();

        $comptesTresorerie = CompteTresorerie::forOrg($orgId)->pluck('compte_comptable_id');

        $query = EcritureComptable::query()
            ->whereIn('compte_comptable_id', $comptesTresorerie)
            ->whereHas('piece', fn ($q) => $q->where('organization_id', $orgId))
            ->with(['piece.journal', 'piece.lignes.compte', 'site:id,nom']);

        if (! $isAdmin) {
            $query->whereIn('site_id', $this->siteScope->accessibleSiteIds($user));
        } elseif ($siteIds = array_filter((array) $request->input('site_ids', []))) {
            $query->whereIn('site_id', $siteIds);
        }

        if ($annee = $request->input('annee')) {
            $query->whereHas('piece', fn ($q) => $q->whereYear('date_piece', $annee));
        }
        if ($mois = $request->input('mois')) {
            $query->whereHas('piece', fn ($q) => $q->whereMonth('date_piece', $mois));
        }
        if ($journalCode = $request->input('journal')) {
            $query->whereHas('piece.journal', fn ($q) => $q->where('code', $journalCode));
        }
        if ($evenement = $request->input('evenement')) {
            $query->whereHas('piece', fn ($q) => $q->where('type_evenement', $evenement));
        }
        if ($compteId = $request->input('compte_id')) {
            $query->where('compte_comptable_id', $compteId);
        }
        if ($sens = $request->input('sens')) {
            $sens === 'entree' ? $query->where('debit', '>', 0) : $query->where('credit', '>', 0);
        }
        if ($reference = trim((string) $request->input('reference', ''))) {
            $ref = mb_strtolower($reference);
            $query->whereHas('piece', fn ($q) => $q->whereRaw('LOWER(numero) LIKE ?', ["%{$ref}%"]));
        }

        // KPIs calculés sur le même filtre, avant pagination — jamais sur la seule page affichée.
        $kpiBase = (clone $query);
        $totalEntrees = (float) (clone $kpiBase)->sum('debit');
        $totalSorties = (float) (clone $kpiBase)->sum('credit');

        $lignes = $query->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (EcritureComptable $l) => [
                'id' => $l->id,
                'date_operation' => $l->piece->date_piece->toDateString(),
                'sens' => (float) $l->debit > 0 ? 'entree' : 'sortie',
                'evenement' => $l->piece->type_evenement,
                'evenement_label' => EvenementComptable::tryFrom($l->piece->type_evenement)?->label() ?? $l->piece->type_evenement,
                'libelle' => $l->libelle,
                'reference' => $l->piece->numero,
                'journal' => $l->piece->journal?->code,
                'montant' => (float) $l->debit + (float) $l->credit,
                'site' => $l->site ? ['id' => $l->site->id, 'nom' => $l->site->nom] : null,
                'statut' => $l->piece->statut->value,
                'statut_label' => $l->piece->statut->label(),
                // Drill-down : les autres lignes de la même pièce, pour affichage
                // sans navigation supplémentaire (règle #2 du chantier — simple et lisible).
                'piece_lignes' => $l->piece->lignes->map(fn (EcritureComptable $pl) => [
                    'compte_numero' => $pl->compte?->numero,
                    'compte_libelle' => $pl->compte?->libelle,
                    'libelle' => $pl->libelle,
                    'debit' => (float) $pl->debit,
                    'credit' => (float) $pl->credit,
                ])->values(),
            ]);

        return Inertia::render('Comptabilite/Journal', [
            'lignes' => $lignes,
            'sens_options' => [
                ['value' => 'entree', 'label' => 'Entrée'],
                ['value' => 'sortie', 'label' => 'Sortie'],
            ],
            'evenement_options' => $this->evenementsTresorerie(),
            'journal_options' => JournalComptable::where('organization_id', $orgId)->orderBy('code')->get(['code', 'libelle'])
                ->map(fn (JournalComptable $j) => ['value' => $j->code, 'label' => "{$j->code} — {$j->libelle}"]),
            'compte_options' => CompteComptable::whereIn('id', $comptesTresorerie)->orderBy('numero')->get(['id', 'numero', 'libelle'])
                ->map(fn (CompteComptable $c) => ['value' => $c->id, 'label' => "{$c->numero} — {$c->libelle}"]),
            'sites' => $this->sitesDisponibles($orgId, $user),
            'is_admin' => $isAdmin,
            'filters' => [
                'annee' => $request->input('annee', ''),
                'mois' => $request->input('mois', ''),
                'journal' => $request->input('journal', ''),
                'evenement' => $request->input('evenement', ''),
                'compte_id' => $request->input('compte_id', ''),
                'sens' => $request->input('sens', ''),
                'reference' => $request->input('reference', ''),
                'site_ids' => array_values(array_filter((array) $request->input('site_ids', []))),
            ],
            'kpis' => [
                'total_entrees' => $totalEntrees,
                'total_sorties' => $totalSorties,
                'solde' => $totalEntrees - $totalSorties,
            ],
        ]);
    }

    /** @return array<int, array{value:string,label:string}> */
    private function evenementsTresorerie(): array
    {
        // Seuls les événements qui produisent effectivement une ligne sur un compte de
        // trésorerie ont leur place ici (ex: fiche_*_validee n'en produit jamais).
        $pertinents = [
            EvenementComptable::PAIEMENT_PROPRIETAIRE,
            EvenementComptable::PAIEMENT_LIVREUR,
            EvenementComptable::PAIEMENT_SITE,
            EvenementComptable::PAIEMENT_CONSULTANT,
            EvenementComptable::DEPENSE_INTERNE_VALIDEE,
            EvenementComptable::DEPENSE_AVANCE_TIERS_VALIDEE,
            EvenementComptable::ENCAISSEMENT_VENTE_RECU,
            EvenementComptable::PAIEMENT_SALAIRE,
            EvenementComptable::MOUVEMENT_FONDS_ENVOYE,
            EvenementComptable::MOUVEMENT_FONDS_RECU,
            EvenementComptable::SOLDE_OUVERTURE_TRESORERIE,
            EvenementComptable::PAIEMENT_COMMISSION_LOGISTIQUE_DIRECT,
            EvenementComptable::VERSEMENT_CASHBACK,
        ];

        return collect($pertinents)
            ->map(fn (EvenementComptable $e) => ['value' => $e->value, 'label' => $e->label()])
            ->all();
    }

    /** @return array<int, array{value:string,label:string}> */
    private function sitesDisponibles(string $orgId, $user): array
    {
        $query = Site::where('organization_id', $orgId)->orderBy('nom');

        if (! $user->isAdmin()) {
            $query->whereIn('id', $this->siteScope->accessibleSiteIds($user));
        }

        return $query->get(['id', 'nom'])->map(fn (Site $s) => ['value' => $s->id, 'label' => $s->nom])->all();
    }
}
