<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Enums\EvenementComptable;
use App\Enums\StatutFactureFournisseur;
use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Models\Fournisseur;
use App\Models\PieceComptable;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Services\Comptabilite\FactureFournisseurComptabilisationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexFactureFournisseurController extends Controller
{
    public function __invoke(Request $request, PerimetreCommandesAchat $perimetre, FactureFournisseurComptabilisationService $comptabilisation): Response
    {
        $this->authorize('viewAny', FactureFournisseur::class);

        $user = $request->user();
        $filters = $request->only(['statut', 'fournisseur_id', 'numero']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));

        $query = fn () => $perimetre->appliquerFactures(FactureFournisseur::query(), $user)
            ->when($siteIds !== [], fn (Builder $q) => $q->whereIn('site_id', $siteIds))
            ->when($filters['statut'] ?? null, fn (Builder $q, string $s) => $q->where('statut', $s))
            ->when($filters['fournisseur_id'] ?? null, fn (Builder $q, string $id) => $q->where('fournisseur_id', $id))
            ->when(trim((string) ($filters['numero'] ?? '')) !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('reference', 'like', '%'.trim($filters['numero']).'%')
                ->orWhere('numero_facture_fournisseur', 'like', '%'.trim($filters['numero']).'%')));

        $paginator = $query()
            ->with(['fournisseur.personne', 'fournisseur.entrepriseTierce', 'site:id,nom', 'commande:id,reference'])
            ->orderByDesc('date_facture')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        // État comptable de la page en une requête (jamais « comptabilisée » sans pièce).
        $pieces = PieceComptable::query()
            ->where('organization_id', $user->organization_id)
            ->where('source_type', (new FactureFournisseur)->getMorphClass())
            ->whereIn('source_id', collect($paginator->items())->pluck('id'))
            ->where('type_evenement', EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value)
            ->get()
            ->keyBy('source_id');

        $dette = (float) $query()
            ->whereIn('statut', array_map(fn ($s) => $s->value, StatutFactureFournisseur::constatees()))
            ->selectRaw('COALESCE(SUM(montant_ttc - montant_paye), 0) as reste')
            ->value('reste');

        return Inertia::render('Achats/Factures/Index', [
            'factures' => $paginator->through(fn (FactureFournisseur $f) => [
                'comptabilite' => $comptabilisation->etat($f, $pieces->get($f->id), pieceConnue: true),
                'id' => $f->id,
                'reference' => $f->reference,
                'numero_facture_fournisseur' => $f->numero_facture_fournisseur,
                'date_facture' => $f->date_facture?->format('d/m/Y'),
                'fournisseur_nom' => $f->fournisseurNom(),
                'site_nom' => $f->site?->nom,
                'commande_reference' => $f->commande?->reference,
                'montant_ttc' => (float) $f->montant_ttc,
                'reste_du' => $f->resteDu(),
                'statut' => $f->statut?->value,
                'statut_label' => $f->statut?->label(),
            ]),
            'dette_totale' => $dette,
            'filters' => array_merge($filters, ['site_ids' => $siteIds]),
            'statuts' => StatutFactureFournisseur::options(),
            'fournisseurs' => Fournisseur::where('organization_id', $user->organization_id)
                ->with(['personne', 'entrepriseTierce'])
                ->get()
                ->sortBy('nom_complet')
                ->values()
                ->map(fn (Fournisseur $f) => ['value' => $f->id, 'label' => $f->nom_complet]),
            'sites' => $perimetre->sites($user)->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom])->values(),
        ]);
    }
}
