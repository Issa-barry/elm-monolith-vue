<?php

namespace Tests\Unit;

use App\Enums\TypeSupportTresorerie;
use Tests\TestCase;

/**
 * Déduction pure Type ↔ moyen_paiement — cf. revue du 2026-08-22 : le dropdown
 * "Compte comptable" ne filtrait rien, permettant de créer un support "Caisse"
 * avec un compte Mobile Money (561300). Cette règle est la base du filtrage
 * appliqué dans SupportTresorerieTypeResolver et de la validation serveur.
 */
class TypeSupportTresorerieTest extends TestCase
{
    public function test_especes_ou_null_donne_caisse(): void
    {
        $this->assertSame(TypeSupportTresorerie::CAISSE, TypeSupportTresorerie::fromMoyenPaiement('especes'));
        $this->assertSame(TypeSupportTresorerie::CAISSE, TypeSupportTresorerie::fromMoyenPaiement(null));
    }

    public function test_virement_ou_cheque_donne_banque(): void
    {
        $this->assertSame(TypeSupportTresorerie::BANQUE, TypeSupportTresorerie::fromMoyenPaiement('virement'));
        $this->assertSame(TypeSupportTresorerie::BANQUE, TypeSupportTresorerie::fromMoyenPaiement('cheque'));
    }

    public function test_mobile_money_generique_ou_avec_operateur_donne_mobile_money(): void
    {
        $this->assertSame(TypeSupportTresorerie::MOBILE_MONEY, TypeSupportTresorerie::fromMoyenPaiement('mobile_money'));
        $this->assertSame(TypeSupportTresorerie::MOBILE_MONEY, TypeSupportTresorerie::fromMoyenPaiement('mobile_money:orange'));
        $this->assertSame(TypeSupportTresorerie::MOBILE_MONEY, TypeSupportTresorerie::fromMoyenPaiement('mobile_money:mtn'));
        $this->assertSame(TypeSupportTresorerie::MOBILE_MONEY, TypeSupportTresorerie::fromMoyenPaiement('mobile_money:djomy'));
    }

    public function test_moyen_paiement_inconnu_donne_null(): void
    {
        $this->assertNull(TypeSupportTresorerie::fromMoyenPaiement('bitcoin'));
    }
}
