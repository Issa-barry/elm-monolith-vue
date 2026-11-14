<?php

namespace Tests\Unit;

use App\Enums\CategorieDepense;
use App\Enums\EvenementComptable;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Services\Comptabilite\DepenseComptabilisationService;
use Tests\TestCase;

/**
 * La liste CATEGORIES_AVANCE_TIERS (lue par le rapport par agence) reste exactement celle que la
 * comptabilisation traite en avance récupérable sur le tiers : une seule règle, jamais deux listes
 * qui divergent.
 */
class DepenseComptabilisationCategoriesTest extends TestCase
{
    public function test_la_liste_des_avances_sur_tiers_suit_la_comptabilisation(): void
    {
        $service = app(DepenseComptabilisationService::class);

        foreach (CategorieDepense::cases() as $categorie) {
            $depense = new Depense;
            $depense->setRelation('depenseType', new DepenseType(['categorie' => $categorie->value]));

            $this->assertSame(
                in_array($categorie, DepenseComptabilisationService::CATEGORIES_AVANCE_TIERS, true),
                $service->evenementPour($depense) === EvenementComptable::DEPENSE_AVANCE_TIERS_VALIDEE,
                $categorie->value,
            );
        }
    }
}
