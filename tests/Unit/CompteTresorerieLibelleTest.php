<?php

namespace Tests\Unit;

use App\Enums\TypeSupportTresorerie;
use App\Models\CompteTresorerie;
use Tests\TestCase;

/**
 * Génération pure du libellé automatique "{Type} de {Site}" — cf. revue du
 * 2026-08-22 : le libellé est devenu facultatif à la saisie, généré ici
 * plutôt que rejeté par une validation "required" invisible à l'utilisateur.
 */
class CompteTresorerieLibelleTest extends TestCase
{
    public function test_genere_caisse_de_site(): void
    {
        $this->assertSame('Caisse de Cba', CompteTresorerie::libelleBase(TypeSupportTresorerie::CAISSE, 'Cba'));
    }

    public function test_genere_banque_de_site(): void
    {
        $this->assertSame('Banque de Matoto', CompteTresorerie::libelleBase(TypeSupportTresorerie::BANQUE, 'Matoto'));
    }

    public function test_genere_mobile_money_de_site(): void
    {
        $this->assertSame('Mobile Money de Sonfonia', CompteTresorerie::libelleBase(TypeSupportTresorerie::MOBILE_MONEY, 'Sonfonia'));
    }

    public function test_supporte_un_nom_de_site_vide(): void
    {
        $this->assertSame('Caisse de', CompteTresorerie::libelleBase(TypeSupportTresorerie::CAISSE, ''));
    }
}
