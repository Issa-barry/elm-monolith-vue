<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\OperateurMobileMoney;
use App\Enums\TypeSupportTresorerie;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\CompteTresorerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Chaque Mobile Money est un compte à part, porté par son propre support (décision du 24/09/2026,
 * cf. docs/encaissements.md) : plan comptable par défaut, reprise des supports existants et libellé.
 */
class SupportMobileMoneyOperateurTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([]);
    }

    private function compte(string $numero): CompteComptable
    {
        return CompteComptable::where('organization_id', $this->org->id)->where('numero', $numero)->firstOrFail();
    }

    public function test_le_plan_par_defaut_donne_un_compte_a_chaque_operateur_saisissable(): void
    {
        $attendus = ['orange' => '561100', 'mtn' => '561200', 'kulu' => '561400', 'paycard' => '561500', 'soutra_money' => '561600'];

        foreach (OperateurMobileMoney::avecWallet() as $operateur) {
            $detail = $operateur->detailComptable();
            $mapping = CompteMapping::where('organization_id', $this->org->id)
                ->where('evenement', 'encaissement_vente_recu')
                ->where('role', 'tresorerie')
                ->where('moyen_paiement', "mobile_money:{$detail}")
                ->with('compte')
                ->first();

            $this->assertNotNull($mapping, "Aucun compte pour {$operateur->label()}");
            $this->assertSame($attendus[$detail], $mapping->compte->numero);
        }
    }

    public function test_la_migration_deduit_l_operateur_des_supports_existants_depuis_leur_compte(): void
    {
        $site = $this->user->sites()->first();
        $orange = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $this->compte('561100')->id,
            'type' => TypeSupportTresorerie::MOBILE_MONEY->value,
            'libelle' => 'Mobile Money de Matoto',
        ]);
        $generique = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $this->compte('561000')->id,
            'type' => TypeSupportTresorerie::MOBILE_MONEY->value,
            'libelle' => 'Mobile Money générique',
        ]);

        $migration = require database_path('migrations/2026_09_24_200000_add_operateur_mobile_money_to_compta_supports_tresorerie_table.php');
        $migration->up();

        $this->assertSame(OperateurMobileMoney::ORANGE_MONEY, $orange->fresh()->operateur_mobile_money);
        // Compte sans opérateur connu : jamais deviné, le support reste à compléter.
        $this->assertNull($generique->fresh()->operateur_mobile_money);
    }

    public function test_le_libelle_automatique_nomme_l_operateur(): void
    {
        $this->assertSame('Kulu de Matoto', CompteTresorerie::libelleBase(TypeSupportTresorerie::MOBILE_MONEY, 'Matoto', OperateurMobileMoney::KULU));
        $this->assertSame('Mobile Money de Matoto', CompteTresorerie::libelleBase(TypeSupportTresorerie::MOBILE_MONEY, 'Matoto'));
        $this->assertSame('Banque de Matoto', CompteTresorerie::libelleBase(TypeSupportTresorerie::BANQUE, 'Matoto', 'kulu'));
    }
}
