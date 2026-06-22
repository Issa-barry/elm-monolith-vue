<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FactureVenteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CommandeVente::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();

        // Sites auxquels l'utilisateur a accès (vide = tous, pour un admin)
        $authorizedSiteIds = $isAdmin ? collect() : $user->sites()->pluck('sites.id');

        $periode = $request->input('periode', 'month');
        $statut = $request->input('statut', 'tous');
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));
        $livreurId = $request->input('livreur_id');
        $vehiculeRecherche = $request->input('vehicule');
        $chauffeurRecherche = $request->input('chauffeur');
        $convoyeurRecherche = $request->input('convoyeur');
        $proprietaireRecherche = $request->input('proprietaire');
        $clientRecherche = $request->input('client');
        $referenceRecherche = $request->input('reference');
        $livreurData = null;
        if ($livreurId) {
            $livreurModel = Livreur::where('organization_id', $orgId)->find($livreurId);
            if ($livreurModel) {
                $livreurData = [
                    'id' => $livreurModel->id,
                    'nom_complet' => $livreurModel->nom_complet,
                    'telephone' => $livreurModel->telephone,
                ];
            } else {
                $livreurId = null;
            }
        }

        // Liste des sites accessibles à l'utilisateur pour le filtre
        $sitesQuery = Site::where('organization_id', $orgId);
        if (! $isAdmin && $authorizedSiteIds->isNotEmpty()) {
            $sitesQuery->whereIn('id', $authorizedSiteIds);
        }
        $sites = $sitesQuery->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->nom])
            ->prepend(['value' => 'tous', 'label' => 'Tous les sites'])
            ->values();

        $query = FactureVente::with([
            'commande.vehicule.proprietaire',
            'commande.vehicule.equipe.membres.livreur',
            'commande.client',
            'commande.site',
            'encaissements.creator',
        ])
            ->where('organization_id', $orgId);

        // Un utilisateur non-admin ne voit que les factures des sites auxquels il est affecté
        if (! $isAdmin && $authorizedSiteIds->isNotEmpty()) {
            $query->whereHas('commande', fn ($q) => $q->whereIn('site_id', $authorizedSiteIds));
        }

        match ($periode) {
            'today' => $query->whereDate('created_at', Carbon::today()),
            'week' => $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
            'month' => $query->whereYear('created_at', Carbon::now()->year)
                ->whereMonth('created_at', Carbon::now()->month),
            default => null, // 'all' : pas de filtre date
        };

        if ($statut !== 'tous') {
            $query->where('statut_facture', $statut);
        }

        if ($isAdmin && ! empty($siteIds)) {
            $query->whereHas('commande', fn ($q) => $q->whereIn('site_id', $siteIds));
        }

        if ($livreurId) {
            $query->whereHas('commande.vehicule.equipe.membres', fn ($q) => $q->where('livreur_id', $livreurId));
        }

        if ($referenceRecherche) {
            $query->where('reference', 'like', "%{$referenceRecherche}%");
        }

        if ($vehiculeRecherche) {
            $query->whereHas('commande.vehicule', fn ($q) => $q
                ->where('nom_vehicule', 'like', "%{$vehiculeRecherche}%")
                ->orWhere('immatriculation', 'like', "%{$vehiculeRecherche}%"));
        }

        if ($chauffeurRecherche) {
            $query->whereHas('commande.vehicule.equipe.membres', fn ($q) => $q
                ->where('role', 'chauffeur')
                ->whereHas('livreur', fn ($l) => $l
                    ->where('nom', 'like', "%{$chauffeurRecherche}%")
                    ->orWhere('prenom', 'like', "%{$chauffeurRecherche}%")
                    ->orWhere('telephone', 'like', "%{$chauffeurRecherche}%")));
        }

        if ($convoyeurRecherche) {
            $query->whereHas('commande.vehicule.equipe.membres', fn ($q) => $q
                ->where('role', 'convoyeur')
                ->whereHas('livreur', fn ($l) => $l
                    ->where('nom', 'like', "%{$convoyeurRecherche}%")
                    ->orWhere('prenom', 'like', "%{$convoyeurRecherche}%")
                    ->orWhere('telephone', 'like', "%{$convoyeurRecherche}%")));
        }

        if ($proprietaireRecherche) {
            $query->whereHas('commande.vehicule.proprietaire', fn ($q) => $q
                ->where('nom', 'like', "%{$proprietaireRecherche}%")
                ->orWhere('prenom', 'like', "%{$proprietaireRecherche}%"));
        }

        if ($clientRecherche) {
            $query->whereHas('commande.client', fn ($q) => $q
                ->where('nom', 'like', "%{$clientRecherche}%")
                ->orWhere('prenom', 'like', "%{$clientRecherche}%")
                ->orWhere('telephone', 'like', "%{$clientRecherche}%"));
        }

        $factures = $query->orderByDesc('created_at')
            ->get()
            ->map(fn (FactureVente $f) => [
                'id' => $f->id,
                'reference' => $f->reference,
                'commande_id' => $f->commande_vente_id,
                'vehicule_nom' => $f->commande?->vehicule?->nom_vehicule,
                'client_nom' => $f->commande?->client
                    ? trim($f->commande->client->prenom.' '.$f->commande->client->nom)
                    : null,
                'site_nom' => $f->commande?->site?->nom,
                'montant_net' => (float) $f->montant_net,
                'montant_encaisse' => (float) $f->montant_encaisse,
                'montant_restant' => (float) $f->montant_restant,
                'statut_facture' => $f->statut_facture?->value,
                'statut_label' => $f->statut_label,
                'is_annulee' => $f->isAnnulee(),
                'is_payee' => $f->isPayee(),
                'is_encaissable' => $f->commande?->isEncaissable() ?? false,
                'created_at' => $f->created_at?->format('d/m/Y'),
                'encaissements' => $f->encaissements
                    ->sortByDesc(fn ($e) => $e->created_at?->timestamp ?? 0)
                    ->values()
                    ->map(fn ($e) => [
                        'id' => $e->id,
                        'montant' => (float) $e->montant,
                        'date_encaissement' => $e->date_encaissement?->format('d/m/Y'),
                        'enregistre_le' => $e->created_at?->format('d/m/Y H:i'),
                        'mode_paiement' => $e->mode_paiement instanceof ModePaiement
                            ? $e->mode_paiement->label()
                            : (string) $e->mode_paiement,
                        'note' => $e->note,
                        'created_by' => $e->creator?->name,
                    ])
                    ->all(),
            ]);

        // Totaux pour les cartes de synthèse
        $impayees = $factures->where('statut_facture', StatutFactureVente::IMPAYEE->value);
        $partielles = $factures->where('statut_facture', StatutFactureVente::PARTIEL->value);
        $payees = $factures->where('statut_facture', StatutFactureVente::PAYEE->value);

        $totaux = [
            'total_a_encaisser' => $factures
                ->whereNotIn('statut_facture', [StatutFactureVente::PAYEE->value, StatutFactureVente::ANNULEE->value])
                ->sum('montant_restant'),
            'nb_impayees' => $impayees->count(),
            'montant_impayees' => $impayees->sum('montant_restant'),
            'nb_partielles' => $partielles->count(),
            'montant_partielles' => $partielles->sum('montant_restant'),
            'nb_payees' => $payees->count(),
            'montant_payees' => $payees->sum('montant_net'),
        ];

        return Inertia::render('Factures/Index', [
            'factures' => $factures->values(),
            'totaux' => $totaux,
            'modes_paiement' => ModePaiement::options(),
            'periode' => $periode,
            'statut' => $statut,
            'site_ids' => $siteIds,
            'sites' => $sites,
            'livreur_id' => $livreurId,
            'livreur' => $livreurData,
            'vehicule' => $vehiculeRecherche,
            'chauffeur' => $chauffeurRecherche,
            'convoyeur' => $convoyeurRecherche,
            'proprietaire' => $proprietaireRecherche,
            'client' => $clientRecherche,
            'reference' => $referenceRecherche,
        ]);
    }
}
