<?php

namespace App\Http\Controllers\Testing;

use App\Enums\CategorieVehicule;
use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\EvenementComptable;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Features\ModuleFeature;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\CompteComptable;
use App\Models\EcritureComptable;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\Parametre;
use App\Models\Personne;
use App\Models\PieceComptable;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\ProduitVariante;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use App\Services\MatriculeService;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Support de test E2E UNIQUEMENT — routé seulement sous APP_ENV=e2e (cf. routes/web.php), comme
 * CommissionE2eDiagnosticController. Prépare en une requête le contexte du parcours
 * « changement de barème Livreur → reconfiguration groupée → publication → relance d'une
 * commission partielle » (tests/e2e/commissions/reconfiguration-partages.spec.ts), qu'il serait
 * trop long de construire par l'interface.
 *
 * ORGANISATION DÉDIÉE, créée à chaque appel (site, super administrateur connecté dans la session
 * courante) : la suite E2E tourne en parallèle et ses autres specs supposent un état précis des
 * organisations partagées (« elm » sans aucun barème, cf. equipe-flow.spec.ts ; périodes de
 * paiement validées par commission-v2-full-chain sur « V2 Demo ») — cette spec ne doit ni les
 * polluer ni en dépendre.
 *
 * La commande PARTIELLE est construite comme en production avant le 24/09/2026 : partage non
 * conforme au barème, génération directe (le contrôle de création la refuserait aujourd'hui).
 */
class CommissionE2eFixturesController extends Controller
{
    public function partagesLivreur(Request $request): JsonResponse
    {
        $suffixe = Str::upper(Str::random(5));
        [$user, $site] = $this->organisationDedieeConnectee($request, 'Partage', $suffixe);
        $orgId = $user->organization_id;

        $resultat = DB::transaction(function () use ($orgId, $site, $suffixe, $user) {
            $processus = CommissionProcessusDefaults::resoudreOuCreer($orgId, CommissionProcessus::CODE_VENTE);
            $type = TypeVehicule::create(['organization_id' => $orgId, 'nom' => "E2E Type {$suffixe}", 'is_active' => true]);
            $categorie = Categorie::create(['organization_id' => $orgId, 'nom' => "E2E Partage {$suffixe}", 'statut' => 'actif']);

            $regle = fn (string $cible, int $montant, ?string $typeId = null) => CommissionRegle::create([
                'organization_id' => $orgId,
                'processus_id' => $processus->id,
                'libelle' => "E2E {$cible} {$suffixe}",
                'scope_type' => CommissionScopeType::CATEGORIE->value,
                'scope_id' => $categorie->id,
                'type_vehicule_id' => $typeId,
                'cible_type' => $cible,
                'mode' => $cible === CommissionCibleType::CODE_EQUIPE_LIVRAISON ? CommissionMode::A_REPARTIR->value : CommissionMode::DIRECT->value,
                'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                'montant' => $montant,
                'effective_from' => now()->subMonth()->toDateString(),
                'statut' => CommissionRegleStatut::ACTIVE->value,
            ]);
            $regle(CommissionCibleType::CODE_PROPRIETAIRE, 100);
            $regle(CommissionCibleType::CODE_PROPRIETAIRE, 100, $type->id);
            $regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 0);
            $regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $type->id);

            $vehicules = [];
            foreach ([['V1', [500, 300]], ['V2', [400, 400]], ['V3', [800]], ['V4', [500, 200]]] as $index => [$code, $parts]) {
                $vehicule = Vehicule::create([
                    'organization_id' => $orgId,
                    'site_id' => $site?->id,
                    'nom_vehicule' => "E2E {$code} {$suffixe}",
                    'immatriculation' => "E2E-{$suffixe}-{$index}",
                    'type_vehicule_id' => $type->id,
                    'capacite_packs' => 1000,
                    'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $orgId])->id,
                    'categorie' => CategorieVehicule::PARTENAIRE,
                    'livraison_vente' => true,
                    'livraison_logistique' => false,
                    'is_active' => true,
                ]);
                // V3 : équipe à is_active=false comme la plupart des équipes en service (drapeau lu
                // seulement par les distributions) — cas réel de la régression du 25/09/2026.
                $equipe = EquipeLivraison::create(['organization_id' => $orgId, 'vehicule_id' => $vehicule->id, 'is_active' => $code !== 'V3']);

                foreach ($parts as $ordre => $montant) {
                    $livreur = Livreur::factory()->create([
                        'organization_id' => $orgId,
                        'is_active' => true,
                        'nom_complet' => ($ordre === 0 ? 'Chauffeur ' : 'Convoyeur ')."{$code} {$suffixe}",
                    ]);
                    EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => $ordre === 0 ? 'chauffeur' : 'convoyeur', 'ordre' => $ordre]);
                    EquipeLivraisonPartageCategorie::create([
                        'equipe_id' => $equipe->id,
                        'processus_id' => $processus->id,
                        'categorie_id' => $categorie->id,
                        'livreur_id' => $livreur->id,
                        'part_pourcentage' => 0,
                        'montant_unitaire' => $montant,
                        'effective_from' => now()->subMonth()->toDateString(),
                    ]);
                }
                $vehicules[$code] = $vehicule;
            }

            ProduitTypeDefaultSeeder::seedPourOrganisation($orgId);
            $produit = Produit::create([
                'organization_id' => $orgId,
                'nom' => "E2E Pack {$suffixe}",
                'produit_type_id' => ProduitType::where('organization_id', $orgId)->where('code', 'service')->value('id'),
                'categorie_id' => $categorie->id,
                'statut' => 'actif',
            ]);
            $variante = ProduitVariante::create([
                'organization_id' => $orgId,
                'produit_id' => $produit->id,
                'is_default' => true,
                'is_active' => true,
                'prix_vente' => 2000,
                'prix_usine' => 1500,
                'prix_achat' => 1500,
                'cout' => 1000,
            ]);

            return compact('categorie', 'type', 'vehicules', 'variante', 'user', 'site');
        });

        // Commande PARTIELLE sur V4 (partage 700 ≠ barème 800) — hors transaction ci-dessus : le
        // moteur ouvre ses propres transactions.
        $commande = CommandeVente::create([
            'organization_id' => $orgId,
            'site_id' => $resultat['site']?->id,
            'vehicule_id' => $resultat['vehicules']['V4']->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 20000,
            'commission_eligible_snapshot' => true,
            'created_by' => $user->id,
        ]);
        $ligne = $commande->lignes()->create([
            'variante_id' => $resultat['variante']->id,
            'quantite_demandee' => 10,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => 20000,
        ]);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());
        CommandeVenteService::validerChargement($commande->fresh(), [['id' => $ligne->id, 'quantite_chargee' => 10, 'type_ecart' => 'conforme']]);
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());

        return response()->json([
            'categorie' => ['id' => $resultat['categorie']->id, 'nom' => $resultat['categorie']->nom],
            'type_vehicule' => ['id' => $resultat['type']->id, 'nom' => $resultat['type']->nom],
            'vehicules' => collect($resultat['vehicules'])->map(fn (Vehicule $v) => ['id' => $v->id, 'nom' => $v->nom_vehicule]),
            'produit_id' => $resultat['variante']->produit_id,
            'commande_partielle_id' => $commande->id,
        ]);
    }

    /**
     * Contexte du parcours « parrain » (tests/e2e/commissions/parrain-commission.spec.ts, ADR 0035) :
     * organisation dédiée (site central de trésorerie, plan comptable), barème Livreur 1 000 GNF/pack
     * en Vente et en Logistique, véhicule Vente + Logistique avec chauffeur et convoyeur (600 / 400),
     * caisse dédiée approvisionnée du payeur. Le parrain, lui, est ajouté par l'interface.
     */
    public function parrainCommission(Request $request): JsonResponse
    {
        $suffixe = Str::upper(Str::random(5));
        [$user, $site] = $this->organisationDedieeConnectee($request, 'Parrain', $suffixe, siteCentral: true);
        $orgId = $user->organization_id;

        Feature::for(Organization::find($orgId))->activate(ModuleFeature::COMPTABILITE);
        app(PlanComptableBootstrapService::class)->bootstrap($orgId);
        Parametre::setDeclencheurCommissionVente($orgId, DeclencheurCommissionVente::CHARGEMENT_VALIDE);
        Parametre::setVentesAutoriserStockNegatif($orgId, true);

        $resultat = DB::transaction(function () use ($orgId, $site, $suffixe) {
            $categorie = Categorie::create(['organization_id' => $orgId, 'nom' => "E2E Sachet {$suffixe}", 'statut' => 'actif']);
            $vehicule = Vehicule::create([
                'organization_id' => $orgId,
                'site_id' => $site->id,
                'nom_vehicule' => "E2E Parrainé {$suffixe}",
                'immatriculation' => "E2E-PAR-{$suffixe}",
                'capacite_packs' => 1000,
                'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $orgId])->id,
                'categorie' => CategorieVehicule::PARTENAIRE,
                'livraison_vente' => true,
                'livraison_logistique' => true,
                'is_active' => true,
            ]);
            $equipe = EquipeLivraison::create(['organization_id' => $orgId, 'vehicule_id' => $vehicule->id, 'is_active' => true]);

            $livreurs = [];
            foreach (['chauffeur' => 600, 'convoyeur' => 400] as $role => $montant) {
                $livreur = Livreur::factory()->create([
                    'organization_id' => $orgId,
                    'is_active' => true,
                    'nom_complet' => ucfirst($role)." E2E {$suffixe}",
                ]);
                EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => $role, 'ordre' => count($livreurs)]);
                $livreurs[$livreur->id] = $montant;
            }

            foreach ([CommissionProcessus::CODE_VENTE, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT] as $code) {
                $processus = CommissionProcessusDefaults::resoudreOuCreer($orgId, $code);
                CommissionRegle::create([
                    'organization_id' => $orgId,
                    'processus_id' => $processus->id,
                    'libelle' => "E2E Livreur {$code} {$suffixe}",
                    'scope_type' => CommissionScopeType::CATEGORIE->value,
                    'scope_id' => $categorie->id,
                    'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                    'mode' => CommissionMode::A_REPARTIR->value,
                    'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                    'montant' => 1000,
                    'effective_from' => now()->subMonth()->toDateString(),
                    'statut' => CommissionRegleStatut::ACTIVE->value,
                ]);
                foreach ($livreurs as $livreurId => $montant) {
                    EquipeLivraisonPartageCategorie::create([
                        'equipe_id' => $equipe->id,
                        'processus_id' => $processus->id,
                        'categorie_id' => $categorie->id,
                        'livreur_id' => $livreurId,
                        'part_pourcentage' => 0,
                        'montant_unitaire' => $montant,
                        'effective_from' => now()->subMonth()->toDateString(),
                    ]);
                }
            }

            $produit = Produit::create([
                'organization_id' => $orgId,
                'nom' => "E2E Pack {$suffixe}",
                'produit_type_id' => ProduitType::where('organization_id', $orgId)->where('code', 'service')->value('id'),
                'categorie_id' => $categorie->id,
                'statut' => 'actif',
            ]);
            $variante = ProduitVariante::create([
                'organization_id' => $orgId,
                'produit_id' => $produit->id,
                'is_default' => true,
                'is_active' => true,
                'prix_vente' => 2000,
                'prix_usine' => 1500,
                'prix_achat' => 1500,
                'cout' => 1000,
            ]);

            return compact('categorie', 'vehicule', 'variante');
        });

        // Payer une fiche en espèces = sortie de la caisse dédiée du payeur (ADR 0009).
        $caisse = app(SupportTresorerieValidationService::class)->valider(
            app(CaisseAgentService::class)->creer($orgId, $site->id, $user->id),
            $user,
        );
        app(EcritureComptableService::class)->comptabiliser(
            evenement: EvenementComptable::SOLDE_OUVERTURE_TRESORERIE,
            source: $caisse,
            organizationId: $orgId,
            dateComptable: now(),
            libelle: "Alimentation E2E {$suffixe}",
            lignes: [
                ['compte_comptable_id' => $caisse->compte_comptable_id, 'sens' => 'debit', 'montant' => 1_000_000],
                ['role' => 'contrepartie_ouverture', 'sens' => 'credit', 'montant' => 1_000_000],
            ],
            siteId: $site->id,
            createdBy: $user->id,
        );

        return response()->json([
            'vehicule' => ['id' => $resultat['vehicule']->id, 'nom' => $resultat['vehicule']->nom_vehicule],
            'categorie' => ['id' => $resultat['categorie']->id, 'nom' => $resultat['categorie']->nom],
            'variante_id' => $resultat['variante']->id,
            'site' => ['id' => $site->id, 'nom' => $site->nom],
        ]);
    }

    /**
     * Opération du véhicule parrainé, chargée et génératrice de commission : vente classique
     * (`vente`) ou transfert logistique entre sites (`transfert`). Renvoie l'enveloppe de l'équipe
     * et ses parts, pour vérifier la part du parrain sans écran dédié.
     */
    public function parrainOperation(Request $request, Vehicule $vehicule, string $type): JsonResponse
    {
        abort_unless($vehicule->organization_id === $request->user()->organization_id, 404);
        $quantite = (int) $request->input('quantite', 100);
        $variante = ProduitVariante::findOrFail($request->input('variante_id'));

        if ($type === 'transfert') {
            $destination = Site::create(['organization_id' => $vehicule->organization_id, 'nom' => 'Dépôt E2E '.Str::upper(Str::random(4)), 'type' => 'agence', 'localisation' => 'Kindia']);
            $source = TransfertLogistique::create([
                'organization_id' => $vehicule->organization_id,
                'site_source_id' => $vehicule->site_id,
                'site_destination_id' => $destination->id,
                'vehicule_id' => $vehicule->id,
                'equipe_livraison_id' => $vehicule->equipe?->id,
                'statut' => StatutTransfert::CHARGEMENT,
                'created_by' => $request->user()->id,
            ]);
            TransfertLigne::create([
                'transfert_logistique_id' => $source->id,
                'variante_id' => $variante->id,
                'quantite_demandee' => $quantite,
                'quantite_chargee' => $quantite,
            ]);
            CommissionEnveloppeGenerator::genererPourTransfertLogistique($source->fresh(), 'quantite_chargee');
        } else {
            $source = CommandeVente::create([
                'organization_id' => $vehicule->organization_id,
                'site_id' => $vehicule->site_id,
                'vehicule_id' => $vehicule->id,
                'nature_operation' => NatureOperation::VENTE_STANDARD->value,
                'statut' => StatutCommandeVente::BROUILLON,
                'total_commande' => $quantite * 2000,
                'commission_eligible_snapshot' => true,
                'created_by' => $request->user()->id,
            ]);
            $ligne = $source->lignes()->create([
                'variante_id' => $variante->id,
                'quantite_demandee' => $quantite,
                'prix_usine_snapshot' => 1500,
                'prix_vente_snapshot' => 2000,
                'total_ligne' => $quantite * 2000,
            ]);
            CommandeVenteService::confirmer($source);
            CommandeVenteService::demarrerChargement($source->fresh());
            CommandeVenteService::validerChargement($source->fresh(), [['id' => $ligne->id, 'quantite_chargee' => $quantite, 'type_ecart' => 'conforme']]);
        }

        $enveloppe = CommissionEnveloppe::where('source_type', $source::class)
            ->where('source_id', $source->id)
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)
            ->with('parts')
            ->first();

        return response()->json([
            'reference' => $source->reference,
            'montant_total' => $enveloppe ? (float) $enveloppe->montant_total : null,
            'parts' => $enveloppe
                ? $enveloppe->parts->map(fn ($p) => ['type' => $p->beneficiaire_type, 'montant' => (float) $p->montant_net])->values()
                : [],
        ]);
    }

    /**
     * État comptable et des fiches de l'organisation du véhicule parrainé, lu après le paiement :
     * fiches parrain et livreur (période, rang, montants, agence) et solde de chaque compte de
     * commission (622500, 467210, 467220 — débit moins crédit).
     */
    public function parrainEtat(Request $request, Vehicule $vehicule): JsonResponse
    {
        $orgId = $vehicule->organization_id;
        abort_unless($orgId === $request->user()->organization_id, 404);

        $fiches = PaiementFiche::where('organization_id', $orgId)
            ->whereIn('beneficiaire_type', ['parrain', 'livreur'])
            ->with('periode:id,reference')
            ->orderBy('rang')
            ->get()
            ->map(fn (PaiementFiche $f) => [
                'type' => $f->beneficiaire_type,
                'periode' => $f->periode?->reference,
                'rang' => $f->rang,
                'montant_net' => (float) $f->montant_net,
                'montant_paye' => (float) $f->montant_paye,
                'montant_restant' => $f->montant_restant,
                'site_id' => $f->site_id,
            ])
            ->values();

        $soldes = EcritureComptable::query()
            ->join('compta_comptes', 'compta_comptes.id', '=', 'compta_ecritures.compte_comptable_id')
            ->where('compta_comptes.organization_id', $orgId)
            ->whereIn('compta_comptes.numero', ['622500', '467210', '467220'])
            ->groupBy('compta_comptes.numero')
            ->selectRaw('compta_comptes.numero as numero, SUM(compta_ecritures.debit) - SUM(compta_ecritures.credit) as solde')
            ->pluck('solde', 'numero')
            ->map(fn ($solde) => (float) $solde);

        return response()->json([
            'fiches' => $fiches,
            'soldes' => $soldes,
            'libelles' => CompteComptable::where('organization_id', $orgId)
                ->whereIn('numero', ['622500', '467210', '467220'])
                ->pluck('libelle', 'numero'),
            'pieces' => PieceComptable::where('organization_id', $orgId)
                ->whereIn('type_evenement', [EvenementComptable::FICHE_PARRAIN_VALIDEE->value, EvenementComptable::PAIEMENT_PARRAIN->value])
                ->groupBy('type_evenement')
                ->selectRaw('type_evenement, COUNT(*) as nombre')
                ->pluck('nombre', 'type_evenement'),
        ]);
    }

    /**
     * Organisation dédiée (site, super administrateur) créée à chaque appel, et session connectée à
     * son administrateur : la spec ne dépend d'aucune donnée partagée de la base E2E.
     *
     * @return array{0: User, 1: Site}
     */
    private function organisationDedieeConnectee(Request $request, string $libelle, string $suffixe, bool $siteCentral = false): array
    {
        $telephone = '+2246'.random_int(10000000, 99999999);

        [$user, $site] = DB::transaction(function () use ($suffixe, $telephone, $libelle, $siteCentral) {
            $org = Organization::create(['name' => "E2E {$libelle} {$suffixe}", 'slug' => 'e2e-'.Str::lower($libelle).'-'.Str::lower($suffixe), 'is_active' => true]);
            ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
            $site = Site::create(['organization_id' => $org->id, 'nom' => "Site E2E {$suffixe}", 'type' => 'depot', 'localisation' => 'Conakry', 'is_central_tresorerie' => $siteCentral]);

            $personne = Personne::resoudreOuCreer($org->id, [
                'prenom' => 'Admin',
                'nom' => "E2E {$suffixe}",
                'telephone' => $telephone,
                'code_pays' => 'GN',
                'code_phone_pays' => '+224',
                'pays' => 'Guinée',
                'ville' => 'Conakry',
            ]);
            $user = User::create(['personne_id' => $personne->id, 'organization_id' => $org->id, 'password' => 'E2ePartage@2026', 'is_active' => true]);
            $user->authIdentities()->create([
                'type' => UserAuthIdentity::TYPE_TELEPHONE,
                'value' => $telephone,
                'normalized_value' => Personne::normaliserTelephone($telephone),
                'verified_at' => now(),
                'is_primary' => true,
            ]);
            Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
            $user->syncRoles(['super_admin']);
            app(MatriculeService::class)->assignForUser($user);
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

            return [$user, $site];
        });

        Auth::login($user);
        $request->session()->regenerate();

        return [$user, $site];
    }

    /**
     * Accorde (POST) ou retire (DELETE) à l'utilisateur connecté la permission directe
     * `ventes.annuler_exceptionnel`, exigée pour supprimer un encaissement depuis le 24/09/2026 et
     * réservée au super administrateur. Le compte « Admin V2 Demo » (admin_entreprise) doit pouvoir
     * exercer le vrai endpoint de suppression le temps d'une spec, sans modifier les rôles seedés.
     */
    public function permissionAnnulationExceptionnelle(Request $request): JsonResponse
    {
        $permission = Permission::findOrCreate('ventes.annuler_exceptionnel', 'web');
        $user = $request->user();

        $request->isMethod('delete')
            ? $user->revokePermissionTo($permission)
            : $user->givePermissionTo($permission);

        return response()->json(['granted' => $user->fresh()->hasDirectPermission($permission)]);
    }
}
