<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\EvenementComptable;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentResolver;
use App\Services\Tresorerie\CaisseAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Règle du 23/09/2026 : on n'encaisse pas en espèces sans caisse dédiée active. Un agent qui
 * n'en a pas voyait jusqu'ici son encaissement retomber sur le compte partagé 571000 — l'argent
 * existait, mais personne n'en était responsable. Le refus est prononcé côté serveur (le bouton
 * désactivé de PaymentCard n'est qu'un confort) et ne concerne que les espèces : Mobile Money,
 * virement et chèque ne touchent jamais la caisse de l'agent.
 */
class EncaissementEspecesCaisseObligatoireTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['factures.encaisser', 'ventes.read', 'ventes.update']);
        $this->site = $this->user->sites()->firstOrFail();
    }

    private function facture(?Site $site = null, float $montant = 500_000): FactureVente
    {
        $site ??= $this->site;

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => $montant,
        ]);

        return FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'site_id' => $site->id,
            'montant_net' => $montant,
        ]);
    }

    /** @param  array<string, mixed>  $surcharge */
    private function encaisser(FactureVente $facture, array $surcharge = [], ?User $auteur = null)
    {
        return $this->actingAs($auteur ?? $this->user)->post(route('encaissements.store', $facture), array_merge([
            'montant' => 100_000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ], $surcharge));
    }

    private function autreSite(): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Kindia',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
    }

    // ── Refus ────────────────────────────────────────────────────────────────

    public function test_especes_sans_caisse_dediee_sont_refusees_et_rien_n_est_enregistre(): void
    {
        $facture = $this->facture();

        $this->encaisser($facture)->assertSessionHasErrors(['mode_paiement' => CaisseAgentResolver::MESSAGE_SANS_CAISSE]);

        $this->assertSame(0, EncaissementVente::count());
        $this->assertSame(0, PieceComptable::where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)->count());
        $this->assertSame('impayee', $facture->fresh()->statut_facture->value);
    }

    public function test_l_admin_de_l_organisation_n_a_pas_de_passe_droit(): void
    {
        $this->assertTrue($this->user->hasRole('admin_entreprise'));

        $this->encaisser($this->facture())->assertSessionHasErrors('mode_paiement');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_le_super_admin_sans_caisse_est_refuse_lui_aussi(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->encaisser($this->facture(), [], $superAdmin)->assertSessionHasErrors('mode_paiement');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_une_caisse_en_brouillon_ne_suffit_pas(): void
    {
        app(CaisseAgentService::class)->creer($this->org->id, $this->site->id, $this->user->id);

        $this->encaisser($this->facture())->assertSessionHasErrors('mode_paiement');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_une_caisse_desactivee_ne_suffit_pas(): void
    {
        $caisse = $this->creerCaisseActive($this->site->id, $this->user->id);
        $caisse->update(['actif' => false]);

        $this->encaisser($this->facture())->assertSessionHasErrors('mode_paiement');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_la_caisse_d_un_autre_site_ne_suffit_pas(): void
    {
        $autreSite = $this->autreSite();
        $this->user->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => false]);
        $this->creerCaisseActive($autreSite->id, $this->user->id);

        $this->encaisser($this->facture($this->site))->assertSessionHasErrors('mode_paiement');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_la_caisse_d_un_autre_agent_ne_suffit_pas(): void
    {
        $collegue = $this->creerAgent($this->site);
        $this->creerCaisseActive($this->site->id, $collegue->id);

        $this->encaisser($this->facture())->assertSessionHasErrors('mode_paiement');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_date_anterieure_a_la_mise_en_service_de_la_caisse_est_refusee(): void
    {
        $this->creerCaisseActive($this->site->id, $this->user->id);

        $this->encaisser($this->facture(), ['date_encaissement' => now()->subDays(3)->toDateString()])
            ->assertSessionHasErrors(['date_encaissement' => CaisseAgentResolver::MESSAGE_AVANT_MISE_EN_SERVICE]);

        $this->assertSame(0, EncaissementVente::count());
    }

    // ── Acceptation ──────────────────────────────────────────────────────────

    public function test_especes_avec_caisse_active_sont_acceptees_et_alimentent_cette_caisse(): void
    {
        $caisse = $this->creerCaisseActive($this->site->id, $this->user->id);
        $facture = $this->facture();

        $this->encaisser($facture)->assertSessionHasNoErrors();

        $encaissement = EncaissementVente::firstOrFail();
        $this->assertSame($this->user->id, $encaissement->created_by);

        $piece = PieceComptable::where('source_type', $encaissement->getMorphClass())
            ->where('source_id', $encaissement->id)
            ->firstOrFail();
        $numeros = $piece->lignes()->with('compte')->get()->pluck('compte.numero')->all();

        $this->assertContains($caisse->compte->numero, $numeros, 'l\'argent arrive sur le sous-compte de la caisse');
        $this->assertNotContains('571000', $numeros, 'jamais sur le compte partagé de l\'agence');
    }

    public function test_espece_du_jour_de_la_mise_en_service_est_acceptee(): void
    {
        $this->creerCaisseActive($this->site->id, $this->user->id);

        $this->encaisser($this->facture(), ['date_encaissement' => Carbon::now()->toDateString()])->assertSessionHasNoErrors();

        $this->assertSame(1, EncaissementVente::count());
    }

    public function test_les_autres_modes_restent_possibles_sans_caisse(): void
    {
        $this->encaisser($this->facture(), [
            'mode_paiement' => 'mobile_money',
            'operateur_mobile_money' => 'orange_money',
            'reference_paiement' => 'OM-123',
        ])->assertSessionHasNoErrors();

        $this->encaisser($this->facture(), [
            'mode_paiement' => 'virement',
            'reference_paiement' => 'VIR-456',
        ])->assertSessionHasNoErrors();

        $this->encaisser($this->facture(), ['mode_paiement' => 'cheque'])->assertSessionHasNoErrors();

        $this->assertSame(3, EncaissementVente::count());
    }

    // ── Indicateur exposé à l'interface ──────────────────────────────────────

    public function test_la_fiche_vente_indique_si_les_especes_sont_disponibles(): void
    {
        $facture = $this->facture();
        $commande = $facture->commande;

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.peut_encaisser_especes', false));

        $this->creerCaisseActive($this->site->id, $this->user->id);

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.peut_encaisser_especes', true));
    }

    public function test_la_fiche_vente_ignore_la_caisse_d_un_autre_site(): void
    {
        $facture = $this->facture($this->site);
        $autreSite = $this->autreSite();
        $this->user->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => false]);
        $this->creerCaisseActive($autreSite->id, $this->user->id);

        $this->actingAs($this->user)
            ->get(route('ventes.show', $facture->commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.peut_encaisser_especes', false));
    }

    public function test_la_liste_des_ventes_indique_le_droit_especes_par_ligne(): void
    {
        $facture = $this->facture();
        $reference = $facture->commande->reference;

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['numero_commande' => $reference]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 1)
                ->where('commandes.0.peut_encaisser_especes', false));

        $this->creerCaisseActive($this->site->id, $this->user->id);

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['numero_commande' => $reference]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 1)
                ->where('commandes.0.peut_encaisser_especes', true));
    }

    public function test_la_liste_des_factures_indique_le_droit_especes_par_ligne(): void
    {
        $facture = $this->facture();

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all', 'reference' => $facture->reference]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('factures', 1)
                ->where('factures.0.peut_encaisser_especes', false));

        $this->creerCaisseActive($this->site->id, $this->user->id);

        $this->actingAs($this->user)
            ->get(route('factures.index', ['periode' => 'all', 'reference' => $facture->reference]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('factures', 1)
                ->where('factures.0.peut_encaisser_especes', true));
    }

    public function test_la_caisse_d_un_collegue_n_ouvre_pas_le_droit_especes(): void
    {
        $collegue = $this->creerAgent($this->site);
        $this->creerCaisseActive($this->site->id, $collegue->id);
        $facture = $this->facture();

        $this->actingAs($this->user)
            ->get(route('ventes.show', $facture->commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.peut_encaisser_especes', false));
    }

    // ── Résolveur (règle partagée) ───────────────────────────────────────────

    public function test_le_resolveur_liste_les_sites_ou_l_agent_a_une_caisse_active(): void
    {
        $resolveur = app(CaisseAgentResolver::class);
        $this->assertSame([], $resolveur->sitesAvecCaisseActive($this->org->id, $this->user->id));

        $caisse = $this->creerCaisseActive($this->site->id, $this->user->id);
        $this->assertSame([$this->site->id], $resolveur->sitesAvecCaisseActive($this->org->id, $this->user->id));

        $caisse->update(['actif' => false]);
        $this->assertSame([], $resolveur->sitesAvecCaisseActive($this->org->id, $this->user->id));

    }
}
