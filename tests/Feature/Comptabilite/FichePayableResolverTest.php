<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Models\User;
use App\Services\Commission\FichePayableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Relie une ligne d'écran Commissions à la fiche sur laquelle son bouton Payer enregistre
 * le paiement : jamais une fiche d'une période non validée, déjà soldée, hors du filtre de
 * période, d'une autre organisation, ou que l'utilisateur n'a pas le droit de payer.
 */
class FichePayableResolverTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private string $beneficiaireId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        $this->beneficiaireId = (string) Str::ulid();
    }

    private function periode(string $debut, string $fin, StatutPeriodePaiement $statut, ?Organization $org = null): PaiementPeriode
    {
        return PaiementPeriode::create([
            'organization_id' => ($org ?? $this->org)->id,
            'reference' => 'PAY-'.Str::upper(Str::random(8)),
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'statut' => $statut->value,
        ]);
    }

    private function fiche(PaiementPeriode $periode, float $net, float $paye = 0, ?string $siteId = null): PaiementFiche
    {
        return PaiementFiche::create([
            'organization_id' => $periode->organization_id,
            'periode_id' => $periode->id,
            'reference' => 'FICHE-'.Str::upper(Str::random(8)),
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $this->beneficiaireId,
            'beneficiaire_nom' => 'Livreur Test',
            'site_id' => $siteId,
            'montant_brut' => $net,
            'total_deductions' => 0,
            'montant_net' => $net,
            'montant_paye' => $paye,
            'statut' => $paye > 0 ? StatutFichePaiement::PARTIELLEMENT_PAYE->value : StatutFichePaiement::A_PAYER->value,
        ]);
    }

    private function resoudre(?User $user = null, string $periodeCode = ''): ?array
    {
        return FichePayableResolver::pourBeneficiaires($user ?? $this->user, 'livreur', [$this->beneficiaireId], $periodeCode)
            ->get($this->beneficiaireId);
    }

    public function test_retient_la_fiche_due_dune_periode_validee(): void
    {
        $fiche = $this->fiche($this->periode('2026-09-01', '2026-09-15', StatutPeriodePaiement::VALIDEE), 10_000, 4_000);

        $resolu = $this->resoudre();

        $this->assertSame($fiche->id, $resolu['id']);
        $this->assertSame(6_000.0, $resolu['montant_restant']);
        $this->assertSame('2026-09-01', $resolu['periode_debut']);
        $this->assertSame('2026-09-15', $resolu['periode_fin']);
    }

    public function test_ignore_une_periode_non_validee(): void
    {
        foreach ([StatutPeriodePaiement::BROUILLON, StatutPeriodePaiement::CALCULEE, StatutPeriodePaiement::CLOTUREE] as $statut) {
            $this->fiche($this->periode('2026-09-01', '2026-09-15', $statut), 10_000);
        }

        $this->assertNull($this->resoudre());
    }

    public function test_ignore_une_fiche_deja_soldee(): void
    {
        $this->fiche($this->periode('2026-09-01', '2026-09-15', StatutPeriodePaiement::VALIDEE), 10_000, 10_000);

        $this->assertNull($this->resoudre());
    }

    public function test_sans_filtre_retient_la_fiche_la_plus_ancienne(): void
    {
        $recente = $this->fiche($this->periode('2026-09-16', '2026-09-30', StatutPeriodePaiement::VALIDEE), 5_000);
        $ancienne = $this->fiche($this->periode('2026-09-01', '2026-09-15', StatutPeriodePaiement::VALIDEE), 3_000);

        $this->assertSame($ancienne->id, $this->resoudre()['id']);
        $this->assertSame($recente->id, $this->resoudre(null, '2026-09-P2')['id']);
    }

    public function test_le_filtre_de_periode_exclut_les_autres_periodes(): void
    {
        $this->fiche($this->periode('2026-09-01', '2026-09-15', StatutPeriodePaiement::VALIDEE), 3_000);

        $this->assertNull($this->resoudre(null, '2026-09-P2'));
    }

    public function test_sans_permission_payer_aucune_fiche(): void
    {
        $this->fiche($this->periode('2026-09-01', '2026-09-15', StatutPeriodePaiement::VALIDEE), 3_000);
        $lecteur = $this->makeUserWithPermissions($this->org, ['comptabilite.read']);

        $this->assertNull($this->resoudre($lecteur));
    }

    public function test_non_admin_ne_retrouve_pas_la_fiche_dune_agence_non_affectee(): void
    {
        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre agence',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $this->fiche($this->periode('2026-09-01', '2026-09-15', StatutPeriodePaiement::VALIDEE), 3_000, 0, $autreSite->id);

        Permission::firstOrCreate(['name' => 'comptabilite.payer', 'guard_name' => 'web']);
        $caissier = User::factory()->create(['organization_id' => $this->org->id]);
        $caissier->givePermissionTo('comptabilite.payer');

        $this->assertNull($this->resoudre($caissier));
        $this->assertNotNull($this->resoudre());
    }

    public function test_isolation_organisationnelle(): void
    {
        $autreOrg = Organization::factory()->create();
        $this->fiche($this->periode('2026-09-01', '2026-09-15', StatutPeriodePaiement::VALIDEE, $autreOrg), 3_000);

        $this->assertNull($this->resoudre());
    }
}
