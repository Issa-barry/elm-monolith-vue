<?php

namespace App\Http\Controllers\Testing;

use App\Enums\CategorieVehicule;
use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommandeVente;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\ProduitVariante;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\MatriculeService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $telephone = '+2246'.random_int(10000000, 99999999);

        [$user, $site] = DB::transaction(function () use ($suffixe, $telephone) {
            $org = Organization::create(['name' => "E2E Partage {$suffixe}", 'slug' => 'e2e-partage-'.Str::lower($suffixe), 'is_active' => true]);
            ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);
            $site = Site::create(['organization_id' => $org->id, 'nom' => "Site E2E {$suffixe}", 'type' => 'depot', 'localisation' => 'Conakry']);

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
        $orgId = $user->organization_id;

        $resultat = DB::transaction(function () use ($orgId, $site, $suffixe, $user) {
            $processus = CommissionProcessusDefaults::resoudreOuCreer($orgId, CommissionProcessus::CODE_VENTE);
            $type =TypeVehicule::create(['organization_id' => $orgId, 'nom' => "E2E Type {$suffixe}", 'is_active' => true]);
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
}
