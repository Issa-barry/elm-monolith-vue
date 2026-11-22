<?php

namespace Tests\Feature\Performance;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppeLigne;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Support\Commission\CommissionKpiBuckets;
use App\Support\Commission\CommissionProcessusFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Commissions des propriétaires et des sites (lot 9) : les écrans lisent les parts en lignes
 * légères (LecturePartsCommission) au lieu de les charger avec leur vente, leur véhicule, leur
 * site et leurs lignes. Chaque ligne affichée reste identique au calcul fait sur les modèles et
 * leurs relations, comme avant : montants, compartiments, véhicules et sites, agences,
 * processus, catégories. Le nombre de requêtes ne dépend pas du volume.
 */
class CommissionsProprietairesSitesTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private const URL = '/backoffice/comptabilite/commissions/';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer', 'commissions.exporter']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Ventes et transferts, source supprimée, véhicule et site supprimés, véhicule sans
     * propriétaire, montants ajustés et centimes, tous les statuts, lignes avec et sans catégorie,
     * dépenses véhicule, propriétaire et site.
     */
    private function jeu(int $repetitions = 1, string $p = ''): void
    {
        $org = $this->org->id;
        $a = Site::where('organization_id', $org)->firstOrFail();
        $b = Site::create(['organization_id' => $org, 'nom' => 'Agence B'.$p, 'type' => 'depot', 'localisation' => 'Kindia']);
        $c = Site::create(['organization_id' => $org, 'nom' => 'Agence C'.$p, 'type' => 'usine', 'localisation' => 'Labé']);
        $categories = [Categorie::create(['organization_id' => $org, 'nom' => 'Bouteille'.$p]), Categorie::create(['organization_id' => $org, 'nom' => 'Sachet'.$p])];
        $proprio = fn (string $nom) => Proprietaire::create(['organization_id' => $org, 'is_active' => true,
            'personne_id' => Personne::create(['organization_id' => $org, 'nom' => $nom.$p, 'prenom' => 'Test', 'telephone' => '6209'.random_int(10000, 99999)])->id]);
        [$p1, $p2, $p3] = [$proprio('Un'), $proprio('Deux'), $proprio('Trois')];
        $veh = fn (string $nom, Site $s, ?Proprietaire $pr) => Vehicule::create(['organization_id' => $org, 'nom_vehicule' => $nom.$p, 'immatriculation' => 'GN-'.$nom.$p,
            'site_id' => $s->id, 'categorie' => 'interne', 'capacite_packs' => 500, 'is_active' => true, 'proprietaire_id' => $pr?->id]);
        $vehicules = [$veh('V1', $a, $p1), $veh('V2', $b, $p2), $veh('V3', $c, $p1), null, $veh('V4', $a, $p3)];
        $proc = fn (string $code) => CommissionProcessus::firstOrCreate(['organization_id' => $org, 'code' => $code], ['libelle' => $code, 'declencheur' => 'chargement_valide', 'strategie_ancrage_site' => 'operation', 'statut' => 'actif'])->id;
        [$vente, $logistique, $distribution] = [$proc(CommissionProcessus::CODE_VENTE), $proc(CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT), $proc(CommissionProcessus::CODE_DISTRIBUTION_CLIENT)];
        $dates = ['2026-11-18', '2026-11-03', '2026-10-20', '2026-08-05'];
        $statuts = ['creee', 'impaye', 'partiel', 'paye', 'annulee', 'impaye'];

        foreach (range(0, 30 * $repetitions - 1) as $k) {
            $vehicule = $vehicules[$k % 5];
            $siteVente = [$a, $b, $c][$k % 3];
            $transfert = $k % 4 === 3;
            $source = $this->source($transfert, sprintf('CPS%s-%04d', $p, $k), $siteVente, $a, $vehicule);
            $processus = match (true) {
                $transfert => $logistique,
                $k % 5 === 0 => $distribution,
                default => $vente,
            };
            $operation = ['k' => $k, 'statut' => $statuts[($k * 5) % 6], 'date' => $dates[$k % 4], 'processus' => $processus, 'montant' => 20000 + 1234.56 * $k];
            $categoriesLignes = [$categories[0]->id, $k % 2 ? $categories[1]->id : null];
            $this->commission($source, 'proprietaire', ($vehicule?->proprietaire_id) ?? $p2->id, $operation, $categoriesLignes);
            $this->commission($source, 'site', $siteVente->id, $operation, $categoriesLignes);
            if ($k === 4) {
                $source->delete();
            }
        }
        $type = DepenseType::create(['organization_id' => $org, 'code' => 'AV'.$p, 'libelle' => 'Avance', 'categorie' => 'interne', 'commentaire_obligatoire' => false, 'justificatif_obligatoire' => false, 'is_active' => true]);
        foreach ([['vehicule', $vehicules[0]->id, 7000], ['vehicule', $vehicules[2]->id, 3000], ['proprietaire', $p1->id, 2500], ['site', $a->id, 4200], ['site', $b->id, 1300]] as $i => [$bt, $bid, $m]) {
            Depense::create(['organization_id' => $org, 'site_id' => $a->id, 'user_id' => $this->user->id, 'depense_type_id' => $type->id, 'beneficiaire_type' => $bt,
                'beneficiaire_id' => $bid, 'montant' => $m, 'date_depense' => $dates[$i % 4], 'statut' => StatutDepense::VALIDE->value]);
        }
        $vehicules[2]->delete();
        $c->delete();
    }

    private function source(bool $transfert, string $reference, Site $site, Site $destination, ?Vehicule $vehicule): CommandeVente|TransfertLogistique
    {
        if ($transfert) {
            return TransfertLogistique::create(['organization_id' => $this->org->id, 'site_source_id' => $site->id, 'site_destination_id' => $destination->id,
                'vehicule_id' => $vehicule?->id, 'created_by' => $this->user->id]);
        }

        return CommandeVente::withoutEvents(fn () => CommandeVente::create(['organization_id' => $this->org->id, 'reference' => $reference, 'site_id' => $site->id,
            'vehicule_id' => $vehicule?->id, 'statut' => 'livree', 'total_commande' => 500000]));
    }

    /**
     * Commission d'une cible (propriétaire ou site) avec ses lignes et la part du bénéficiaire.
     *
     * @param  array{k: int, statut: string, date: string, processus: string, montant: float}  $o
     * @param  list<?string>  $categories
     */
    private function commission(CommandeVente|TransfertLogistique $source, string $cible, string $beneficiaire, array $o, array $categories): void
    {
        $enveloppe = CommissionEnveloppe::create(['organization_id' => $this->org->id, 'source_type' => $source::class, 'source_id' => $source->id, 'processus_id' => $o['processus'],
            'cible_type' => $cible, 'cible_id' => (string) Str::ulid(), 'montant_total' => $o['montant'], 'earned_at' => $o['date'], 'statut' => $o['statut']]);
        foreach ($categories as $categorie) {
            CommissionEnveloppeLigne::create(['enveloppe_id' => $enveloppe->id, 'source_ligne_type' => 'App\\Models\\CommandeVenteLigne', 'source_ligne_id' => (string) Str::ulid(),
                'categorie_id_snapshot' => $categorie, 'quantite' => 10, 'unite_calcul_snapshot' => 'pack', 'montant_ligne' => $o['montant'] / 2]);
        }
        $m = $cible === 'site' ? $o['montant'] / 7 : $o['montant'] / 3;
        $ajuste = $o['k'] % 3 === 0;
        CommissionEnveloppePart::create(['enveloppe_id' => $enveloppe->id, 'beneficiaire_type' => $cible, 'beneficiaire_id' => $beneficiaire,
            'taux_repartition_snapshot' => 100, 'montant_brut' => $m, 'montant_net' => $m, 'montant_actuel' => $ajuste ? $m - 500.25 : null,
            'montant_verse' => match ($o['statut']) {
                'paye' => $ajuste ? $m - 500.25 : $m, 'partiel' => round($m / 3, 2), default => 0
            },
            'statut' => $o['statut'], 'validated_at' => $o['statut'] === 'creee' ? null : $o['date']]);
    }

    /** @return Collection<string, array<string, mixed>> lignes de l'écran par bénéficiaire */
    private function ecran(string $ecran, array $params = []): Collection
    {
        $props = $this->actingAs($this->user)->get(self::URL.$ecran.'?'.http_build_query($params))->assertOk()->viewData('page')['props'];

        return collect($props['beneficiaires'])->keyBy('beneficiaire_id');
    }

    /**
     * Référence : les parts chargées avec leurs relations, comme l'écran le faisait avant le lot 9.
     *
     * @return Collection<string, Collection<int, CommissionEnveloppePart>>
     */
    private function partsChargees(string $type): Collection
    {
        return CommissionEnveloppePart::with(['enveloppe.source.site', 'enveloppe.source.vehicule', 'enveloppe.processus', 'enveloppe.lignes.categorieSnapshot'])
            ->where('beneficiaire_type', $type)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $this->org->id)->when($type === 'site', fn ($e) => $e->where('cible_type', 'site')))
            ->get()
            ->groupBy('beneficiaire_id');
    }

    /** @return array{brut: float, a_payer: float, verse: float} */
    private static function sommesHorsCreee(Collection $parts): array
    {
        $payables = $parts->filter(fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::CREEE);

        return [
            'brut' => (float) $payables->sum('montant_brut'),
            'a_payer' => (float) $payables->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer),
            'verse' => (float) $payables->sum('montant_verse'),
        ];
    }

    public function test_proprietaires_identiques_au_calcul_par_les_modeles(): void
    {
        $this->jeu();
        $lignes = $this->ecran('proprietaires');
        $reference = $this->partsChargees('proprietaire');
        $nomsSites = Site::where('organization_id', $this->org->id)->pluck('nom', 'id');

        $this->assertEqualsCanonicalizing($reference->keys()->all(), $lignes->keys()->all());
        foreach ($reference as $proprioId => $parts) {
            $ligne = $lignes[$proprioId];
            $sommes = self::sommesHorsCreee($parts);
            $buckets = CommissionKpiBuckets::calculer($parts);
            $this->assertSame($sommes['brut'], $ligne['total_brut_cumule']);
            $this->assertSame($sommes['verse'], $ligne['total_verse']);
            $this->assertSame(max(0.0, $sommes['a_payer'] - $ligne['total_frais']), $ligne['total_net_cumule']);
            foreach (['total_genere', 'en_attente_periode', 'payable'] as $cle) {
                $this->assertSame($buckets[$cle], $ligne[$cle], $cle);
            }
            $this->assertSame($parts->pluck('enveloppe_id')->unique()->count(), $ligne['nb_commandes']);
            $this->assertSame(CommissionProcessusFilter::labelsPresents($parts), $ligne['processus_labels']);
            $agence = $parts->map(fn (CommissionEnveloppePart $p) => $nomsSites->get((string) $p->enveloppe?->siteResponsableId()))->filter()->unique()->sort()->implode(', ');
            $this->assertSame($agence ?: null, $ligne['agence']);

            $vehicules = $parts->filter(fn (CommissionEnveloppePart $p) => $p->enveloppe?->source?->vehicule !== null)
                ->groupBy(fn (CommissionEnveloppePart $p) => (string) $p->enveloppe->source->vehicule->id)
                ->map(fn (Collection $vp) => [
                    'id' => $vp->first()->enveloppe->source->vehicule->id,
                    'nom' => $vp->first()->enveloppe->source->vehicule->nom_vehicule,
                    'immatriculation' => $vp->first()->enveloppe->source->vehicule->immatriculation,
                    'sites' => $vp->pluck('enveloppe.source.site')->filter()->unique('id')->sortBy('nom')->map(fn (Site $s) => ['id' => $s->id, 'nom' => $s->nom])->values()->all(),
                    'commission_generee' => CommissionKpiBuckets::calculer($vp)['total_genere'],
                ])->sortBy('nom')->values()->all();
            $this->assertSame($vehicules, $ligne['vehicules']);
        }
    }

    public function test_sites_identiques_au_calcul_par_les_modeles(): void
    {
        $this->jeu();
        $lignes = $this->ecran('sites');
        $reference = $this->partsChargees('site');

        $this->assertEqualsCanonicalizing($reference->keys()->all(), $lignes->keys()->all());
        foreach ($reference as $siteId => $parts) {
            $ligne = $lignes[$siteId];
            $sommes = self::sommesHorsCreee($parts);
            $buckets = CommissionKpiBuckets::calculer($parts);
            $this->assertSame($sommes['brut'], $ligne['total_brut_cumule']);
            $this->assertSame($sommes['verse'], $ligne['total_verse']);
            foreach (['total_genere', 'en_attente_periode', 'payable'] as $cle) {
                $this->assertSame($buckets[$cle], $ligne[$cle], $cle);
            }
            $this->assertSame($parts->pluck('enveloppe_id')->unique()->count(), $ligne['nb_commandes']);
            $this->assertSame(CommissionProcessusFilter::labelsPresents($parts), $ligne['processus_labels']);
            $categories = $parts->flatMap(fn (CommissionEnveloppePart $p) => $p->enveloppe->lignes->pluck('categorieSnapshot.nom'))->filter()->unique()->sort()->values()->all();
            $this->assertSame($categories, $ligne['categories']);
        }
    }

    public function test_les_filtres_gardent_le_meme_perimetre(): void
    {
        $this->jeu();
        $siteB = Site::where('nom', 'Agence B')->firstOrFail();

        // Une seule période (mois propriétaire / quinzaine site) et un processus.
        $this->assertNotEmpty($this->ecran('proprietaires', ['periode' => ['2026-11-M']]));
        $this->assertSame(
            $this->partsChargees('proprietaire')->map->count()->sum(),
            $this->ecran('proprietaires')->sum('nb_commandes'),
        );
        $parSite = $this->ecran('sites', ['site_ids' => [$siteB->id]]);
        $this->assertSame([$siteB->id], $parSite->keys()->all());
        $this->assertSame(['Distribution client'], $this->ecran('sites', ['processus' => ['distribution_client']])->pluck('processus_labels')->flatten()->unique()->values()->all());
    }

    public function test_aucune_part_chargee_en_modele_et_requetes_independantes_du_volume(): void
    {
        $this->jeu();
        $this->ecran('proprietaires');
        $this->ecran('sites');

        $mesure = function (string $ecran): array {
            $modeles = 0;
            Event::listen('eloquent.retrieved: '.CommissionEnveloppePart::class, function () use (&$modeles) {
                $modeles++;
            });
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ecran($ecran);
            $requetes = count(DB::getQueryLog());
            DB::disableQueryLog();
            Event::forget('eloquent.retrieved: '.CommissionEnveloppePart::class);

            return [$requetes, $modeles];
        };

        [$petitP, $modelesP] = $mesure('proprietaires');
        [$petitS, $modelesS] = $mesure('sites');
        $this->assertSame(0, $modelesP);
        $this->assertSame(0, $modelesS);

        $this->jeu(3, ' bis');
        $this->assertSame($petitP, $mesure('proprietaires')[0]);
        $this->assertSame($petitS, $mesure('sites')[0]);
    }

    public function test_exports_livreurs_portent_le_nom_de_l_ecran(): void
    {
        $this->jeu();

        $csv = $this->actingAs($this->user)->get(self::URL.'vente/export/excel?periode[]=toutes')->assertOk();
        $this->assertStringContainsString('commissions-livreurs-2026-11-20.csv', (string) $csv->headers->get('content-disposition'));

        $pdf = $this->actingAs($this->user)->get(self::URL.'vente/export/pdf?periode[]=toutes')->assertOk();
        $this->assertStringContainsString('commissions-livreurs-2026-11-20.pdf', (string) $pdf->headers->get('content-disposition'));

        $html = view('pdf.commissions.index', ['title' => 'Commissions des livreurs', 'org' => null, 'periode_label' => 'Toutes périodes', 'filters' => [],
            'sites' => [], 'show_validation_columns' => true, 'printed_by' => '—', 'generated_at' => now()])->render();
        $this->assertStringContainsString('Commissions des livreurs', $html);
        $this->assertStringNotContainsString('livreur vente', (string) file_get_contents(app_path('Http/Controllers/Comptabilite/CommissionVenteController.php')));
    }
}
