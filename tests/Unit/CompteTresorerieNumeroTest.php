<?php

namespace Tests\Unit;

use App\Models\CompteTresorerie;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Comparaison de deux écritures d'un numéro de portefeuille Mobile Money (ADR 0027) : un
 * portefeuille ne porte qu'un seul compte, quelle que soit la façon dont son numéro est saisi.
 */
class CompteTresorerieNumeroTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function memesPortefeuilles(): array
    {
        return [
            'identiques' => ['620000001', '620000001'],
            'espaces' => ['620 00 00 01', '620000001'],
            'tirets et points' => ['620-000.001', '620 000 001'],
            'indicatif avec plus' => ['+224 620 00 00 01', '620 00 00 01'],
            'indicatif sans plus' => ['224620000001', '620000001'],
            'préfixe international 00' => ['00224620000001', '620000001'],
            '00 contre plus' => ['00224 620 00 00 01', '+224620000001'],
            'indicatif à un chiffre' => ['+1 5550001234', '5550001234'],
            'indicatif à deux chiffres' => ['+33 758855039', '758855039'],
            'code marchand en lettres' => ['om-elm 01', 'OMELM01'],
        ];
    }

    #[DataProvider('memesPortefeuilles')]
    public function test_deux_ecritures_du_meme_portefeuille_sont_reconnues(?string $a, ?string $b): void
    {
        $this->assertTrue(CompteTresorerie::memeNumero($a, $b));
        $this->assertTrue(CompteTresorerie::memeNumero($b, $a));
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function portefeuillesDifferents(): array
    {
        return [
            'dernier chiffre' => ['620000001', '620000002'],
            'même longueur, autre début' => ['620000001', '720000001'],
            'préfixe trop long pour un indicatif' => ['1224620000001', '620000001'],
            'numéro trop court pour porter un indicatif' => ['2241234', '1234'],
            'lettres : jamais de suffixe' => ['AB620000001', '620000001'],
            'vide' => ['', '620000001'],
            'nul' => [null, null],
            'ponctuation seule' => [' - ', '620000001'],
        ];
    }

    #[DataProvider('portefeuillesDifferents')]
    public function test_deux_portefeuilles_differents_ne_sont_jamais_confondus(?string $a, ?string $b): void
    {
        $this->assertFalse(CompteTresorerie::memeNumero($a, $b));
        $this->assertFalse(CompteTresorerie::memeNumero($b, $a));
    }

    public function test_la_forme_de_comparaison_ne_garde_que_lettres_et_chiffres_sans_le_prefixe_international(): void
    {
        $this->assertSame('620000001', CompteTresorerie::normaliserNumero(' 620 00-00.01 '));
        $this->assertSame('224620000001', CompteTresorerie::normaliserNumero('+224 620 00 00 01'));
        $this->assertSame('224620000001', CompteTresorerie::normaliserNumero('00224 620 00 00 01'));
        $this->assertSame('0012', CompteTresorerie::normaliserNumero('0012'), 'un code court qui commence par 00 n\'est pas un numéro international');
        $this->assertSame('', CompteTresorerie::normaliserNumero(null));
    }
}
