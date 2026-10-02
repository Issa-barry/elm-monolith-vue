<?php

namespace Tests\Unit;

use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use Tests\TestCase;

/**
 * Colonne « Qté » des listes Ventes et Factures (cf. CommandeVente::quantite_totale).
 */
class CommandeVenteQuantiteTotaleTest extends TestCase
{
    private function ligne(?int $demandee, ?int $chargee = null, ?int $livree = null): CommandeVenteLigne
    {
        return new CommandeVenteLigne([
            'quantite_demandee' => $demandee,
            'quantite_chargee' => $chargee,
            'quantite_livree' => $livree,
        ]);
    }

    public function test_quantite_effective_retombe_sur_la_demandee_tant_que_rien_n_est_charge(): void
    {
        $this->assertSame(10, $this->ligne(10)->quantite_effective);
    }

    public function test_quantite_effective_prefere_la_chargee_a_la_demandee(): void
    {
        $this->assertSame(8, $this->ligne(10, 8)->quantite_effective);
    }

    public function test_quantite_effective_prefere_la_livree_a_la_chargee(): void
    {
        $this->assertSame(7, $this->ligne(10, 8, 7)->quantite_effective);
    }

    public function test_une_quantite_a_zero_n_est_jamais_remplacee_par_l_etape_precedente(): void
    {
        $this->assertSame(0, $this->ligne(10, 0)->quantite_effective);
        $this->assertSame(0, $this->ligne(10, 8, 0)->quantite_effective);
    }

    public function test_quantite_totale_somme_les_quantites_effectives_des_lignes(): void
    {
        $commande = new CommandeVente;
        $commande->setRelation('lignes', collect([
            $this->ligne(10),
            $this->ligne(5, 4),
            $this->ligne(3, 3, 2),
        ]));

        $this->assertSame(16, $commande->quantite_totale);
    }

    public function test_quantite_totale_sans_ligne_vaut_zero(): void
    {
        $commande = new CommandeVente;
        $commande->setRelation('lignes', collect());

        $this->assertSame(0, $commande->quantite_totale);
    }
}
