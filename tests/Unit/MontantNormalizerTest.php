<?php

namespace Tests\Unit;

use App\Support\MontantNormalizer;
use Tests\TestCase;

class MontantNormalizerTest extends TestCase
{
    public function test_supprime_les_espaces_normales(): void
    {
        $this->assertSame('20000000', MontantNormalizer::normalize('20 000 000'));
    }

    public function test_supprime_les_espaces_fines_insecables(): void
    {
        // U+202F — séparateur produit par toLocaleString('fr-FR') côté client.
        $this->assertSame('20000000', MontantNormalizer::normalize("20\u{202F}000\u{202F}000"));
    }

    public function test_supprime_les_espaces_insecables_normales(): void
    {
        // U+00A0
        $this->assertSame('20000000', MontantNormalizer::normalize("20\u{00A0}000\u{00A0}000"));
    }

    public function test_remplace_la_virgule_decimale_par_un_point(): void
    {
        $this->assertSame('1500.50', MontantNormalizer::normalize('1 500,50'));
    }

    public function test_laisse_un_nombre_deja_propre_inchange(): void
    {
        $this->assertSame('20000000', MontantNormalizer::normalize('20000000'));
    }

    public function test_ne_touche_pas_a_une_valeur_non_chaine(): void
    {
        $this->assertSame(20000000, MontantNormalizer::normalize(20000000));
        $this->assertNull(MontantNormalizer::normalize(null));
    }

    public function test_conserve_une_chaine_vide_telle_quelle(): void
    {
        $this->assertSame('', MontantNormalizer::normalize(''));
    }
}
