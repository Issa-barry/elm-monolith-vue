<?php

namespace Tests\Feature\Settings;

use App\Enums\CommissionRegleStatut;
use App\Models\Categorie;
use App\Models\CommissionBaremeBrouillon;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Organization;
use App\Models\TypeVehicule;
use App\Services\Commission\CommissionBaremeHistoriqueService;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * COMM-021 — historique des modifications de barème affiché dans Paramètres → Commissions,
 * dérivé des versions de commission_regles (ajout, modification, retrait).
 */
class CommissionBaremeHistoriqueTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Categorie $bouteille;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['parametres.read', 'parametres.update']);
        $this->bouteille = Categorie::create(['organization_id' => $this->org->id, 'nom' => "Bouteille d'eau", 'statut' => 'actif']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enregistrer(array $lignes, string $processus = CommissionProcessus::CODE_VENTE): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions/configuration', ['processus_code' => $processus, 'lignes' => $lignes])
            ->assertSessionHasNoErrors();
    }

    private function ligne(int $livreur, int $proprietaire = 950, array $exceptions = []): array
    {
        return [
            'categorie_id' => $this->bouteille->id,
            'beneficiaires' => [CommissionCibleType::CODE_PROPRIETAIRE, CommissionCibleType::CODE_EQUIPE_LIVRAISON],
            'montants_standard' => [
                CommissionCibleType::CODE_PROPRIETAIRE => $proprietaire,
                CommissionCibleType::CODE_EQUIPE_LIVRAISON => $livreur,
            ],
            'exceptions' => $exceptions,
        ];
    }

    private function historique(string $processus = CommissionProcessus::CODE_VENTE): array
    {
        $id = CommissionProcessus::where('organization_id', $this->org->id)->where('code', $processus)->value('id');

        return CommissionBaremeHistoriqueService::pour($this->org->id, $id);
    }

    /** @test */
    public function un_premier_enregistrement_apparait_comme_un_ajout_par_beneficiaire_avec_son_auteur(): void
    {
        Carbon::setTestNow('2026-08-29 18:48:19');
        $this->enregistrer([$this->ligne(950)]);

        $historique = $this->historique();

        $this->assertCount(1, $historique);
        $this->assertSame($this->user->fresh()->name, $historique[0]['auteur']);
        $this->assertFalse($historique[0]['publication_brouillon']);
        $this->assertSame(['Propriétaire', 'Livreur'], array_column($historique[0]['changements'], 'cible'));
        $livreur = $historique[0]['changements'][1];
        $this->assertSame(CommissionBaremeHistoriqueService::TYPE_AJOUT, $livreur['type']);
        $this->assertSame("Bouteille d'eau", $livreur['categorie']);
        $this->assertNull($livreur['ancien_montant']);
        $this->assertSame(950, $livreur['nouveau_montant']);
        $this->assertSame('2026-08-29', $livreur['en_vigueur_le']);
    }

    /** @test */
    public function une_baisse_du_bareme_livreur_apparait_comme_une_modification_ancien_vers_nouveau(): void
    {
        Carbon::setTestNow('2026-08-29 18:48:19');
        $this->enregistrer([$this->ligne(950)]);

        Carbon::setTestNow('2026-09-23 15:30:18');
        $this->enregistrer([$this->ligne(800)]);

        $historique = $this->historique();

        $this->assertCount(2, $historique);
        $dernier = $historique[0];
        $this->assertSame(Carbon::parse('2026-09-23 15:30:18')->toIso8601String(), $dernier['date']);
        // Le montant propriétaire inchangé ne crée aucune version, donc aucune ligne d'historique.
        $this->assertCount(1, $dernier['changements']);
        $this->assertSame([
            'type' => CommissionBaremeHistoriqueService::TYPE_MODIFICATION,
            'categorie' => "Bouteille d'eau",
            'type_vehicule' => null,
            'cible_code' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible' => 'Livreur',
            'ancien_montant' => 950,
            'nouveau_montant' => 800,
            'ancien_consultant' => null,
            'nouveau_consultant' => null,
            'en_vigueur_le' => '2026-09-23',
        ], $dernier['changements'][0]);
    }

    /** @test */
    public function un_retrait_de_categorie_est_trace_avec_son_auteur_et_son_premier_jour_sans_bareme(): void
    {
        $sachet = Categorie::create(['organization_id' => $this->org->id, 'nom' => "Sachet d'eau", 'statut' => 'actif']);
        $ligneSachet = array_merge($this->ligne(300, 100), ['categorie_id' => $sachet->id]);

        Carbon::setTestNow('2026-08-29 18:48:19');
        $this->enregistrer([$this->ligne(950), $ligneSachet]);

        Carbon::setTestNow('2026-09-13 10:00:00');
        $this->enregistrer([$this->ligne(950)]);

        $retrait = $this->historique()[0];

        $this->assertSame($this->user->fresh()->name, $retrait['auteur']);
        $this->assertCount(2, $retrait['changements']);
        foreach ($retrait['changements'] as $changement) {
            $this->assertSame(CommissionBaremeHistoriqueService::TYPE_RETRAIT, $changement['type']);
            $this->assertSame("Sachet d'eau", $changement['categorie']);
            $this->assertNull($changement['nouveau_montant']);
            $this->assertSame('2026-09-13', $changement['en_vigueur_le']);
        }
        $this->assertSame(300, $retrait['changements'][1]['ancien_montant']);
        $this->assertDatabaseHas('commission_regles', ['scope_id' => $sachet->id, 'closed_by' => $this->user->id]);
        $this->assertDatabaseMissing('commission_regles', ['scope_id' => $this->bouteille->id, 'closed_by' => $this->user->id]);
    }

    /** @test */
    public function une_exception_par_type_de_vehicule_porte_le_nom_du_type(): void
    {
        $tricycle = TypeVehicule::where('organization_id', $this->org->id)->where('nom', 'Tricycle')->firstOrFail();

        Carbon::setTestNow('2026-08-29 18:48:19');
        $this->enregistrer([$this->ligne(300, 950, [[
            'type_vehicule_id' => $tricycle->id,
            'montants' => [
                CommissionCibleType::CODE_PROPRIETAIRE => 950,
                CommissionCibleType::CODE_EQUIPE_LIVRAISON => 245,
            ],
        ]])]);

        $changements = collect($this->historique()[0]['changements']);
        $exception = $changements->first(fn (array $c) => $c['type_vehicule'] === 'Tricycle' && $c['cible'] === 'Livreur');

        $this->assertNotNull($exception);
        $this->assertSame(245, $exception['nouveau_montant']);
        // Barème général listé avant ses exceptions.
        $this->assertNull($changements->first()['type_vehicule']);
    }

    /** @test */
    public function un_retrait_historique_sans_auteur_rejoint_lenregistrement_dont_il_fait_partie(): void
    {
        $processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);
        $sachet = Categorie::create(['organization_id' => $this->org->id, 'nom' => "Sachet d'eau", 'statut' => 'actif']);

        Carbon::setTestNow('2026-08-29 18:48:19');
        $ancienne = $this->regle($processus, $sachet, 300, '2026-08-29');

        Carbon::setTestNow('2026-09-13 15:10:19');
        // Avant closed_by : un retrait ne gardait que la date de clôture, jamais son auteur.
        $ancienne->update(['statut' => CommissionRegleStatut::REMPLACEE->value, 'effective_to' => '2026-09-12']);
        Carbon::setTestNow('2026-09-13 15:10:20');
        $this->regle($processus, $this->bouteille, 950, '2026-09-13');

        $historique = $this->historique();

        $this->assertCount(2, $historique);
        $this->assertSame($this->user->fresh()->name, $historique[0]['auteur']);
        $this->assertEqualsCanonicalizing(
            [CommissionBaremeHistoriqueService::TYPE_RETRAIT, CommissionBaremeHistoriqueService::TYPE_AJOUT],
            array_column($historique[0]['changements'], 'type'),
        );
    }

    /** @test */
    public function un_retrait_historique_isole_reste_sans_auteur(): void
    {
        $processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);

        Carbon::setTestNow('2026-08-29 18:48:19');
        $regle = $this->regle($processus, $this->bouteille, 300, '2026-08-29');
        Carbon::setTestNow('2026-09-13 09:00:00');
        $regle->update(['statut' => CommissionRegleStatut::REMPLACEE->value, 'effective_to' => '2026-09-12']);

        $retrait = $this->historique()[0];

        $this->assertNull($retrait['auteur']);
        $this->assertSame(CommissionBaremeHistoriqueService::TYPE_RETRAIT, $retrait['changements'][0]['type']);
    }

    /** @test */
    public function signale_un_enregistrement_issu_de_la_publication_dun_brouillon(): void
    {
        Carbon::setTestNow('2026-09-25 11:00:00');
        $this->enregistrer([$this->ligne(950)]);
        $processus = CommissionProcessus::where('organization_id', $this->org->id)->where('code', CommissionProcessus::CODE_VENTE)->firstOrFail();
        CommissionBaremeBrouillon::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'lignes' => [],
            'regles_signature' => str_repeat('0', 64),
            'statut' => CommissionBaremeBrouillon::STATUT_PUBLIE,
            'publie_par' => $this->user->id,
            'publie_le' => Carbon::parse('2026-09-25 11:00:01'),
        ]);

        $this->assertTrue($this->historique()[0]['publication_brouillon']);
    }

    /** @test */
    public function lhistorique_est_isole_par_processus_et_par_organisation(): void
    {
        Carbon::setTestNow('2026-09-01 19:40:49');
        $this->enregistrer([$this->ligne(200)], CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);

        $autreOrg = Organization::factory()->create();
        $autreCategorie = Categorie::create(['organization_id' => $autreOrg->id, 'nom' => 'Autre', 'statut' => 'actif']);
        $this->regle(CommissionProcessusDefaults::resoudreOuCreer($autreOrg->id, CommissionProcessus::CODE_VENTE), $autreCategorie, 999, '2026-09-01', $autreOrg->id);

        $this->actingAs($this->user)
            ->get('/settings/commissions?processus='.CommissionProcessus::CODE_VENTE)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('historique', 0));

        $this->actingAs($this->user)
            ->get('/settings/commissions?processus='.CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('historique', 1)
                ->where('historique.0.changements.1.nouveau_montant', 200)
            );
    }

    private function regle(CommissionProcessus $processus, Categorie $categorie, int $montant, string $du, ?string $orgId = null): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $orgId ?? $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livreur — '.$categorie->nom,
            'scope_type' => 'categorie',
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => 'a_repartir',
            'unite_calcul' => 'par_unite_vendue',
            'montant' => $montant,
            'effective_from' => $du,
            'statut' => CommissionRegleStatut::ACTIVE->value,
            'created_by' => $orgId ? null : $this->user->id,
        ]);
    }
}
