<?php

namespace Tests\Feature\Performance;

use App\Enums\ModePaiement;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Liste Ventes paginée côté serveur (lot 3) : totaux sur toutes les ventes filtrées, identiques
 * au calcul historique par les accesseurs des modèles ; tri sur l'ensemble des résultats ;
 * périmètre d'agence respecté.
 */
class ListeVentesPagineeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $agenceA;

    private Site $agenceB;

    private string $varianteId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read']);
        $this->agenceA = Site::where('organization_id', $this->org->id)->firstOrFail();
        $this->agenceB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->varianteId = $this->makeProduitAvecVariante($this->org)->variantes()->first()->id;
    }

    /**
     * @param  array{statut?: StatutCommandeVente, total?: float, facture?: ?string, encaisse?: list<float>, rembourse?: float, site?: Site, nature?: NatureOperation, org?: Organization, quantite?: int, grossiste?: bool}  $o
     */
    private function vente(array $o = []): CommandeVente
    {
        $this->sequence++;
        $org = $o['org'] ?? $this->org;
        $site = $o['site'] ?? $this->agenceA;
        $commande = CommandeVente::withoutEvents(fn () => CommandeVente::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'statut' => $o['statut'] ?? StatutCommandeVente::LIVREE,
            'nature_operation' => ($o['nature'] ?? NatureOperation::VENTE_STANDARD)->value,
            'nature_client' => ($o['grossiste'] ?? false) ? 'grossiste' : null,
            'mode_remise_grossiste' => ($o['grossiste'] ?? false) ? 'livraison' : null,
            'total_commande' => $o['total'] ?? 1000,
            'reference' => sprintf('LVP-%05d', $this->sequence),
            'numero' => $this->sequence,
        ]));
        $commande->forceFill(['created_at' => now()->subMinutes($this->sequence)])->saveQuietly();

        if (isset($o['quantite'])) {
            CommandeVenteLigne::withoutEvents(fn () => CommandeVenteLigne::create([
                'commande_vente_id' => $commande->id,
                'variante_id' => $this->varianteId,
                'quantite_demandee' => $o['quantite'] + 5,
                'quantite_chargee' => $o['quantite'],
                'libelle_snapshot' => 'Pack',
                'prix_usine_snapshot' => 1,
                'prix_vente_snapshot' => 1,
                'total_ligne' => 1,
            ]));
        }

        if (array_key_exists('facture', $o) && $o['facture'] !== null) {
            $facture = FactureVente::withoutEvents(fn () => FactureVente::create([
                'organization_id' => $org->id,
                'site_id' => $site->id,
                'commande_vente_id' => $commande->id,
                'reference' => $commande->reference,
                'montant_brut' => $o['total'] ?? 1000,
                'montant_net' => $o['total'] ?? 1000,
                'statut_facture' => $o['facture'],
                'montant_rembourse' => $o['rembourse'] ?? 0,
            ]));
            foreach ($o['encaisse'] ?? [] as $montant) {
                EncaissementVente::withoutEvents(fn () => EncaissementVente::create([
                    'facture_vente_id' => $facture->id,
                    'montant' => $montant,
                    'date_encaissement' => now()->toDateString(),
                    'mode_paiement' => ModePaiement::ESPECES->value,
                ]));
            }
        }

        return $commande;
    }

    /** Jeu varié : statuts, factures payées/partielles/impayées/annulées, remboursement, centimes, deux agences. */
    private function jeuDeVentes(): void
    {
        $statuts = [StatutCommandeVente::LIVREE, StatutCommandeVente::CLOTUREE, StatutCommandeVente::ANNULEE, StatutCommandeVente::ANNULEE_ERREUR_SAISIE, StatutCommandeVente::FACTURATION, StatutCommandeVente::BROUILLON];
        foreach (range(1, 46) as $i) {
            $total = 1000 + $i * 137.35;
            $facture = match ($i % 5) {
                0 => null,
                1 => 'payee',
                2 => 'partiel',
                3 => 'impayee',
                default => 'annulee',
            };
            $encaisse = match ($facture) {
                'payee' => [round($total / 2, 2), round($total - round($total / 2, 2), 2)],
                'partiel' => [round($total / 3, 2)],
                'annulee' => [100.10],
                default => [],
            };
            $this->vente([
                'statut' => $statuts[$i % count($statuts)],
                'total' => $total,
                'facture' => $facture,
                'encaisse' => $encaisse,
                'rembourse' => $i % 9 === 0 ? 50.25 : 0,
                'site' => $i % 3 === 0 ? $this->agenceB : $this->agenceA,
                'quantite' => ($i * 7) % 40,
                'grossiste' => $i % 4 === 0,
            ]);
        }
        // Trop-perçu (reste négatif ramené à zéro) et hors périmètre : autre nature, autre organisation.
        $this->vente(['total' => 500, 'facture' => 'payee', 'encaisse' => [800]]);
        $this->vente(['nature' => NatureOperation::DISTRIBUTION_CLIENT, 'total' => 99999, 'facture' => 'impayee']);
        $autre = Organization::factory()->create();
        $siteAutre = Site::create(['organization_id' => $autre->id, 'nom' => 'Ailleurs', 'type' => 'depot', 'localisation' => 'Labé']);
        $this->vente(['org' => $autre, 'site' => $siteAutre, 'total' => 77777, 'facture' => 'impayee']);
    }

    /**
     * Calcul historique (avant pagination) : accesseurs des modèles sur la liste complète.
     *
     * @param  Collection<int, CommandeVente>  $commandes
     */
    private function totauxHistoriques(Collection $commandes): array
    {
        $commandes->load('facture.encaissements');
        $nonAnnulees = $commandes->filter(fn ($c) => ! $c->isAnnulee() && ! $c->isAnnuleeErreurSaisie());
        $cloturees = $commandes->filter(fn ($c) => $c->isCloturee());
        $facturees = $commandes->filter(fn ($c) => $c->facture && ! $c->facture->isAnnulee());

        return [
            'total_montant' => round((float) $nonAnnulees->sum('total_commande'), 2),
            'nb_total' => $nonAnnulees->count(),
            'total_a_encaisser' => round((float) $facturees->sum(fn ($c) => (float) $c->facture->montant_restant), 2),
            'deja_paye' => round((float) $facturees->sum(fn ($c) => (float) $c->facture->montant_encaisse), 2),
            'nb_cloturees' => $cloturees->count(),
            'montant_cloturees' => round((float) $cloturees->sum('total_commande'), 2),
        ];
    }

    private function arrondis(array $totaux): array
    {
        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totaux);
    }

    /** Utilisateur de l'organisation qui consulte toutes les agences (rôle admin_entreprise). */
    private function admin(): User
    {
        $this->assertTrue($this->user->voitToutesLesAgences());

        return $this->user;
    }

    /** Vendeur rattaché à la seule agence A. */
    private function vendeurAgenceA(): User
    {
        Role::firstOrCreate(['name' => 'vendeur_test', 'guard_name' => 'web']);
        $vendeur = User::factory()->create(['organization_id' => $this->org->id]);
        $vendeur->assignRole('vendeur_test');
        $vendeur->givePermissionTo('ventes.read');
        $vendeur->sites()->attach($this->agenceA->id, ['role' => 'employe', 'is_default' => true]);

        return $vendeur;
    }

    /** @return array<string, mixed> */
    private function page(User $user, array $params = []): array
    {
        return $this->actingAs($user)->get(route('ventes.index', $params))->assertOk()->viewData('page')['props'];
    }

    public function test_les_totaux_portent_sur_toutes_les_ventes_filtrees_et_egalent_le_calcul_historique(): void
    {
        $this->jeuDeVentes();
        $admin = $this->admin();
        $visibles = CommandeVente::where('organization_id', $this->org->id)->where('nature_operation', NatureOperation::VENTE_STANDARD->value)->get();

        $page1 = $this->page($admin);
        $page3 = $this->page($admin, ['page' => 3]);

        $this->assertSame($this->totauxHistoriques($visibles), $this->arrondis($page1['totaux']));
        $this->assertSame($page1['totaux'], $page3['totaux']);
        $this->assertSame(47, $page1['pagination']['total']);
        $this->assertSame(3, $page1['pagination']['derniere_page']);
        $this->assertCount(20, $page1['commandes']);
        $this->assertCount(7, $page3['commandes']);
    }

    public function test_les_totaux_suivent_les_filtres_de_statut(): void
    {
        $this->jeuDeVentes();
        $admin = $this->admin();
        $statuts = [StatutCommandeVente::CLOTUREE->value, StatutCommandeVente::ANNULEE->value];
        $visibles = CommandeVente::where('organization_id', $this->org->id)->where('nature_operation', NatureOperation::VENTE_STANDARD->value)->whereIn('statut', $statuts)->get();

        $props = $this->page($admin, ['statuts' => $statuts]);

        $this->assertSame($this->totauxHistoriques($visibles), $this->arrondis($props['totaux']));
        $this->assertSame($visibles->count(), $props['pagination']['total']);
    }

    public function test_un_utilisateur_limite_a_son_agence_ne_voit_ni_les_ventes_ni_les_montants_des_autres(): void
    {
        $this->jeuDeVentes();
        $visibles = CommandeVente::where('organization_id', $this->org->id)->where('nature_operation', NatureOperation::VENTE_STANDARD->value)->where('site_id', $this->agenceA->id)->get();
        $vendeur = $this->vendeurAgenceA();
        $this->assertFalse($vendeur->voitToutesLesAgences());

        $references = [];
        $props = $this->page($vendeur);
        foreach (range(1, $props['pagination']['derniere_page']) as $p) {
            $references = [...$references, ...array_column($this->page($vendeur, ['page' => $p])['commandes'], 'reference')];
        }

        $this->assertSame($this->totauxHistoriques($visibles), $this->arrondis($props['totaux']));
        $this->assertEqualsCanonicalizing($visibles->pluck('reference')->all(), $references);
    }

    /** @return list<array{string, string}> */
    public static function tris(): array
    {
        return [
            ['total_commande', 'asc'], ['total_commande', 'desc'], ['facture_montant_restant', 'desc'],
            ['quantite_totale', 'asc'], ['site_nom', 'asc'], ['processus_label', 'desc'],
            ['reference', 'desc'], ['statut', 'asc'], ['created_at', 'asc'],
        ];
    }

    /** @dataProvider tris */
    public function test_le_tri_porte_sur_toutes_les_pages(string $tri, string $sens): void
    {
        $this->jeuDeVentes();
        $admin = $this->admin();

        $lignes = [];
        foreach (range(1, 3) as $p) {
            $props = $this->page($admin, ['tri' => $tri, 'sens' => $sens, 'page' => $p]);
            $lignes = [...$lignes, ...$props['commandes']];
        }
        $this->assertCount(47, $lignes);
        $this->assertSame($tri, $props['pagination']['tri']);

        $valeurs = array_map(fn (array $c) => match ($tri) {
            'facture_montant_restant' => (float) ($c['facture_montant_restant'] ?? 0),
            'created_at' => $c['reference'],
            default => $c[$tri],
        }, $lignes);
        // created_at : les ventes sont créées de plus en plus anciennes ; l'ordre croissant des dates
        // est donc l'ordre décroissant des références.
        $attendu = $valeurs;
        if ($sens === 'asc' && $tri !== 'created_at') {
            sort($attendu);
        } else {
            rsort($attendu);
        }
        $this->assertSame($attendu, $valeurs);
    }

    public function test_distribution_et_precommandes_gardent_leur_liste_complete(): void
    {
        foreach (range(1, 23) as $_) {
            $this->vente(['nature' => NatureOperation::DISTRIBUTION_CLIENT]);
        }

        $this->actingAs($this->admin())
            ->get(route('distributions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('commandes', 23)->where('pagination', null));
    }

    public function test_l_export_lu_par_paquets_contient_toutes_les_ventes_une_seule_fois(): void
    {
        config(['excel.exports.chunk_size' => 5]);
        Permission::firstOrCreate(['name' => 'ventes.exporter', 'guard_name' => 'web']);
        $this->user->givePermissionTo('ventes.exporter');
        $memeDate = now()->subDay()->startOfMinute();
        $references = [];
        foreach (range(1, 12) as $_) {
            $vente = $this->vente(['facture' => 'partiel', 'encaisse' => [100.25, 200.50]]);
            $vente->forceFill(['created_at' => $memeDate])->saveQuietly();
            $references[] = $vente->reference;
        }

        $reponse = $this->actingAs($this->admin())
            ->get(route('ventes.export', ['format' => 'csv', 'columns' => ['reference', 'deja_paye']]))
            ->assertOk();
        $lignes = array_map('str_getcsv', array_slice(explode("\n", trim(file_get_contents($reponse->getFile()->getPathname()))), 1));

        $attendues = CommandeVente::whereIn('reference', $references)->orderByDesc('id')->pluck('reference')->all();
        $this->assertSame($attendues, array_column($lignes, 0));
        $this->assertSame(array_fill(0, 12, '300.75'), array_column($lignes, 1));
    }

    public function test_un_tri_inconnu_est_refuse(): void
    {
        $this->actingAs($this->admin())
            ->get(route('ventes.index', ['tri' => 'mot_de_passe']))
            ->assertSessionHasErrors('tri');
    }
}
