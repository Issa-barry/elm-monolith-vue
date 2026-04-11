<?php

namespace App\Http\Controllers;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use App\Models\EquipeLivraison;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransfertLogistiqueController extends Controller
{
    private const DATE_DISPLAY_FORMAT = 'd/m/Y';

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);

        $orgId  = auth()->user()->organization_id;
        $statut = $request->input('statut');
        $search = $request->input('search');

        $query = TransfertLogistique::with([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,nom',
            'commission:id,transfert_logistique_id,statut,montant_total,montant_verse',
        ])
            ->where('organization_id', $orgId)
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->when($search, fn ($q) => $q->where('reference', 'like', "%{$search}%"))
            ->orderByDesc('created_at');

        $transferts = $query->get();

        // KPIs
        $kpis = [
            'en_preparation' => $transferts->whereIn('statut', [
                StatutTransfert::PREPARATION->value,
                StatutTransfert::CHARGEMENT->value,
            ])->count(),
            'en_transit'  => $transferts->where('statut', StatutTransfert::TRANSIT->value)->count(),
            'en_reception'=> $transferts->where('statut', StatutTransfert::RECEPTION->value)->count(),
            'clotures_mois' => TransfertLogistique::where('organization_id', $orgId)
                ->where('statut', StatutTransfert::CLOTURE->value)
                ->whereYear('updated_at', now()->year)
                ->whereMonth('updated_at', now()->month)
                ->count(),
        ];

        return Inertia::render('Logistique/Index', [
            'transferts'     => $transferts->map(fn ($t) => $this->mapTransfert($t))->values(),
            'kpis'           => $kpis,
            'statuts'        => StatutTransfert::options(),
            'filtre_statut'  => $statut,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(): Response
    {
        $this->authorize('create', TransfertLogistique::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Logistique/Create', [
            'sites'    => Site::where('organization_id', $orgId)
                ->select('id', 'nom')
                ->orderBy('nom')
                ->get(),
            'vehicules'=> Vehicule::where('organization_id', $orgId)
                ->where('is_active', true)
                ->with('equipe:id,nom')
                ->select('id', 'nom_vehicule', 'immatriculation', 'equipe_livraison_id', 'capacite_packs')
                ->get()
                ->map(fn ($v) => [
                    'id'                  => $v->id,
                    'nom_vehicule'        => $v->nom_vehicule,
                    'immatriculation'     => $v->immatriculation,
                    'equipe_livraison_id' => $v->equipe_livraison_id,
                    'equipe_nom'          => $v->equipe?->nom,
                    'capacite_packs'      => $v->capacite_packs,
                ]),
            'equipes'  => EquipeLivraison::where('organization_id', $orgId)
                ->where('is_active', true)
                ->select('id', 'nom')
                ->orderBy('nom')
                ->get(),
            'produits' => Produit::where('organization_id', $orgId)
                ->select('id', 'nom')
                ->orderBy('nom')
                ->get(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', TransfertLogistique::class);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'site_source_id'      => ['required', 'integer', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'site_destination_id' => ['required', 'integer', Rule::exists('sites', 'id')->where('organization_id', $orgId), 'different:site_source_id'],
            'vehicule_id'         => ['nullable', 'integer', Rule::exists('vehicules', 'id')->where('organization_id', $orgId)],
            'equipe_livraison_id' => ['nullable', 'integer', Rule::exists('equipes_livraison', 'id')->where('organization_id', $orgId)],
            'date_depart_prevue'  => ['nullable', 'date'],
            'date_arrivee_prevue' => ['nullable', 'date', 'after_or_equal:date_depart_prevue'],
            'notes'               => ['nullable', 'string', 'max:1000'],
            'lignes'              => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer', Rule::exists('produits', 'id')->where('organization_id', $orgId)],
            'lignes.*.quantite_demandee' => ['required', 'integer', 'min:1'],
            'lignes.*.notes'      => ['nullable', 'string', 'max:250'],
        ], [
            'site_destination_id.different' => 'Le site destination doit être différent du site source.',
            'date_arrivee_prevue.after_or_equal' => 'La date d\'arrivée doit être postérieure ou égale à la date de départ.',
            'lignes.required' => 'Au moins une ligne produit est requise.',
            'lignes.*.produit_id.required' => 'Chaque ligne doit avoir un produit.',
            'lignes.*.quantite_demandee.min' => 'La quantité doit être supérieure à 0.',
        ]);

        DB::transaction(function () use ($data, $orgId) {
            $transfert = TransfertLogistique::create([
                'organization_id'    => $orgId,
                'site_source_id'     => $data['site_source_id'],
                'site_destination_id'=> $data['site_destination_id'],
                'vehicule_id'        => $data['vehicule_id'] ?? null,
                'equipe_livraison_id'=> $data['equipe_livraison_id'] ?? null,
                'date_depart_prevue' => $data['date_depart_prevue'] ?? null,
                'date_arrivee_prevue'=> $data['date_arrivee_prevue'] ?? null,
                'notes'              => $data['notes'] ?? null,
            ]);

            // Lignes — dédoublonner sur produit_id
            $seen = [];
            foreach ($data['lignes'] as $ligne) {
                $pid = $ligne['produit_id'];
                if (isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;

                $transfert->lignes()->create([
                    'produit_id'         => $pid,
                    'quantite_demandee'  => $ligne['quantite_demandee'],
                    'notes'              => $ligne['notes'] ?? null,
                ]);
            }

            return $transfert;
        });

        return redirect()->route('logistique.index')
            ->with('success', 'Transfert créé avec succès.');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(TransfertLogistique $transfert_logistique): Response
    {
        $this->authorize('view', $transfert_logistique);

        $transfert_logistique->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation,taux_commission_proprietaire',
            'vehicule.equipe:id,nom',
            'vehicule.proprietaire:id,prenom,nom',
            'equipeLivraison:id,nom',
            'lignes.produit:id,nom',
            'commission.parts.versements.createur:id,prenom,nom',
            'createur:id,prenom,nom',
        ]);

        return Inertia::render('Logistique/Show', [
            'transfert'           => $this->mapTransfertDetail($transfert_logistique),
            'statuts'             => StatutTransfert::options(),
            'types_ecart'         => TypeEcartLogistique::options(),
            'bases_calcul'        => BaseCalculLogistique::options(),
            'can_avancer'         => auth()->user()->can('avancerStatut', $transfert_logistique),
            'can_annuler'         => auth()->user()->can('annuler', $transfert_logistique),
            'can_update'          => auth()->user()->can('update', $transfert_logistique),
            'can_generer_commission'=> auth()->user()->can('genererCommission', $transfert_logistique),
            'can_verser_commission' => auth()->user()->can('verserCommission', $transfert_logistique),
        ]);
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    public function edit(TransfertLogistique $transfert_logistique): Response
    {
        $this->authorize('update', $transfert_logistique);

        $orgId = auth()->user()->organization_id;

        $transfert_logistique->load(['lignes.produit:id,nom', 'siteSource:id,nom', 'siteDestination:id,nom']);

        return Inertia::render('Logistique/Create', [
            'transfert' => $this->mapTransfertDetail($transfert_logistique),
            'sites'     => Site::where('organization_id', $orgId)->select('id', 'nom')->orderBy('nom')->get(),
            'vehicules' => Vehicule::where('organization_id', $orgId)->where('is_active', true)
                ->with('equipe:id,nom')
                ->select('id', 'nom_vehicule', 'immatriculation', 'equipe_livraison_id', 'capacite_packs')
                ->get()
                ->map(fn ($v) => [
                    'id'                  => $v->id,
                    'nom_vehicule'        => $v->nom_vehicule,
                    'immatriculation'     => $v->immatriculation,
                    'equipe_livraison_id' => $v->equipe_livraison_id,
                    'equipe_nom'          => $v->equipe?->nom,
                    'capacite_packs'      => $v->capacite_packs,
                ]),
            'equipes'   => EquipeLivraison::where('organization_id', $orgId)->where('is_active', true)->select('id', 'nom')->orderBy('nom')->get(),
            'produits'  => Produit::where('organization_id', $orgId)->select('id', 'nom')->orderBy('nom')->get(),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('update', $transfert_logistique);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'site_source_id'      => ['required', 'integer', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'site_destination_id' => ['required', 'integer', Rule::exists('sites', 'id')->where('organization_id', $orgId), 'different:site_source_id'],
            'vehicule_id'         => ['nullable', 'integer', Rule::exists('vehicules', 'id')->where('organization_id', $orgId)],
            'equipe_livraison_id' => ['nullable', 'integer', Rule::exists('equipes_livraison', 'id')->where('organization_id', $orgId)],
            'date_depart_prevue'  => ['nullable', 'date'],
            'date_arrivee_prevue' => ['nullable', 'date', 'after_or_equal:date_depart_prevue'],
            'notes'               => ['nullable', 'string', 'max:1000'],
            'lignes'              => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer', Rule::exists('produits', 'id')->where('organization_id', $orgId)],
            'lignes.*.quantite_demandee' => ['required', 'integer', 'min:1'],
            'lignes.*.notes'      => ['nullable', 'string', 'max:250'],
        ]);

        DB::transaction(function () use ($data, $transfert_logistique) {
            $transfert_logistique->update([
                'site_source_id'     => $data['site_source_id'],
                'site_destination_id'=> $data['site_destination_id'],
                'vehicule_id'        => $data['vehicule_id'] ?? null,
                'equipe_livraison_id'=> $data['equipe_livraison_id'] ?? null,
                'date_depart_prevue' => $data['date_depart_prevue'] ?? null,
                'date_arrivee_prevue'=> $data['date_arrivee_prevue'] ?? null,
                'notes'              => $data['notes'] ?? null,
            ]);

            // Remplacer toutes les lignes
            $transfert_logistique->lignes()->delete();

            $seen = [];
            foreach ($data['lignes'] as $ligne) {
                $pid = $ligne['produit_id'];
                if (isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;

                $transfert_logistique->lignes()->create([
                    'produit_id'        => $pid,
                    'quantite_demandee' => $ligne['quantite_demandee'],
                    'notes'             => $ligne['notes'] ?? null,
                ]);
            }
        });

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('success', 'Transfert mis à jour.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function destroy(TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('delete', $transfert_logistique);

        $transfert_logistique->delete();

        return redirect()->route('logistique.index')
            ->with('success', 'Transfert supprimé.');
    }

    // ── Mapping ───────────────────────────────────────────────────────────────

    private function mapTransfert(TransfertLogistique $t): array
    {
        return [
            'id'                  => $t->id,
            'reference'           => $t->reference,
            'site_source_nom'     => $t->siteSource?->nom,
            'site_destination_nom'=> $t->siteDestination?->nom,
            'vehicule_nom'        => $t->vehicule?->nom_vehicule,
            'immatriculation'     => $t->vehicule?->immatriculation,
            'equipe_nom'          => $t->equipeLivraison?->nom,
            'statut'              => $t->statut?->value,
            'statut_label'        => $t->statut_label,
            'statut_dot_class'    => $t->statut instanceof StatutTransfert ? $this->statutDotClass($t->statut) : 'bg-zinc-400',
            'date_depart_prevue'  => $t->date_depart_prevue?->format(self::DATE_DISPLAY_FORMAT),
            'date_arrivee_prevue' => $t->date_arrivee_prevue?->format(self::DATE_DISPLAY_FORMAT),
            'date_depart_reelle'  => $t->date_depart_reelle?->format(self::DATE_DISPLAY_FORMAT),
            'date_arrivee_reelle' => $t->date_arrivee_reelle?->format(self::DATE_DISPLAY_FORMAT),
            'commission_statut'   => $t->commission?->statut?->value,
            'commission_statut_label' => $t->commission?->statut_label,
            'is_brouillon'        => $t->isBrouillon(),
            'is_cloture'          => $t->isCloture(),
            'is_terminal'         => $t->isTerminal(),
            'is_annule'           => $t->isAnnule(),
            'is_editable'         => $t->isEditable(),
            'created_at'          => $t->created_at?->format(self::DATE_DISPLAY_FORMAT),
        ];
    }

    private function mapTransfertDetail(TransfertLogistique $t): array
    {
        $base = $this->mapTransfert($t);

        $base['notes']        = $t->notes;
        $base['vehicule_id']  = $t->vehicule_id;
        $base['equipe_livraison_id'] = $t->equipe_livraison_id;
        $base['site_source_id']      = $t->site_source_id;
        $base['site_destination_id'] = $t->site_destination_id;
        $base['createur']            = $t->createur ? trim($t->createur->prenom . ' ' . $t->createur->nom) : null;

        $base['lignes'] = $t->lignes->map(fn ($l) => [
            'id'                  => $l->id,
            'produit_id'          => $l->produit_id,
            'produit_nom'         => $l->produit?->nom,
            'quantite_demandee'   => $l->quantite_demandee,
            'quantite_chargee'    => $l->quantite_chargee,
            'quantite_recue'      => $l->quantite_recue,
            'ecart'               => $l->ecart,
            'ecart_type'          => $l->ecart_type?->value,
            'ecart_label'         => $l->ecart_label,
            'ecart_dot_class'     => $l->ecart_dot_class,
            'ecart_motif'         => $l->ecart_motif,
            'notes'               => $l->notes,
            'est_reception_complete' => $l->estReceptionComplete(),
        ])->values()->all();

        if ($t->commission) {
            $base['commission'] = $this->mapCommission($t->commission);
        } else {
            $base['commission'] = null;
        }

        return $base;
    }

    private function mapCommission(\App\Models\CommissionLogistique $c): array
    {
        return [
            'id'                => $c->id,
            'base_calcul'       => $c->base_calcul?->value,
            'base_calcul_label' => $c->base_calcul?->label(),
            'valeur_base'       => (float) $c->valeur_base,
            'quantite_reference'=> $c->quantite_reference,
            'montant_total'     => (float) $c->montant_total,
            'montant_verse'     => (float) $c->montant_verse,
            'montant_restant'   => (float) $c->montant_restant,
            'statut'            => $c->statut?->value,
            'statut_label'      => $c->statut_label,
            'statut_dot_class'  => $c->statut_dot_class,
            'is_versee'         => $c->isVersee(),
            'parts'             => $c->relationLoaded('parts') ? $c->parts->map(fn ($p) => [
                'id'                    => $p->id,
                'type_beneficiaire'     => $p->type_beneficiaire,
                'beneficiaire_nom'      => $p->beneficiaire_nom,
                'taux_commission'       => (float) $p->taux_commission,
                'montant_brut'          => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'montant_net'           => (float) $p->montant_net,
                'montant_verse'         => (float) $p->montant_verse,
                'montant_restant'       => (float) $p->montant_restant,
                'statut'                => $p->statut?->value,
                'statut_label'          => $p->statut_label,
                'statut_dot_class'      => $p->statut_dot_class,
                'is_versee'             => $p->isVersee(),
                'versements'            => $p->versements->map(fn ($v) => [
                    'id'             => $v->id,
                    'montant'        => (float) $v->montant,
                    'date_versement' => $v->date_versement?->format(self::DATE_DISPLAY_FORMAT),
                    'enregistre_le'  => $v->created_at?->format('d/m/Y H:i'),
                    'mode_paiement'  => $v->mode_paiement,
                    'note'           => $v->note,
                    'created_by'     => $v->createur ? trim($v->createur->prenom . ' ' . $v->createur->nom) : null,
                ])->values()->all(),
            ])->values()->all() : [],
        ];
    }

    private function statutDotClass(StatutTransfert $statut): string
    {
        return match ($statut) {
            StatutTransfert::BROUILLON   => 'bg-zinc-400 dark:bg-zinc-500',
            StatutTransfert::PREPARATION => 'bg-blue-400',
            StatutTransfert::CHARGEMENT  => 'bg-amber-400',
            StatutTransfert::TRANSIT     => 'bg-blue-500',
            StatutTransfert::RECEPTION   => 'bg-amber-500',
            StatutTransfert::CLOTURE     => 'bg-emerald-500',
            StatutTransfert::ANNULE      => 'bg-red-400',
        };
    }
}
