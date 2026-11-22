<?php

namespace Tests\Feature\Performance;

use App\Enums\ModePaiement;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasListePagineeDeuxAgences;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Liste Factures paginée côté serveur (lot 7) : totaux des cartes identiques au calcul historique
 * par les accesseurs de FactureVente, sur toutes les factures filtrées ; tri sur l'ensemble des
 * résultats ; périmètre d'agence respecté ; sommes dues toutes périodes jamais perdues.
 */
class ListeFacturesPagineeTest extends TestCase
{
    use HasAdminSetup, HasListePagineeDeuxAgences, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparerDeuxAgences();
    }

    /**
     * @param  array{statut?: string, total?: float, encaisse?: list<float>, rembourse?: float, site?: Site, org?: Organization, quantite?: int, date?: CarbonInterface, vehicule?: ?Vehicule, client?: ?Client}  $o
     */
    private function facture(array $o = []): FactureVente
    {
        $this->sequence++;
        $org = $o['org'] ?? $this->org;
        $site = $o['site'] ?? $this->agenceA;
        $date = $o['date'] ?? now()->startOfMonth()->addMinutes(10 + $this->sequence);
        $total = $o['total'] ?? 1000;
        $reference = sprintf('LFP-%05d', $this->sequence);

        $commande = CommandeVente::withoutEvents(fn () => CommandeVente::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'vehicule_id' => ($o['vehicule'] ?? null)?->id,
            'client_id' => ($o['client'] ?? null)?->id,
            'statut' => StatutCommandeVente::LIVREE,
            'nature_operation' => 'vente_standard',
            'total_commande' => $total,
            'reference' => $reference,
            'numero' => $this->sequence,
        ]));

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

        $facture = FactureVente::withoutEvents(fn () => FactureVente::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'commande_vente_id' => $commande->id,
            'reference' => $reference,
            'montant_brut' => $total,
            'montant_net' => $total,
            'statut_facture' => $o['statut'] ?? StatutFactureVente::IMPAYEE->value,
            'montant_rembourse' => $o['rembourse'] ?? 0,
        ]));
        $facture->forceFill(['created_at' => $date])->saveQuietly();

        foreach ($o['encaisse'] ?? [] as $montant) {
            EncaissementVente::withoutEvents(fn () => EncaissementVente::create([
                'facture_vente_id' => $facture->id,
                'montant' => $montant,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => ModePaiement::ESPECES->value,
            ]));
        }

        return $facture;
    }

    /** Jeu varié du mois : tous les statuts, centimes, remboursement, trop-perçu, deux agences, autre organisation. */
    private function jeuDeFactures(): void
    {
        foreach (range(1, 46) as $i) {
            $total = 1000 + $i * 137.35;
            $statut = match ($i % 5) {
                0 => 'creee',
                1 => 'payee',
                2 => 'partiel',
                3 => 'impayee',
                default => 'annulee',
            };
            $this->facture([
                'statut' => $statut,
                'total' => $total,
                'encaisse' => match ($statut) {
                    'payee' => [round($total / 2, 2), round($total - round($total / 2, 2), 2)],
                    'partiel' => [round($total / 3, 2)],
                    'annulee' => [100.10],
                    default => [],
                },
                'rembourse' => $i % 9 === 0 ? 50.25 : 0,
                'site' => $i % 3 === 0 ? $this->agenceB : $this->agenceA,
                'quantite' => ($i * 7) % 40,
            ]);
        }
        // Trop-perçu (reste négatif ramené à zéro) et autre organisation.
        $this->facture(['statut' => 'payee', 'total' => 500, 'encaisse' => [800]]);
        $autre = Organization::factory()->create();
        $siteAutre = Site::create(['organization_id' => $autre->id, 'nom' => 'Ailleurs', 'type' => 'depot', 'localisation' => 'Labé']);
        $this->facture(['org' => $autre, 'site' => $siteAutre, 'total' => 77777]);
    }

    /**
     * Calcul historique (avant pagination) : accesseurs des modèles sur la liste complète.
     *
     * @param  Collection<int, FactureVente>  $factures
     */
    private function totauxHistoriques(Collection $factures): array
    {
        $factures->load('encaissements');
        $parStatut = fn (string $s) => $factures->filter(fn (FactureVente $f) => $f->statut_facture->value === $s);
        $actives = $factures->reject(fn (FactureVente $f) => $f->isAnnulee());

        return [
            'total' => round((float) $actives->sum('montant_net'), 2),
            'nb_total' => $actives->count(),
            'total_a_encaisser' => round((float) $factures
                ->reject(fn (FactureVente $f) => $f->isAnnulee() || $f->isPayee())
                ->sum(fn (FactureVente $f) => $f->montant_restant), 2),
            'nb_impayees' => $parStatut('impayee')->count(),
            'montant_impayees' => round((float) $parStatut('impayee')->sum(fn (FactureVente $f) => $f->montant_restant), 2),
            'nb_partielles' => $parStatut('partiel')->count(),
            'montant_partielles' => round((float) $parStatut('partiel')->sum(fn (FactureVente $f) => $f->montant_restant), 2),
            'nb_payees' => $parStatut('payee')->count(),
            'montant_payees' => round((float) $parStatut('payee')->sum('montant_net'), 2),
        ];
    }

    private function arrondis(array $totaux): array
    {
        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totaux);
    }

    /** @return array<string, mixed> */
    private function page(User $user, array $params = []): array
    {
        return $this->props($user, 'factures.index', $params);
    }

    /** @return Collection<int, FactureVente> */
    private function facturesDeLOrganisation(): Collection
    {
        return FactureVente::where('organization_id', $this->org->id)->get();
    }

    public function test_les_totaux_portent_sur_toutes_les_factures_filtrees_et_egalent_le_calcul_historique(): void
    {
        $this->jeuDeFactures();

        $page1 = $this->page($this->user);
        $page3 = $this->page($this->user, ['page' => 3]);

        $this->assertSame($this->totauxHistoriques($this->facturesDeLOrganisation()), $this->arrondis($page1['totaux']));
        $this->assertSame($page1['totaux'], $page3['totaux']);
        $this->assertSame(47, $page1['pagination']['total']);
        $this->assertSame(3, $page1['pagination']['derniere_page']);
        $this->assertCount(20, $page1['factures']);
        $this->assertCount(7, $page3['factures']);
    }

    public function test_chaque_ligne_garde_ses_montants_et_ses_encaissements(): void
    {
        $this->jeuDeFactures();

        $lignes = [];
        foreach (range(1, 3) as $p) {
            $lignes = [...$lignes, ...$this->page($this->user, ['page' => $p])['factures']];
        }

        $this->assertCount(47, $lignes);
        $attendues = $this->facturesDeLOrganisation()->load('encaissements')->keyBy('id');
        foreach ($lignes as $ligne) {
            $f = $attendues[$ligne['id']];
            $this->assertEqualsWithDelta($f->montant_restant, $ligne['montant_restant'], 0.001);
            $this->assertEqualsWithDelta($f->montant_encaisse, $ligne['montant_encaisse'], 0.001);
            $this->assertCount($f->encaissements->count(), $ligne['encaissements']);
        }
    }

    public function test_les_totaux_suivent_le_filtre_de_statut(): void
    {
        $this->jeuDeFactures();
        $partielles = $this->facturesDeLOrganisation()->filter(fn (FactureVente $f) => $f->statut_facture === StatutFactureVente::PARTIEL);

        $props = $this->page($this->user, ['statut' => 'partiel']);

        $this->assertSame($this->totauxHistoriques($partielles), $this->arrondis($props['totaux']));
        $this->assertSame($partielles->count(), $props['pagination']['total']);
    }

    public function test_un_utilisateur_limite_a_son_agence_ne_voit_ni_les_factures_ni_les_montants_des_autres(): void
    {
        $this->jeuDeFactures();
        $this->facture(['site' => $this->agenceB, 'date' => now()->startOfMonth()->subDays(3), 'total' => 9999]);
        $visibles = $this->facturesDeLOrganisation()->filter(fn (FactureVente $f) => $f->site_id === $this->agenceA->id);
        $vendeur = $this->vendeurAgenceA();

        $props = $this->page($vendeur);
        $references = [];
        foreach (range(1, $props['pagination']['derniere_page']) as $p) {
            $references = [...$references, ...array_column($this->page($vendeur, ['page' => $p])['factures'], 'reference')];
        }

        $this->assertSame($this->totauxHistoriques($visibles), $this->arrondis($props['totaux']));
        $this->assertEqualsCanonicalizing($visibles->pluck('reference')->all(), $references);
        // Le suivi toutes périodes ne remonte pas non plus la facture ancienne de l'agence B.
        $this->assertEqualsWithDelta($props['totaux']['total_a_encaisser'], $props['suivi_toutes_periodes']['total_a_encaisser'], 0.001);
    }

    public function test_une_facture_ancienne_impayee_reste_dans_le_suivi_toutes_periodes(): void
    {
        $this->facture(['statut' => 'impayee', 'total' => 4000]);
        $this->facture(['statut' => 'partiel', 'total' => 3000, 'encaisse' => [1000]]);
        $this->facture(['statut' => 'payee', 'total' => 2000, 'encaisse' => [2000]]);
        $this->facture(['statut' => 'impayee', 'total' => 7000, 'date' => now()->startOfMonth()->subMonths(2)]);
        $this->facture(['statut' => 'partiel', 'total' => 5000, 'encaisse' => [500], 'date' => now()->startOfMonth()->subDays(3)]);
        $this->facture(['statut' => 'annulee', 'total' => 8000, 'date' => now()->startOfMonth()->subDays(3)]);

        $props = $this->page($this->user);

        // Cartes : le mois en cours seulement (période par défaut inchangée).
        $this->assertSame('ce_mois', $props['periode']);
        $this->assertEqualsWithDelta(6000, $props['totaux']['total_a_encaisser'], 0.001);
        $this->assertSame(1, $props['totaux']['nb_impayees']);
        // Suivi : toutes les sommes encore dues, quel que soit le mois.
        $this->assertEqualsWithDelta(6000 + 7000 + 4500, $props['suivi_toutes_periodes']['total_a_encaisser'], 0.001);
        $this->assertSame(2, $props['suivi_toutes_periodes']['nb_impayees']);
        $this->assertEqualsWithDelta(11000, $props['suivi_toutes_periodes']['montant_impayees'], 0.001);

        // Période « Tout » : les cartes portent déjà sur tout, pas de second calcul.
        $tout = $this->page($this->user, ['periode' => 'tout']);
        $this->assertNull($tout['suivi_toutes_periodes']);
        $this->assertEqualsWithDelta(17500, $tout['totaux']['total_a_encaisser'], 0.001);
    }

    /** @return list<array{string, string}> */
    public static function tris(): array
    {
        return [
            ['montant_net', 'asc'], ['montant_net', 'desc'], ['montant_restant', 'desc'], ['montant_encaisse', 'asc'],
            ['quantite_totale', 'asc'], ['site_nom', 'desc'], ['statut_label', 'asc'], ['reference', 'desc'],
            ['created_at', 'asc'],
        ];
    }

    /** @dataProvider tris */
    public function test_le_tri_porte_sur_toutes_les_pages(string $tri, string $sens): void
    {
        $this->jeuDeFactures();

        $lignes = [];
        foreach (range(1, 3) as $p) {
            $props = $this->page($this->user, ['tri' => $tri, 'sens' => $sens, 'page' => $p]);
            $lignes = [...$lignes, ...$props['factures']];
        }
        $this->assertCount(47, $lignes);
        $this->assertSame($tri, $props['pagination']['tri']);

        $valeurs = array_map(fn (array $f) => match ($tri) {
            // Les factures sont créées de plus en plus récentes : l'ordre des dates est celui des références.
            'created_at' => $f['reference'],
            default => $f[$tri],
        }, $lignes);
        $attendu = $valeurs;
        $sens === 'asc' ? sort($attendu) : rsort($attendu);
        $this->assertSame($attendu, $valeurs);
    }

    public function test_la_recherche_mobile_porte_sur_toutes_les_factures(): void
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'nom_vehicule' => 'Camion Kaloum']);
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'nom' => 'SYLLA', 'prenom' => 'Aminata']);
        $this->facture(['vehicule' => $vehicule]);
        $this->facture(['client' => $client]);
        $this->facture(['site' => $this->agenceB]);
        foreach (range(1, 30) as $_) {
            $this->facture();
        }
        $cible = $this->facture();

        $this->assertSame([$cible->reference], array_column($this->page($this->user, ['recherche' => $cible->reference])['factures'], 'reference'));
        $this->assertCount(1, $this->page($this->user, ['recherche' => 'kaloum'])['factures']);
        $this->assertCount(1, $this->page($this->user, ['recherche' => 'Aminata'])['factures']);
        $this->assertSame(1, $this->page($this->user, ['recherche' => 'Agence B'])['pagination']['total']);
    }

    public function test_le_nombre_de_requetes_ne_depend_pas_du_nombre_de_factures(): void
    {
        $compter = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page($this->user);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->jeuDeFactures();
        $this->page($this->user); // premier appel : caches des rôles, permissions et paramètres
        $avec47 = $compter();
        foreach (range(1, 60) as $i) {
            $this->facture(['statut' => 'partiel', 'encaisse' => [100, 200], 'quantite' => $i]);
        }

        $this->assertSame($avec47, $compter());
    }

    public function test_un_tri_inconnu_est_refuse(): void
    {
        $this->actingAs($this->user)
            ->get(route('factures.index', ['tri' => 'montant_net; drop table factures_ventes']))
            ->assertSessionHasErrors('tri');
    }
}
