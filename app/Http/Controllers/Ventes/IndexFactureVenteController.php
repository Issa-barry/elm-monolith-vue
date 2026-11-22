<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\ModePaiement;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Site;
use App\Services\SavedFilterService;
use App\Services\Tresorerie\AgenceEncaissementResolver;
use App\Support\Periodes\Periode;
use App\Support\Ventes\ListeFactures;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexFactureVenteController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', CommandeVente::class);

        $request->validate([
            'saved_view' => ['nullable', 'ulid'],
            'recherche' => ['nullable', 'string', 'max:255'],
            'tri' => ['nullable', 'in:'.implode(',', ListeFactures::TRIS)],
            'sens' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $savedView = app(SavedFilterService::class)->applyToRequest($request, 'factures');

        $user = auth()->user();
        $orgId = $user->organization_id;
        $toutesAgences = $user->voitToutesLesAgences();

        // Sites auxquels l'utilisateur a accès (vide = tous : administrateur ou vision 360°, ADR 0025)
        $authorizedSiteIds = $toutesAgences ? collect() : $user->sites()->pluck('sites.id');

        $periode = Periode::depuisRequeteValidee($request, 'ce_mois');
        $statut = $request->input('statut') ?? 'tous';
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));
        $livreurId = $request->input('livreur_id');
        $vehiculeRecherche = $request->input('vehicule');
        $chauffeurRecherche = $request->input('chauffeur');
        $convoyeurRecherche = $request->input('convoyeur');
        $proprietaireRecherche = $request->input('proprietaire');
        $clientRecherche = $request->input('client');
        $referenceRecherche = $request->input('reference');
        $rechercheMobile = trim((string) $request->input('recherche', ''));
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
        if (! $toutesAgences && $authorizedSiteIds->isNotEmpty()) {
            $sitesQuery->whereIn('id', $authorizedSiteIds);
        }
        $sites = $sitesQuery->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->nom])
            ->prepend(['value' => 'tous', 'label' => 'Tous les sites'])
            ->values();

        // Relations lues par les lignes affichées seulement (la page en compte au plus 20).
        $query = FactureVente::with([
            'commande.vehicule',
            'commande.client',
            'commande.site',
            'commande.lignes:id,commande_vente_id,quantite_demandee,quantite_chargee,quantite_livree',
            'encaissements.creator',
        ])
            ->where('organization_id', $orgId);

        // Périmètre limité à ses agences : l'utilisateur ne voit que les factures des sites auxquels il est affecté
        if (! $toutesAgences && $authorizedSiteIds->isNotEmpty()) {
            $query->whereHas('commande', fn ($q) => $q->whereIn('site_id', $authorizedSiteIds));
        }

        if ($statut !== 'tous') {
            $query->where('statut_facture', $statut);
        }

        if ($toutesAgences && ! empty($siteIds)) {
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
                    ->where('livreurs.nom_complet', 'like', "%{$chauffeurRecherche}%")
                    ->orWhereHas('personne', fn ($p) => $p
                        ->where('nom', 'like', "%{$chauffeurRecherche}%")
                        ->orWhere('prenom', 'like', "%{$chauffeurRecherche}%")
                        ->orWhere('telephone', 'like', "%{$chauffeurRecherche}%"))));
        }

        if ($convoyeurRecherche) {
            $query->whereHas('commande.vehicule.equipe.membres', fn ($q) => $q
                ->where('role', 'convoyeur')
                ->whereHas('livreur', fn ($l) => $l
                    ->where('livreurs.nom_complet', 'like', "%{$convoyeurRecherche}%")
                    ->orWhereHas('personne', fn ($p) => $p
                        ->where('nom', 'like', "%{$convoyeurRecherche}%")
                        ->orWhere('prenom', 'like', "%{$convoyeurRecherche}%")
                        ->orWhere('telephone', 'like', "%{$convoyeurRecherche}%"))));
        }

        if ($proprietaireRecherche) {
            $query->whereHas('commande.vehicule.proprietaire', fn ($q) => $q
                ->whereHas('personne', fn ($p) => $p
                    ->where('nom', 'like', "%{$proprietaireRecherche}%")
                    ->orWhere('prenom', 'like', "%{$proprietaireRecherche}%")));
        }

        if ($clientRecherche) {
            $query->whereHas('commande.client', fn ($q) => $q
                ->where('nom', 'like', "%{$clientRecherche}%")
                ->orWhere('prenom', 'like', "%{$clientRecherche}%")
                ->orWhere('telephone', 'like', "%{$clientRecherche}%"));
        }

        // Recherche du téléphone (bloc mobile) : mêmes champs que l'ancienne recherche locale
        // (référence, véhicule, client, site), désormais faite par le serveur sur toutes les factures.
        if ($rechercheMobile !== '') {
            $like = "%{$rechercheMobile}%";
            $query->where(fn ($q) => $q
                ->where('factures_ventes.reference', 'like', $like)
                ->orWhereHas('commande.vehicule', fn ($v) => $v->where('nom_vehicule', 'like', $like))
                ->orWhereHas('commande.client', fn ($c) => $c
                    ->where('nom_complet', 'like', $like)
                    ->orWhere('nom', 'like', $like)
                    ->orWhere('prenom', 'like', $like))
                ->orWhereHas('commande.site', fn ($s) => $s->where('nom', 'like', $like)));
        }

        // Suivi des sommes dues toutes périodes confondues : mêmes filtres, sans la période. Inutile
        // quand la période est « Tout » (identique aux cartes).
        $suiviToutesPeriodes = $periode->estBornee()
            ? ListeFactures::suiviToutesPeriodes(clone $query)
            : null;

        $periode->appliquer($query, 'created_at');

        // Totaux sur toutes les factures filtrées, jamais sur la page affichée ; le comptage sert
        // aussi de total à la pagination.
        ['totaux' => $totaux, 'nb_resultats' => $nbResultats] = ListeFactures::totaux($query);

        $tri = $request->input('tri');
        $sens = $request->input('sens') === 'asc' ? 'asc' : 'desc';
        ListeFactures::trier($query, $tri, $sens);
        $pageFactures = $query->paginate(ListeFactures::PAR_PAGE, total: $nbResultats)->withQueryString();
        $facturesTrouvees = $pageFactures->getCollection();

        // Agences d'encaissement de l'utilisateur, moyens et caisses : une seule résolution pour la page.
        $encaissementParFacture = app(AgenceEncaissementResolver::class)->pourEcran($user, $facturesTrouvees);

        $factures = $facturesTrouvees
            ->map(fn (FactureVente $f) => [
                'id' => $f->id,
                'reference' => $f->reference,
                'commande_id' => $f->commande_vente_id,
                'vehicule_nom' => $f->commande?->vehicule?->nom_vehicule,
                'client_nom' => $f->commande?->client?->nom_complet,
                'site_nom' => $f->commande?->site?->nom,
                'quantite_totale' => $f->commande?->quantite_totale ?? 0,
                'montant_net' => (float) $f->montant_net,
                'montant_encaisse' => (float) $f->montant_encaisse,
                'montant_restant' => (float) $f->montant_restant,
                'statut_facture' => $f->statut_facture?->value,
                'statut_label' => $f->statut_label,
                'is_annulee' => $f->isAnnulee(),
                'is_payee' => $f->isPayee(),
                'is_encaissable' => $f->commande?->isEncaissable() ?? false,
                // Agence d'encaissement = agence de l'utilisateur (ADR 0012, AgenceEncaissementResolver) :
                // agences proposées, leurs moyens et leurs espèces. Les deux clés historiques reprennent
                // l'agence présélectionnée.
                'encaissement_agences' => $encaissementParFacture[$f->id] ?? null,
                'peut_encaisser_especes' => (bool) (self::agenceDefaut($encaissementParFacture[$f->id] ?? null)['peut_encaisser_especes'] ?? false),
                'moyens_encaissement' => self::agenceDefaut($encaissementParFacture[$f->id] ?? null)['moyens'] ?? [],
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
                        'operateur_mobile_money_label' => $e->operateur_mobile_money?->label(),
                        'reference_paiement' => $e->reference_paiement,
                        'note' => $e->note,
                        'created_by' => $e->creator?->name,
                    ])
                    ->all(),
            ]);

        return Inertia::render('Factures/Index', [
            'saved_view' => $savedView,
            'factures' => $factures->values(),
            'pagination' => [
                'page' => $pageFactures->currentPage(),
                'par_page' => $pageFactures->perPage(),
                'total' => $pageFactures->total(),
                'derniere_page' => $pageFactures->lastPage(),
                'tri' => $tri,
                'sens' => $tri !== null ? $sens : null,
            ],
            // Cartes : factures de la période filtrée (calcul SQL, ListeFactures::totaux()).
            'totaux' => $totaux,
            // Sommes encore dues toutes périodes confondues (null quand la période est « Tout »).
            'suivi_toutes_periodes' => $suiviToutesPeriodes,
            'recherche' => $rechercheMobile !== '' ? $rechercheMobile : null,
            ...$periode->valeursFiltre(),
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

    /**
     * Agence présélectionnée d'une entrée de AgenceEncaissementResolver::pourEcran() (null : aucune).
     *
     * @param  array<string, mixed>|null  $encaissement
     * @return array<string, mixed>|null
     */
    private static function agenceDefaut(?array $encaissement): ?array
    {
        return collect($encaissement['agences'] ?? [])->firstWhere('site_id', $encaissement['agence_defaut'] ?? null);
    }
}
