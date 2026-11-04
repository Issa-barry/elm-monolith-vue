<?php

namespace Tests\Feature\Api\Client;

use App\Enums\PrestataireType;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Depense;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Scénario de référence de l'ADR 0054 : un même compte est propriétaire du véhicule A, livreur sur
 * le véhicule B (propriétaire tiers), parrain du véhicule C (propriétaire tiers) et consultant.
 *
 * Parts d'octobre 2026 du compte :
 *  - A, vente V1 (20/10, P2) : propriétaire 10 000 (4 000 versés) ; consultant 1 500.
 *  - A, vente V4 (12/10, P1) : propriétaire 7 000 ANNULÉE (hors montants, visible avec son statut).
 *  - B, vente V2 (05/10, P1) : livreur 6 000.
 *  - B, transfert T1 (18/10, P2) : livreur 3 000.
 *  - B, vente V5 (25/10, P2) : livreur 2 000 encore « Créée » (en attente de validation).
 *  - C, vente V3 (21/10, P2) : parrain 2 500.
 * Dépenses validées : avance livreur 1 000 (10/10), frais du véhicule A 500 (22/10), dépense parrain 300 (22/10).
 * Parts d'autres bénéficiaires sur les mêmes opérations (jamais visibles) : propriétaires tiers de B
 * et C, livreur de C, autre livreur de V1.
 */
class GainsMultiActivitesTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private Organization $org;

    private User $compte;

    private Proprietaire $proprietaire;

    private Livreur $livreur;

    private Parrain $parrain;

    private Prestataire $consultant;

    private Vehicule $vehiculeA;

    private Vehicule $vehiculeB;

    private Vehicule $vehiculeC;

    private EquipeLivreur $membreB;

    private CommandeVente $v2;

    private CommandeVente $v3;

    private TransfertLogistique $t1;

    private const TELEPHONE_TIERS = '+224629000111';

    private const EMAIL_TIERS = 'mamadou.bah@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-03 10:00:00');

        $this->org = Organization::factory()->create();

        $this->compte = $this->makeProprietaireUser($this->org);
        $this->compte->assignRole('livreur');
        $this->proprietaire = $this->compte->proprietaire;
        $this->livreur = (new Livreur)->forceFill([
            'organization_id' => $this->org->id,
            'user_id' => $this->compte->id,
            'personne_id' => $this->proprietaire->personne_id,
            'nom_complet' => 'Hassane DIALLO',
            'is_active' => true,
        ]);
        $this->livreur->save();
        $this->parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $this->parrain->forceFill(['user_id' => $this->compte->id])->save();
        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'Diallo', 'prenom' => 'Hassane']);
        $this->consultant = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => PrestataireType::CONSULTANT->value, 'is_active' => true]);
        $this->consultant->forceFill(['user_id' => $this->compte->id])->save();

        $tiersB = $this->makeProprietaireUser($this->org)->proprietaire;
        $tiersC = $this->makeProprietaireUser($this->org, ['telephone' => self::TELEPHONE_TIERS])->proprietaire;
        $tiersC->personne->update(['prenom' => 'Mamadou', 'nom' => 'BAH', 'email' => self::EMAIL_TIERS]);

        $this->vehiculeA = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $this->proprietaire->id, 'nom_vehicule' => 'A-possede', 'immatriculation' => 'AA-001']);
        $this->vehiculeB = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $tiersB->id, 'nom_vehicule' => 'B-livraison', 'immatriculation' => 'BB-002']);
        $this->vehiculeC = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $tiersC->id, 'nom_vehicule' => 'C-parraine', 'immatriculation' => 'CC-003', 'parrain_id' => $this->parrain->id]);

        $equipeB = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $this->vehiculeB->id, 'nom' => 'Equipe B', 'is_active' => true, 'taux_commission_proprietaire' => 0]);
        $this->membreB = EquipeLivreur::create(['equipe_id' => $equipeB->id, 'livreur_id' => $this->livreur->id, 'role' => 'chauffeur', 'ordre' => 1]);
        $autreLivreurC = $this->makeLivreurUser($this->org)->livreur;
        $equipeC = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $this->vehiculeC->id, 'nom' => 'Equipe C', 'is_active' => true, 'taux_commission_proprietaire' => 0]);
        EquipeLivreur::create(['equipe_id' => $equipeC->id, 'livreur_id' => $autreLivreurC->id, 'role' => 'chauffeur', 'ordre' => 1]);

        $v1 = $this->vente($this->vehiculeA, '2026-10-20');
        $this->part($v1, CommissionCibleType::CODE_PROPRIETAIRE, '2026-10-20', 'proprietaire', $this->proprietaire->id, 10000, 4000);
        $this->part($v1, CommissionCibleType::CODE_CONSULTANT, '2026-10-20', 'prestataire', $this->consultant->id, 1500);
        $this->part($v1, CommissionCibleType::CODE_EQUIPE_LIVRAISON, '2026-10-20', 'livreur', $this->makeLivreurUser($this->org)->livreur->id, 4000);

        $v4 = $this->vente($this->vehiculeA, '2026-10-12');
        $this->part($v4, CommissionCibleType::CODE_PROPRIETAIRE, '2026-10-12', 'proprietaire', $this->proprietaire->id, 7000, 0, StatutCommission::ANNULEE);

        $this->v2 = $this->vente($this->vehiculeB, '2026-10-05', 'VTE-B-0510');
        $this->part($this->v2, CommissionCibleType::CODE_EQUIPE_LIVRAISON, '2026-10-05', 'livreur', $this->livreur->id, 6000);
        $this->part($this->v2, CommissionCibleType::CODE_PROPRIETAIRE, '2026-10-05', 'proprietaire', $tiersB->id, 9999);

        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot', 'localisation' => 'X']);
        $this->t1 = TransfertLogistique::create(['organization_id' => $this->org->id, 'reference' => 'TRF-B-1810', 'site_source_id' => $site->id, 'site_destination_id' => $site->id, 'vehicule_id' => $this->vehiculeB->id, 'statut' => 'cloture', 'created_by' => $this->compte->id]);
        $this->part($this->t1, CommissionCibleType::CODE_EQUIPE_LIVRAISON, '2026-10-18', 'livreur', $this->livreur->id, 3000, 0, null, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);

        $v5 = $this->vente($this->vehiculeB, '2026-10-25');
        $this->part($v5, CommissionCibleType::CODE_EQUIPE_LIVRAISON, '2026-10-25', 'livreur', $this->livreur->id, 2000, 0, StatutCommission::CREEE);

        $this->v3 = $this->vente($this->vehiculeC, '2026-10-21', 'VTE-C-2110');
        $equipeV3 = $this->enveloppe($this->v3, CommissionCibleType::CODE_EQUIPE_LIVRAISON, '2026-10-21');
        $this->partSur($equipeV3, 'parrain', $this->parrain->id, 2500);
        $this->partSur($equipeV3, 'livreur', $autreLivreurC->id, 5000);
        $this->part($this->v3, CommissionCibleType::CODE_PROPRIETAIRE, '2026-10-21', 'proprietaire', $tiersC->id, 8000);

        $this->depense('livreur', $this->livreur->id, 1000, '2026-10-10');
        $this->depense('vehicule', $this->vehiculeA->id, 500, '2026-10-22');
        $this->depense('parrain', $this->parrain->id, 300, '2026-10-22');
    }

    // ── Construction ────────────────────────────────────────────────────────

    private function vente(Vehicule $vehicule, string $date, ?string $reference = null): CommandeVente
    {
        return CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'validated_at' => $date,
            ...($reference ? ['reference' => $reference] : []),
        ]);
    }

    private function enveloppe(CommandeVente|TransfertLogistique $source, string $cible, string $earnedAt, string $processus = CommissionProcessus::CODE_VENTE): CommissionEnveloppe
    {
        return CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => $source::class,
            'source_id' => $source->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, $processus)->id,
            'cible_type' => $cible,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 1,
            'montant_verse' => 0,
            'earned_at' => $earnedAt,
            'statut' => StatutCommission::IMPAYE,
        ]);
    }

    private function part(CommandeVente|TransfertLogistique $source, string $cible, string $earnedAt, string $type, string $id, float $montant, float $verse = 0, ?StatutCommission $statut = null, string $processus = CommissionProcessus::CODE_VENTE): void
    {
        $this->partSur($this->enveloppe($source, $cible, $earnedAt, $processus), $type, $id, $montant, $verse, $statut);
    }

    private function partSur(CommissionEnveloppe $enveloppe, string $type, string $id, float $montant, float $verse = 0, ?StatutCommission $statut = null): void
    {
        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => $verse,
            'statut' => $statut ?? ($verse <= 0 ? StatutCommission::IMPAYE : ($verse >= $montant ? StatutCommission::PAYE : StatutCommission::PARTIEL)),
        ]);
    }

    private function depense(string $type, string $id, float $montant, string $date): void
    {
        Depense::factory()->create([
            'organization_id' => $this->org->id,
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $id,
            'montant' => $montant,
            'statut' => StatutDepense::VALIDE,
            'date_depense' => $date,
        ]);
    }

    private function appel(string $route, array $query = [], ?User $user = null): array
    {
        Sanctum::actingAs($user ?? $this->compte, ['*']);

        return $this->getJson(route($route, $query))->assertOk()->json();
    }

    private function parActivite(array $reponse): array
    {
        return collect($reponse['par_activite'] ?? $reponse['resume']['par_activite'])->keyBy('activite')->all();
    }

    // ── Répartition et totaux ───────────────────────────────────────────────

    public function test_le_dashboard_compte_les_quatre_activites_une_seule_fois(): void
    {
        $octobre = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10]);
        $activites = $this->parActivite($octobre);

        $this->assertSame(['proprietaire', 'livreur', 'parrain', 'consultant'], array_keys($activites));
        $this->assertSame(['Propriétaire', 'Livreur', 'Parrain', 'Consultant'], array_column($activites, 'libelle'));

        $this->assertEquals(['total_earned' => 10000, 'total_paid' => 4000, 'en_attente_validation' => 0, 'frais_depenses_total' => 500, 'balance' => 5500, 'operations_count' => 1], $this->montants($activites['proprietaire']));
        $this->assertEquals(['total_earned' => 11000, 'total_paid' => 0, 'en_attente_validation' => 2000, 'frais_depenses_total' => 1000, 'balance' => 8000, 'operations_count' => 3], $this->montants($activites['livreur']));
        $this->assertEquals(['total_earned' => 2500, 'total_paid' => 0, 'en_attente_validation' => 0, 'frais_depenses_total' => 300, 'balance' => 2200, 'operations_count' => 1], $this->montants($activites['parrain']));
        $this->assertEquals(['total_earned' => 1500, 'total_paid' => 0, 'en_attente_validation' => 0, 'frais_depenses_total' => 0, 'balance' => 1500, 'operations_count' => 1], $this->montants($activites['consultant']));

        // Total = somme des parts réellement attribuées au compte, sans l'annulée ni les parts des tiers.
        $this->assertEquals(['total_earned' => 25000, 'total_paid' => 4000, 'en_attente_validation' => 2000, 'frais_depenses_total' => 1800, 'balance' => 17200, 'operations_count' => 6], $octobre['summary']);
        $this->assertEquals(1800, $octobre['depenses_total']);
        foreach (['total_earned', 'total_paid', 'balance', 'operations_count'] as $champ) {
            $this->assertEquals($octobre['summary'][$champ], array_sum(array_column($activites, $champ)), $champ);
        }
    }

    public function test_p1_et_p2_redonnent_le_mois(): void
    {
        $mois = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10]);
        $p1 = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10, 'quinzaine' => 'P1']);
        $p2 = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10, 'quinzaine' => 'P2']);

        $this->assertEquals(6000, $p1['summary']['total_earned']);
        $this->assertEquals(5000, $p1['summary']['balance']);
        $this->assertEquals(19000, $p2['summary']['total_earned']);
        $this->assertEquals(12200, $p2['summary']['balance']);

        foreach (['total_earned', 'total_paid', 'en_attente_validation', 'frais_depenses_total', 'balance', 'operations_count'] as $champ) {
            $this->assertEquals($mois['summary'][$champ], $p1['summary'][$champ] + $p2['summary'][$champ], $champ);
        }
        foreach (['proprietaire', 'livreur', 'parrain', 'consultant'] as $activite) {
            $this->assertEquals(
                $this->parActivite($mois)[$activite]['total_earned'],
                $this->parActivite($p1)[$activite]['total_earned'] + $this->parActivite($p2)[$activite]['total_earned'],
                $activite,
            );
        }
    }

    public function test_une_activite_sans_gain_sur_la_periode_reste_listee_a_zero(): void
    {
        $p1 = $this->parActivite($this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10, 'quinzaine' => 'P1']));

        $this->assertEquals(0, $p1['parrain']['total_earned']);
        $this->assertEquals(0, $p1['consultant']['total_earned']);
        $this->assertEquals(0, $p1['proprietaire']['total_earned']);
    }

    // ── Véhicules ───────────────────────────────────────────────────────────

    public function test_les_trois_vehicules_apparaissent_avec_leur_lien_et_leurs_gains(): void
    {
        $octobre = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10]);

        $vehicules = collect($octobre['vehicules'])->keyBy('nom_vehicule');
        $this->assertSame(['A-possede', 'B-livraison', 'C-parraine'], $vehicules->keys()->sort()->values()->all());
        $this->assertSame(['proprietaire'], $vehicules['A-possede']['liens']);
        $this->assertSame(['livreur'], $vehicules['B-livraison']['liens']);
        $this->assertSame(['parrain'], $vehicules['C-parraine']['liens']);

        $lignes = collect($octobre['par_vehicule'])->keyBy('nom_vehicule');
        $this->assertEquals(11500, $lignes['A-possede']['total_earned']);
        $this->assertEquals([['activite' => 'proprietaire', 'total_earned' => 10000], ['activite' => 'consultant', 'total_earned' => 1500]], $lignes['A-possede']['par_activite']);
        $this->assertEquals(11000, $lignes['B-livraison']['total_earned']);
        $this->assertEquals(2500, $lignes['C-parraine']['total_earned']);
        $this->assertSame(['parrain'], $lignes['C-parraine']['liens']);
        $this->assertEquals($octobre['summary']['total_earned'], $lignes->sum('total_earned'));
    }

    public function test_le_filtre_vehicule_accepte_un_vehicule_parraine(): void
    {
        $c = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10, 'vehicule_id' => $this->vehiculeC->id]);

        $this->assertEquals(2500, $c['summary']['total_earned']);
        $this->assertEquals(['parrain'], array_keys(array_filter($this->parActivite($c), fn ($l) => $l['total_earned'] > 0)));
        // Une dépense non rattachée à un véhicule ne se déduit pas d'un véhicule filtré.
        $this->assertEquals(0, $c['summary']['frais_depenses_total']);
    }

    public function test_un_vehicule_quitte_sort_de_mes_vehicules_mais_garde_ses_commissions(): void
    {
        $this->membreB->delete();

        $liste = collect($this->appel('client.vehicules.rattaches'))->pluck('nom');
        $this->assertNotContains('B-livraison', $liste);

        $octobre = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10]);
        $b = collect($octobre['par_vehicule'])->firstWhere('nom_vehicule', 'B-livraison');
        $this->assertEquals(11000, $b['total_earned']);
        $this->assertSame([], $b['liens']);
        $this->assertSame([], collect($octobre['vehicules'])->firstWhere('nom_vehicule', 'B-livraison')['liens']);
        $this->assertEquals(11000, $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10, 'vehicule_id' => $this->vehiculeB->id])['summary']['total_earned']);
    }

    public function test_mes_vehicules_liste_les_trois_rattachements_et_restreint_le_vehicule_parraine(): void
    {
        Sanctum::actingAs($this->compte, ['*']);
        $reponse = $this->getJson(route('client.vehicules.rattaches'))->assertOk();
        $vehicules = collect($reponse->json())->keyBy('nom');

        $this->assertSame(['A-possede', 'B-livraison', 'C-parraine'], $vehicules->keys()->sort()->values()->all());
        $this->assertSame(['proprietaire'], $vehicules['A-possede']['liens']);
        $this->assertSame(['livreur'], $vehicules['B-livraison']['liens']);
        $this->assertNotEmpty($vehicules['B-livraison']['equipe']);

        $c = $vehicules['C-parraine'];
        $this->assertSame(['parrain'], $c['liens']);
        $this->assertSame('parrain', $c['role']);
        $this->assertSame('CC-003', $c['immatriculation']);
        $this->assertSame('Mamadou BAH', $c['proprietaire']['nom_complet']);
        $this->assertNull($c['proprietaire']['telephone']);
        $this->assertNull($c['proprietaire']['id']);
        $this->assertSame([], $c['equipe']);
        $this->assertSame([], $c['capacites']);
        $this->assertTrue($c['lecture_restreinte']);
        $this->assertFalse($vehicules['A-possede']['lecture_restreinte']);
        $this->assertNull($c['en_livraison']);
        $this->assertIsArray($c['remuneration_parrain']);

        // Aucune coordonnée du propriétaire tiers, ni l'équipe du véhicule parrainé, nulle part.
        $contenu = $reponse->getContent();
        $this->assertStringNotContainsString(self::TELEPHONE_TIERS, $contenu);
        $this->assertStringNotContainsString('629000111', $contenu);
        $this->assertStringNotContainsString(self::EMAIL_TIERS, $contenu);
    }

    public function test_la_liste_de_l_application_mobile_ne_change_pas(): void
    {
        $vehicules = collect($this->appel('client.vehicules.mine'));

        $this->assertSame(['A-possede', 'B-livraison'], $vehicules->pluck('nom')->sort()->values()->all());
        $this->assertArrayNotHasKey('liens', $vehicules->first());
        $this->assertArrayNotHasKey('remuneration_parrain', $vehicules->first());
    }

    public function test_le_parrain_ne_voit_ni_les_ventes_ni_les_depenses_du_vehicule_parraine(): void
    {
        $this->assertSame(0, $this->appel('client.activite.mine', ['vehicule_id' => $this->vehiculeC->id])['meta']['total']);
        $this->assertNotContains('VTE-C-2110', collect($this->appel('client.activite.mine')['data'])->pluck('reference'));

        Sanctum::actingAs($this->compte, ['*']);
        $this->getJson(route('client.vehicules.frais', ['vehiculeId' => $this->vehiculeC->id]))->assertNotFound();
    }

    // ── Détail des commissions ──────────────────────────────────────────────

    public function test_chaque_ligne_porte_son_origine_et_seules_les_parts_du_compte_sont_listees(): void
    {
        $octobre = $this->appel('client.commissions.mine', ['annee' => 2026, 'mois' => 10]);
        $lignes = collect($octobre['data']);

        $this->assertSame(7, $octobre['meta']['total']);
        $this->assertEqualsCanonicalizing([10000, 7000, 1500, 6000, 3000, 2000, 2500], $lignes->pluck('montant_a_payer')->all());
        foreach ([9999, 8000, 5000, 4000] as $partDeTiers) {
            $this->assertNotContains($partDeTiers, $lignes->pluck('montant_a_payer')->all());
        }

        $parMontant = $lignes->keyBy(fn ($l) => (int) $l['montant_a_payer']);
        $this->assertSame('parrain', $parMontant[2500]['activite']);
        $this->assertSame('Parrain', $parMontant[2500]['activite_label']);
        $this->assertSame('VTE-C-2110', $parMontant[2500]['reference']);
        $this->assertSame('CC-003', $parMontant[2500]['vehicule']['immatriculation']);
        $this->assertSame('vente', $parMontant[2500]['operation']);
        $this->assertSame('proprietaire', $parMontant[10000]['activite']);
        $this->assertSame('livreur', $parMontant[6000]['activite']);
        $this->assertSame('livreur', $parMontant[3000]['activite']);
        $this->assertSame('logistique', $parMontant[3000]['operation']);
        $this->assertSame('TRF-B-1810', $parMontant[3000]['reference']);
        $this->assertSame('creee', $parMontant[2000]['statut']);
        $this->assertSame('consultant', $parMontant[1500]['activite']);
        $this->assertSame($this->consultant->fresh(['personne', 'entrepriseTierce'])->nom_complet, $parMontant[1500]['beneficiaire_libelle']);
        $this->assertNull($parMontant[2500]['beneficiaire_libelle']);

        // L'annulée reste listée avec son statut mais sort du résumé, identique au dashboard.
        $this->assertSame('annulee', $parMontant[7000]['statut']);
        $this->assertSame('Annulée', $parMontant[7000]['statut_label']);
        $dashboard = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10]);
        $this->assertEquals($dashboard['summary'], $octobre['resume']['summary']);
        $this->assertEquals($dashboard['par_activite'], $octobre['resume']['par_activite']);

        $this->assertSame(['2026-10-25', '2026-10-21', '2026-10-20', '2026-10-20', '2026-10-18', '2026-10-12', '2026-10-05'], $lignes->pluck('date')->all());
    }

    public function test_le_filtre_activite_restreint_les_lignes_et_le_resume(): void
    {
        $parrain = $this->appel('client.commissions.mine', ['annee' => 2026, 'mois' => 10, 'activite' => 'parrain']);

        $this->assertSame(['parrain'], collect($parrain['data'])->pluck('activite')->unique()->values()->all());
        $this->assertSame(1, $parrain['meta']['total']);
        $this->assertEquals(2500, $parrain['resume']['summary']['total_earned']);
        $this->assertEquals(2200, $parrain['resume']['summary']['balance']);
        $this->assertSame(['parrain'], array_keys($this->parActivite($parrain)));

        $livreur = $this->appel('client.commissions.mine', ['annee' => 2026, 'mois' => 10, 'activite' => 'livreur', 'quinzaine' => 'P2']);
        $this->assertSame(2, $livreur['meta']['total']);
        $this->assertEquals(5000, $livreur['resume']['summary']['total_earned']);
    }

    public function test_le_filtre_statut_annulee_liste_l_historique_sans_montant(): void
    {
        $annulees = $this->appel('client.commissions.mine', ['annee' => 2026, 'mois' => 10, 'statut' => 'annulee']);

        $this->assertSame(1, $annulees['meta']['total']);
        $this->assertEquals(0, $annulees['resume']['summary']['total_earned']);
    }

    public function test_la_recherche_porte_sur_la_reference_et_le_vehicule(): void
    {
        $parReference = $this->appel('client.commissions.mine', ['periode' => 'tout', 'recherche' => 'VTE-B-0510']);
        $this->assertSame([6000], collect($parReference['data'])->pluck('montant_a_payer')->map(fn ($m) => (int) $m)->all());
        $this->assertEquals(6000, $parReference['resume']['summary']['total_earned']);

        $parImmatriculation = $this->appel('client.commissions.mine', ['periode' => 'tout', 'recherche' => 'cc-003']);
        $this->assertSame(['parrain'], collect($parImmatriculation['data'])->pluck('activite')->all());

        $parNom = $this->appel('client.commissions.mine', ['periode' => 'tout', 'recherche' => 'B-livraison']);
        $this->assertSame(3, $parNom['meta']['total']);

        $rien = $this->appel('client.commissions.mine', ['periode' => 'tout', 'recherche' => 'inconnu-xyz']);
        $this->assertSame(0, $rien['meta']['total']);
        $this->assertEquals(0, $rien['resume']['summary']['total_earned']);
    }

    public function test_le_filtre_vehicule_et_la_pagination(): void
    {
        $c = $this->appel('client.commissions.mine', ['periode' => 'tout', 'vehicule_id' => $this->vehiculeC->id]);
        $this->assertSame(['parrain'], collect($c['data'])->pluck('activite')->all());

        $page1 = $this->appel('client.commissions.mine', ['annee' => 2026, 'mois' => 10, 'per_page' => 3]);
        $page3 = $this->appel('client.commissions.mine', ['annee' => 2026, 'mois' => 10, 'per_page' => 3, 'page' => 3]);
        $this->assertCount(3, $page1['data']);
        $this->assertCount(1, $page3['data']);
        $this->assertSame(3, $page1['meta']['last_page']);
        $this->assertEquals($page1['resume'], $page3['resume']);
    }

    public function test_les_filtres_de_periode_invalides_ou_contradictoires_sont_refuses(): void
    {
        Sanctum::actingAs($this->compte, ['*']);

        foreach ([
            ['activite' => 'site'],
            ['statut' => 'inconnu'],
            ['annee' => 2026, 'mois' => 10, 'periode' => 'ce_mois'],
            ['periode' => 'ce_mois', 'date_debut' => '2026-10-01'],
            ['quinzaine' => 'P1'],
            ['annee' => 2027],
        ] as $query) {
            $this->getJson(route('client.commissions.mine', $query))->assertUnprocessable();
        }
    }

    // ── Isolation et accès ──────────────────────────────────────────────────

    public function test_un_autre_compte_ne_voit_jamais_ces_parts(): void
    {
        $autre = $this->makeLivreurUser($this->org);

        $dashboard = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10], $autre);
        $this->assertEquals(0, $dashboard['summary']['total_earned']);
        $this->assertSame(0, $this->appel('client.commissions.mine', ['periode' => 'tout'], $autre)['meta']['total']);
        $this->assertSame(0, $this->appel('client.commissions.mine', ['periode' => 'tout', 'vehicule_id' => $this->vehiculeC->id], $autre)['meta']['total']);
    }

    public function test_aucun_rattachement_hors_de_l_organisation_du_compte(): void
    {
        $autreOrg = Organization::factory()->create();
        $parrainAilleurs = Parrain::factory()->create(['organization_id' => $autreOrg->id]);
        $parrainAilleurs->forceFill(['user_id' => $this->makeProprietaireUser($autreOrg)->id])->save();
        Vehicule::factory()->create(['organization_id' => $autreOrg->id, 'parrain_id' => $parrainAilleurs->id, 'nom_vehicule' => 'Z-ailleurs']);

        $this->assertNotContains('Z-ailleurs', collect($this->appel('client.vehicules.rattaches'))->pluck('nom'));
        $this->assertNotContains('Z-ailleurs', collect($this->appel('client.dashboard.mine', ['periode' => 'tout'])['vehicules'])->pluck('nom_vehicule'));
    }

    public function test_le_detail_des_commissions_exige_un_compte_authentifie(): void
    {
        $this->getJson(route('client.commissions.mine'))->assertUnauthorized();
    }

    public function test_me_expose_les_consultants_du_compte(): void
    {
        Sanctum::actingAs($this->compte, ['*']);

        $context = $this->getJson('/api/auth/me')->assertOk()->json('context');
        $this->assertSame([$this->consultant->id], $context['consultant_ids']);
        $this->assertSame($this->parrain->id, $context['parrain_id']);
    }

    // ── Écran Inertia (même moteur) ─────────────────────────────────────────

    public function test_l_espace_client_inertia_affiche_les_memes_montants_et_garde_l_annulee_au_releve(): void
    {
        $version = (new HandleInertiaRequests)->version(request());
        $inertia = fn (string $route, array $query) => $this->actingAs($this->compte)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get(route($route, $query))
            ->assertOk()
            ->json('props');

        $api = $this->appel('client.dashboard.mine', ['annee' => 2026, 'mois' => 10]);
        $dashboard = $inertia('client.dashboard', ['period' => 'custom', 'date_debut' => '2026-10-01', 'date_fin' => '2026-10-31']);
        $this->assertEquals($api['summary'], $dashboard['earnings']);
        $this->assertEquals($api['par_activite'], $dashboard['earnings_by_activite']);

        $gains = $inertia('client.earnings', ['date_debut' => '2026-10-01', 'date_fin' => '2026-10-31']);
        $annulee = collect($gains['statement'])->firstWhere('statut', 'annulee');
        $this->assertEquals(7000, $annulee['montant_a_payer']);
        $this->assertSame('proprietaire', $annulee['activite']);
        $this->assertSame('Logistique', collect($gains['statement'])->firstWhere('reference', 'TRF-B-1810')['source']);
    }

    private function montants(array $ligne): array
    {
        return array_intersect_key($ligne, array_flip(['total_earned', 'total_paid', 'en_attente_validation', 'frais_depenses_total', 'balance', 'operations_count']));
    }
}
