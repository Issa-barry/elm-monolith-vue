<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\TypeSupportTresorerie;
use App\Models\CompteComptable;
use App\Models\Organization;
use App\Services\Comptabilite\SupportTresorerieTypeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Vérifie la déduction Type ↔ compte à partir des compta_mappings réellement
 * seedés par PlanComptableBootstrapService pour une organisation — pas de
 * numéro de compte codé en dur dans le test, uniquement les libellés attendus
 * du plan comptable par défaut.
 */
class SupportTresorerieTypeResolverTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([]);
    }

    public function test_type_pour_compte_deduit_caisse_banque_et_mobile_money(): void
    {
        $resolver = app(SupportTresorerieTypeResolver::class);

        $caisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $banque = CompteComptable::where('organization_id', $this->org->id)->where('numero', '521000')->firstOrFail();
        $djomy = CompteComptable::where('organization_id', $this->org->id)->where('numero', '561300')->firstOrFail();

        $this->assertSame(TypeSupportTresorerie::CAISSE, $resolver->typePourCompte($this->org->id, $caisse->id));
        $this->assertSame(TypeSupportTresorerie::BANQUE, $resolver->typePourCompte($this->org->id, $banque->id));
        $this->assertSame(TypeSupportTresorerie::MOBILE_MONEY, $resolver->typePourCompte($this->org->id, $djomy->id));
    }

    public function test_type_pour_compte_retourne_null_pour_un_compte_hors_perimetre_tresorerie(): void
    {
        $resolver = app(SupportTresorerieTypeResolver::class);

        $ventes = CompteComptable::where('organization_id', $this->org->id)->where('numero', '701000')->firstOrFail();

        $this->assertNull($resolver->typePourCompte($this->org->id, $ventes->id));
    }

    public function test_types_par_compte_ne_melange_pas_deux_organisations(): void
    {
        $autreOrg = Organization::factory()->create();
        $resolver = app(SupportTresorerieTypeResolver::class);

        $caisseAutreOrg = CompteComptable::where('organization_id', $autreOrg->id)->where('numero', '571000')->firstOrFail();

        $this->assertFalse($resolver->typesParCompte($this->org->id)->has($caisseAutreOrg->id));
    }
}
