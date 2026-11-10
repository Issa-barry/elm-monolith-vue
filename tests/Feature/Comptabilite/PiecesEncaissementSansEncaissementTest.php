<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\StatutFactureVente;
use App\Enums\StatutPieceComptable;
use App\Models\CommandeVente;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Comptabilite\PiecesEncaissementSansEncaissementService as Service;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\TestCase;

/**
 * Régularisation de l'incident VTE-081126-134 (ADR 0062) : seules les pièces de caisse confirmées
 * sans argent reçu sont contrepassées ; les écritures légitimes et les cas douteux ne sont jamais touchés.
 */
class PiecesEncaissementSansEncaissementTest extends TestCase
{
    use HasCaissesDediees, RefreshDatabase;

    private Organization $org;

    private User $user;

    private CompteTresorerie $caisse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Permission::firstOrCreate(['name' => 'ventes.update', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'factures.encaisser', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $this->user->assignRole('admin_entreprise');
        $this->user->givePermissionTo(['ventes.update', 'factures.encaisser']);
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Conakry']);
        $this->user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        $this->caisse = $this->creerCaisseActivePour($this->user, $site->id);
    }

    private function facture(float $montant = 450000): FactureVente
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
        ]);
        $commande = CommandeVente::factory()->create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'total_commande' => $montant]);

        return FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => $montant,
            'site_id' => $this->caisse->site_id,
        ]);
    }

    private function encaisser(FactureVente $facture, float $montant): EncaissementVente
    {
        $this->actingAs($this->user)->post(route('encaissements.store', $facture), [
            'montant' => $montant,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ])->assertSessionHasNoErrors();

        return EncaissementVente::where('facture_vente_id', $facture->id)->latest('id')->firstOrFail();
    }

    /** L'incident : l'encaissement disparaît sans hook, sa pièce validée reste seule. */
    private function piecePerdue(EncaissementVente $encaissement): PieceComptable
    {
        DB::table('encaissements_ventes')->where('id', $encaissement->id)->delete();
        $facture = $encaissement->facture()->first();
        $facture->recalculStatut();

        return PieceComptable::where('source_id', $encaissement->id)->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)->sole();
    }

    private function solde(): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($this->caisse);
    }

    private function constat(string $numero): array
    {
        return collect(app(Service::class)->analyser())->firstWhere('numero', $numero);
    }

    public function test_piece_en_double_d_une_facture_soldee_est_contrepassee_sans_toucher_la_vraie(): void
    {
        $facture = $this->facture();
        $orpheline = $this->piecePerdue($this->encaisser($facture, 450000));
        $vrai = $this->encaisser($facture, 450000);
        $vraiePiece = PieceComptable::where('source_id', $vrai->id)->sole();
        $this->assertSame(StatutFactureVente::PAYEE, $facture->fresh()->statut_facture);
        $this->assertEquals(900000, $this->solde());

        $this->assertSame(Service::CONTREPASSER, $this->constat($orpheline->numero)['decision']);
        $bilan = app(Service::class)->regulariser();

        $this->assertCount(1, $bilan['contrepassees']);
        $this->assertSame(StatutPieceComptable::CONTREPASSEE, $orpheline->fresh()->statut);
        $this->assertStringContainsString('Incident 08/11', PieceComptable::where('piece_origine_id', $orpheline->id)->sole()->libelle);
        $this->assertSame(StatutPieceComptable::VALIDEE, $vraiePiece->fresh()->statut);
        $this->assertEquals(450000, $this->solde());
        $this->assertSame(1, EncaissementVente::where('facture_vente_id', $facture->id)->count());
    }

    public function test_piece_d_une_facture_annulee_sans_encaissement_est_contrepassee(): void
    {
        $facture = $this->facture();
        $orpheline = $this->piecePerdue($this->encaisser($facture, 450000));
        $facture->forceFill(['statut_facture' => StatutFactureVente::ANNULEE->value])->saveQuietly();

        app(Service::class)->regulariser();

        $this->assertSame(StatutPieceComptable::CONTREPASSEE, $orpheline->fresh()->statut);
        $this->assertEquals(0, $this->solde());
    }

    public function test_facture_non_couverte_reste_a_examiner_et_n_est_jamais_touchee(): void
    {
        $facture = $this->facture();
        $orpheline = $this->piecePerdue($this->encaisser($facture, 450000));

        $constat = $this->constat($orpheline->numero);
        $bilan = app(Service::class)->regulariser();

        $this->assertSame(Service::A_EXAMINER, $constat['decision']);
        $this->assertStringContainsString("l'argent a peut-être été reçu", $constat['raison']);
        $this->assertSame([], $bilan['contrepassees']);
        $this->assertSame(StatutPieceComptable::VALIDEE, $orpheline->fresh()->statut);
        $this->assertEquals(450000, $this->solde());
    }

    public function test_ecritures_inattendues_restent_a_examiner(): void
    {
        $facture = $this->facture();
        $orpheline = $this->piecePerdue($this->encaisser($facture, 450000));
        $this->encaisser($facture, 450000);
        $ligne = EcritureComptable::where('piece_comptable_id', $orpheline->id)->where('debit', '>', 0)->sole();
        EcritureComptable::create([...$ligne->only(['compte_comptable_id', 'site_id', 'libelle']), 'piece_comptable_id' => $orpheline->id, 'debit' => 1, 'credit' => 0]);

        app(Service::class)->regulariser();

        $this->assertSame(Service::A_EXAMINER, $this->constat($orpheline->numero)['decision']);
        $this->assertSame(StatutPieceComptable::VALIDEE, $orpheline->fresh()->statut);
    }

    public function test_relancer_ne_contrepasse_jamais_deux_fois(): void
    {
        $facture = $this->facture();
        $orpheline = $this->piecePerdue($this->encaisser($facture, 450000));
        $this->encaisser($facture, 450000);

        app(Service::class)->regulariser();
        $second = app(Service::class)->regulariser();

        $this->assertSame([], $second['contrepassees']);
        $this->assertSame(1, PieceComptable::where('piece_origine_id', $orpheline->id)->count());
        $this->assertEquals(450000, $this->solde());
    }

    public function test_le_rapport_previsionnel_n_ecrit_rien(): void
    {
        $facture = $this->facture();
        $orpheline = $this->piecePerdue($this->encaisser($facture, 450000));
        $this->encaisser($facture, 450000);
        $pieces = PieceComptable::count();

        $this->artisan('comptabilite:pieces-encaissement-sans-encaissement')->assertSuccessful();

        $this->assertSame($pieces, PieceComptable::count());
        $this->assertSame(StatutPieceComptable::VALIDEE, $orpheline->fresh()->statut);
        $this->assertSame(450000.0, $this->constat($orpheline->numero)['debit']);
    }
}
