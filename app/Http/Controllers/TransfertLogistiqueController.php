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
use App\Services\TransfertActiviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransfertLogistiqueController extends Controller
{
    private const DATE_DISPLAY_FORMAT = 'd/m/Y';

    // ── Index (rétro-compatibilité — redirect géré dans routes/web.php) ──────

    // ── Index Transferts ──────────────────────────────────────────────────────

    public function indexTransferts(Request $request): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);

        return $this->buildIndex($request, 'transferts');
    }

    // ── Index Réceptions ──────────────────────────────────────────────────────

    public function indexReceptions(Request $request): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);

        return $this->buildIndex($request, 'receptions');
    }

    // ── Logique partagée des deux index ──────────────────────────────────────

    private function buildIndex(Request $request, string $vue): Response
    {
        $user = auth()->user();
        $orgId = $user->organization_id;
        $statut = $request->input('statut');
        $search = $request->input('search');
        $isAdmin = $user->hasAnyRole(['super_admin', 'admin_entreprise']);
        $siteIds = $isAdmin ? collect() : $user->sites()->pluck('sites.id');

        // ── Requête principale ─────────────────────────────────────────────────
        $query = TransfertLogistique::with([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,nom',
            'commission:id,transfert_logistique_id,statut,montant_total,montant_verse',
        ])->where('organization_id', $orgId);

        if ($vue === 'receptions') {
            if ($isAdmin) {
                // Admins : RECEPTION + CLOTURE, toute l'organisation
                $statutsVue = [StatutTransfert::RECEPTION->value, StatutTransfert::CLOTURE->value];
                $query->when($statut, fn ($q) => $q->where('statut', $statut))
                    ->when(! $statut, fn ($q) => $q->whereIn('statut', $statutsVue));
            } else {
                // Non-admins : TRANSIT + RECEPTION + CLOTURE où je suis le destinataire
                $statutsVue = [
                    StatutTransfert::TRANSIT->value,
                    StatutTransfert::RECEPTION->value,
                    StatutTransfert::CLOTURE->value,
                ];
                $query->whereIn('site_destination_id', $siteIds)
                    ->when($statut, fn ($q) => $q->where('statut', $statut))
                    ->when(! $statut, fn ($q) => $q->whereIn('statut', $statutsVue));
            }
        } else {
            // Vue Transferts
            $statutsVue = [
                StatutTransfert::BROUILLON->value,
                StatutTransfert::CHARGEMENT->value,
                StatutTransfert::TRANSIT->value,
                StatutTransfert::ANNULE->value,
            ];

            if ($isAdmin) {
                $query->when($statut, fn ($q) => $q->where('statut', $statut))
                    ->when(! $statut, fn ($q) => $q->whereIn('statut', $statutsVue));
            } else {
                // Non-admins :
                // - BROUILLON / CHARGEMENT / ANNULE : source OU destination dans mes sites
                // - TRANSIT : uniquement si je suis la source (sinon → Réceptions)
                if ($statut === StatutTransfert::TRANSIT->value) {
                    $query->where('statut', StatutTransfert::TRANSIT->value)
                        ->whereIn('site_source_id', $siteIds);
                } elseif ($statut) {
                    $query->where('statut', $statut)
                        ->where(fn ($q) => $q->whereIn('site_source_id', $siteIds)
                            ->orWhereIn('site_destination_id', $siteIds));
                } else {
                    $query->where(function ($q) use ($siteIds) {
                        // BROUILLON / CHARGEMENT / ANNULE : site impliqué
                        $q->where(function ($sub) use ($siteIds) {
                            $sub->whereIn('statut', [
                                StatutTransfert::BROUILLON->value,
                                StatutTransfert::CHARGEMENT->value,
                                StatutTransfert::ANNULE->value,
                            ])->where(fn ($s) => $s->whereIn('site_source_id', $siteIds)
                                ->orWhereIn('site_destination_id', $siteIds));
                        })
                        // TRANSIT : uniquement si je suis la source
                            ->orWhere(function ($sub) use ($siteIds) {
                                $sub->where('statut', StatutTransfert::TRANSIT->value)
                                    ->whereIn('site_source_id', $siteIds);
                            });
                    });
                }
            }
        }

        $query->when($search, fn ($q) => $q->where('reference', 'like', "%{$search}%"))
            ->orderByDesc('created_at');

        $transferts = $query->get();

        // ── Dropdown statuts ───────────────────────────────────────────────────
        $statutsFiltre = array_values(array_filter(
            StatutTransfert::options(),
            fn ($o) => in_array($o['value'], $statutsVue, true)
        ));

        // ── KPIs ──────────────────────────────────────────────────────────────
        if ($vue === 'receptions') {
            $clotureQuery = TransfertLogistique::where('organization_id', $orgId)
                ->where('statut', StatutTransfert::CLOTURE->value)
                ->whereYear('updated_at', now()->year)
                ->whereMonth('updated_at', now()->month);
            if (! $isAdmin && $siteIds->isNotEmpty()) {
                $clotureQuery->whereIn('site_destination_id', $siteIds);
            }
            $kpis = [
                'en_attente' => $transferts->filter(
                    fn ($t) => $t->statut === StatutTransfert::TRANSIT
                        || $t->statut === StatutTransfert::RECEPTION
                )->count(),
                'clotures_mois' => $clotureQuery->count(),
            ];
        } else {
            $kpis = [
                'brouillons' => $transferts->where('statut', StatutTransfert::BROUILLON)->count(),
                'en_chargement' => $transferts->where('statut', StatutTransfert::CHARGEMENT)->count(),
                'en_transit' => $transferts->where('statut', StatutTransfert::TRANSIT)->count(),
            ];
        }

        return Inertia::render('Logistique/Index', [
            'transferts' => $transferts->map(fn ($t) => $this->mapTransfert($t))->values(),
            'kpis' => $kpis,
            'statuts' => $statutsFiltre,
            'filtre_statut' => $statut,
            'vue' => $vue,
            'can_create' => auth()->user()->can('create', TransfertLogistique::class),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(): Response
    {
        $this->authorize('create', TransfertLogistique::class);

        $user = auth()->user();
        $orgId = $user->organization_id;

        // Site source = site par défaut de l'utilisateur (ou premier site affecté)
        $siteSourceModel = $user->sites()->where('is_default', true)->select('sites.id', 'sites.nom')->first()
            ?? $user->sites()->select('sites.id', 'sites.nom')->first();

        $siteSource = $siteSourceModel ? ['id' => $siteSourceModel->id, 'nom' => $siteSourceModel->nom] : null;

        return Inertia::render('Logistique/Create', [
            'site_source' => $siteSource,
            'sites' => Site::where('organization_id', $orgId)
                ->select('id', 'nom')
                ->orderBy('nom')
                ->get(),
            'vehicules' => Vehicule::where('organization_id', $orgId)
                ->where('is_active', true)
                ->where('categorie', 'interne')
                ->with('equipe:id,nom,vehicule_id')
                ->select('id', 'nom_vehicule', 'immatriculation', 'capacite_packs')
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'equipe_livraison_id' => $v->equipe?->id,
                    'equipe_nom' => $v->equipe?->nom,
                    'capacite_packs' => $v->capacite_packs,
                ]),
            'equipes' => EquipeLivraison::where('organization_id', $orgId)
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

        $user = auth()->user();
        $orgId = $user->organization_id;

        // Site source forcé depuis le site par défaut de l'utilisateur
        $siteSource = $user->sites()->where('is_default', true)->first()
            ?? $user->sites()->first();

        if (! $siteSource) {
            return back()->withErrors(['site_source_id' => 'Vous n\'êtes affecté à aucun site.']);
        }

        $data = $request->validate([
            'site_destination_id' => ['required', 'integer', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'vehicule_id' => ['required', 'integer', Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->where('categorie', 'interne')],
            'equipe_livraison_id' => ['nullable', 'integer', Rule::exists('equipes_livraison', 'id')->where('organization_id', $orgId)],
            'date_depart_prevue' => ['nullable', 'date'],
            'date_arrivee_prevue' => ['nullable', 'date', 'after_or_equal:date_depart_prevue'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer', Rule::exists('produits', 'id')->where('organization_id', $orgId)],
            'lignes.*.quantite_demandee' => ['required', 'integer', 'min:1'],
            'lignes.*.notes' => ['nullable', 'string', 'max:250'],
        ], [
            'vehicule_id.required' => 'Le véhicule est obligatoire.',
            'vehicule_id.exists' => 'Seuls les véhicules internes sont autorisés pour un transfert.',
            'date_arrivee_prevue.after_or_equal' => 'La date d\'arrivée doit être postérieure ou égale à la date de départ.',
            'lignes.required' => 'Au moins une ligne produit est requise.',
            'lignes.*.produit_id.required' => 'Chaque ligne doit avoir un produit.',
            'lignes.*.quantite_demandee.min' => 'La quantité doit être supérieure à 0.',
        ]);

        // Forcer le site source et vérifier que destination ≠ source
        $data['site_source_id'] = $siteSource->id;
        if ((int) $data['site_destination_id'] === $siteSource->id) {
            return back()->withErrors(['site_destination_id' => 'Le site destination doit être différent du site source.']);
        }

        $transfert = DB::transaction(function () use ($data, $orgId) {
            $transfert = TransfertLogistique::create([
                'organization_id' => $orgId,
                'site_source_id' => $data['site_source_id'],
                'site_destination_id' => $data['site_destination_id'],
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'equipe_livraison_id' => $data['equipe_livraison_id'] ?? null,
                'date_depart_prevue' => $data['date_depart_prevue'] ?? null,
                'date_arrivee_prevue' => $data['date_arrivee_prevue'] ?? null,
                'notes' => $data['notes'] ?? null,
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
                    'produit_id' => $pid,
                    'quantite_demandee' => $ligne['quantite_demandee'],
                    'notes' => $ligne['notes'] ?? null,
                ]);
            }

            return $transfert;
        });

        TransfertActiviteService::log($transfert, 'creation');

        return redirect()->route('logistique.edit', $transfert)
            ->with('success', 'Transfert créé avec succès.');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(TransfertLogistique $transfert_logistique): Response
    {
        $this->authorize('view', $transfert_logistique);

        $transfert_logistique->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'vehicule.equipe:id,nom',
            'vehicule.proprietaire:id,prenom,nom',
            'equipeLivraison:id,nom',
            'lignes.produit:id,nom',
            'commission.parts.versements.createur:id,prenom,nom',
            'createur:id,prenom,nom',
            'activites.user:id,prenom,nom',
        ]);

        // Contexte de navigation : transferts ou réceptions
        $user = auth()->user();
        $statut = $transfert_logistique->statut;
        $isAdmin = $user->hasAnyRole(['super_admin', 'admin_entreprise']);

        if ($isAdmin) {
            $contexte = in_array($statut, [StatutTransfert::RECEPTION, StatutTransfert::CLOTURE])
                ? 'receptions' : 'transferts';
        } else {
            $siteIds = $user->sites()->pluck('sites.id');
            $isDestination = $siteIds->contains($transfert_logistique->site_destination_id);
            $contexte = ($isDestination && in_array($statut, [
                StatutTransfert::TRANSIT,
                StatutTransfert::RECEPTION,
                StatutTransfert::CLOTURE,
            ])) ? 'receptions' : 'transferts';
        }

        return Inertia::render('Logistique/Show', [
            'transfert' => $this->mapTransfertDetail($transfert_logistique),
            'contexte' => $contexte,
            'statuts' => StatutTransfert::options(),
            'types_ecart' => TypeEcartLogistique::options(),
            'bases_calcul' => BaseCalculLogistique::options(),
            'can_avancer' => $user->can('avancerStatut', $transfert_logistique),
            'can_valider_reception' => $user->can('validerReception', $transfert_logistique),
            'can_annuler' => $user->can('annuler', $transfert_logistique),
            'can_update' => $user->can('update', $transfert_logistique),
            'can_generer_commission' => $user->can('genererCommission', $transfert_logistique),
            'can_verser_commission' => $user->can('verserCommission', $transfert_logistique),
            'activites' => $transfert_logistique->activites->map(fn ($a) => [
                'id' => $a->id,
                'action' => $a->action,
                'action_label' => $a->action_label,
                'user_nom' => $a->user ? trim($a->user->prenom.' '.$a->user->nom) : 'Système',
                'details' => $a->details,
                'created_at' => $a->created_at->format('d/m/Y H:i'),
            ])->values(),
        ]);
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    public function edit(TransfertLogistique $transfert_logistique): Response
    {
        $this->authorize('update', $transfert_logistique);

        $orgId = auth()->user()->organization_id;

        $transfert_logistique->load(['lignes.produit:id,nom', 'siteSource:id,nom', 'siteDestination:id,nom']);

        $siteSourceModel = $transfert_logistique->siteSource;

        return Inertia::render('Logistique/Create', [
            'transfert' => $this->mapTransfertDetail($transfert_logistique),
            'site_source' => $siteSourceModel ? ['id' => $siteSourceModel->id, 'nom' => $siteSourceModel->nom] : null,
            'sites' => Site::where('organization_id', $orgId)->select('id', 'nom')->orderBy('nom')->get(),
            'vehicules' => Vehicule::where('organization_id', $orgId)->where('is_active', true)
                ->where('categorie', 'interne')
                ->with('equipe:id,nom,vehicule_id')
                ->select('id', 'nom_vehicule', 'immatriculation', 'capacite_packs')
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'equipe_livraison_id' => $v->equipe?->id,
                    'equipe_nom' => $v->equipe?->nom,
                    'capacite_packs' => $v->capacite_packs,
                ]),
            'equipes' => EquipeLivraison::where('organization_id', $orgId)->where('is_active', true)->select('id', 'nom')->orderBy('nom')->get(),
            'produits' => Produit::where('organization_id', $orgId)->select('id', 'nom')->orderBy('nom')->get(),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('update', $transfert_logistique);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'site_destination_id' => ['required', 'integer', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'vehicule_id' => ['required', 'integer', Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->where('categorie', 'interne')],
            'equipe_livraison_id' => ['nullable', 'integer', Rule::exists('equipes_livraison', 'id')->where('organization_id', $orgId)],
            'date_depart_prevue' => ['nullable', 'date'],
            'date_arrivee_prevue' => ['nullable', 'date', 'after_or_equal:date_depart_prevue'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer', Rule::exists('produits', 'id')->where('organization_id', $orgId)],
            'lignes.*.quantite_demandee' => ['required', 'integer', 'min:1'],
            'lignes.*.notes' => ['nullable', 'string', 'max:250'],
        ], [
            'vehicule_id.required' => 'Le véhicule est obligatoire.',
            'vehicule_id.exists' => 'Seuls les véhicules internes sont autorisés pour un transfert.',
            'lignes.*.produit_id.required' => 'Chaque ligne doit avoir un produit.',
            'lignes.*.quantite_demandee.min' => 'La quantité doit être supérieure à 0.',
        ]);

        // Le site source est immuable : on garde la valeur existante
        $data['site_source_id'] = $transfert_logistique->site_source_id;

        if ((int) $data['site_destination_id'] === $data['site_source_id']) {
            return back()->withErrors(['site_destination_id' => 'Le site destination doit être différent du site source.']);
        }

        DB::transaction(function () use ($data, $transfert_logistique) {
            $transfert_logistique->update([
                'site_source_id' => $data['site_source_id'],
                'site_destination_id' => $data['site_destination_id'],
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'equipe_livraison_id' => $data['equipe_livraison_id'] ?? null,
                'date_depart_prevue' => $data['date_depart_prevue'] ?? null,
                'date_arrivee_prevue' => $data['date_arrivee_prevue'] ?? null,
                'notes' => $data['notes'] ?? null,
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
                    'produit_id' => $pid,
                    'quantite_demandee' => $ligne['quantite_demandee'],
                    'notes' => $ligne['notes'] ?? null,
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
        $user = auth()->user();

        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'site_source_nom' => $t->siteSource?->nom,
            'site_destination_nom' => $t->siteDestination?->nom,
            'vehicule_nom' => $t->vehicule?->nom_vehicule,
            'immatriculation' => $t->vehicule?->immatriculation,
            'equipe_nom' => $t->equipeLivraison?->nom,
            'statut' => $t->statut?->value,
            'statut_label' => $t->statut_label,
            'statut_dot_class' => $t->statut instanceof StatutTransfert ? $this->statutDotClass($t->statut) : 'bg-zinc-400',
            'date_depart_prevue' => $t->date_depart_prevue?->format(self::DATE_DISPLAY_FORMAT),
            'date_arrivee_prevue' => $t->date_arrivee_prevue?->format(self::DATE_DISPLAY_FORMAT),
            'date_depart_reelle' => $t->date_depart_reelle?->format(self::DATE_DISPLAY_FORMAT),
            'date_arrivee_reelle' => $t->date_arrivee_reelle?->format(self::DATE_DISPLAY_FORMAT),
            'commission_statut' => $t->commission?->statut?->value,
            'commission_statut_label' => $t->commission?->statut_label,
            'is_brouillon' => $t->isBrouillon(),
            'is_cloture' => $t->isCloture(),
            'is_terminal' => $t->isTerminal(),
            'is_annule' => $t->isAnnule(),
            'is_editable' => $t->isEditable(),
            'can_annuler' => $user->can('annuler', $t),
            'can_valider_reception' => $user->can('validerReception', $t),
            'created_at' => $t->created_at?->format(self::DATE_DISPLAY_FORMAT),
        ];
    }

    private function mapTransfertDetail(TransfertLogistique $t): array
    {
        $base = $this->mapTransfert($t);

        $base['notes'] = $t->notes;
        $base['vehicule_id'] = $t->vehicule_id;
        $base['equipe_livraison_id'] = $t->equipe_livraison_id;
        $base['site_source_id'] = $t->site_source_id;
        $base['site_destination_id'] = $t->site_destination_id;
        $base['createur'] = $t->createur ? trim($t->createur->prenom.' '.$t->createur->nom) : null;

        $base['lignes'] = $t->lignes->map(fn ($l) => [
            'id' => $l->id,
            'produit_id' => $l->produit_id,
            'produit_nom' => $l->produit?->nom,
            'quantite_demandee' => $l->quantite_demandee,
            'quantite_chargee' => $l->quantite_chargee,
            'quantite_recue' => $l->quantite_recue,
            'ecart' => $l->ecart,
            'ecart_type' => $l->ecart_type?->value,
            'ecart_label' => $l->ecart_label,
            'ecart_dot_class' => $l->ecart_dot_class,
            'ecart_motif' => $l->ecart_motif,
            'notes' => $l->notes,
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
            'id' => $c->id,
            'base_calcul' => $c->base_calcul?->value,
            'base_calcul_label' => $c->base_calcul?->label(),
            'valeur_base' => (float) $c->valeur_base,
            'quantite_reference' => $c->quantite_reference,
            'montant_total' => (float) $c->montant_total,
            'montant_verse' => (float) $c->montant_verse,
            'montant_restant' => (float) $c->montant_restant,
            'statut' => $c->statut?->value,
            'statut_label' => $c->statut_label,
            'statut_dot_class' => $c->statut_dot_class,
            'is_versee' => $c->isVersee(),
            'parts' => $c->relationLoaded('parts') ? $c->parts->map(fn ($p) => [
                'id' => $p->id,
                'type_beneficiaire' => $p->type_beneficiaire,
                'beneficiaire_nom' => $p->beneficiaire_nom,
                'taux_commission' => (float) $p->taux_commission,
                'montant_brut' => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'montant_net' => (float) $p->montant_net,
                'montant_verse' => (float) $p->montant_verse,
                'montant_restant' => (float) $p->montant_restant,
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut_label,
                'statut_dot_class' => $p->statut_dot_class,
                'is_versee' => $p->isVersee(),
                'versements' => $p->versements->map(fn ($v) => [
                    'id' => $v->id,
                    'montant' => (float) $v->montant,
                    'date_versement' => $v->date_versement?->format(self::DATE_DISPLAY_FORMAT),
                    'enregistre_le' => $v->created_at?->format('d/m/Y H:i'),
                    'mode_paiement' => $v->mode_paiement,
                    'note' => $v->note,
                    'created_by' => $v->createur ? trim($v->createur->prenom.' '.$v->createur->nom) : null,
                ])->values()->all(),
            ])->values()->all() : [],
        ];
    }

    private function statutDotClass(StatutTransfert $statut): string
    {
        return $statut->dotClass();
    }
}
