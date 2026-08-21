<?php

namespace Tests\Unit;

use App\Enums\StatutCommission;
use App\Models\CommissionEnveloppePart;
use App\Support\Commission\CommissionKpiBuckets;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * CommissionKpiBuckets ventile un ensemble de CommissionEnveloppePart en 4 compartiments —
 * décision produit du 20/08/2026 (« visible ne veut pas dire payable »). Modèles en mémoire
 * uniquement (jamais persistés) : les montants utilisés (montant_a_payer/montant_restant) sont
 * de purs accesseurs calculés, aucune requête ni RefreshDatabase nécessaire ici.
 */
class CommissionKpiBucketsTest extends TestCase
{
    private function makePart(StatutCommission $statut, float $montantNet, float $montantVerse = 0.0): CommissionEnveloppePart
    {
        return new CommissionEnveloppePart([
            'montant_brut' => $montantNet,
            'montant_net' => $montantNet,
            'montant_verse' => $montantVerse,
            'montant_actuel' => null,
            'statut' => $statut,
        ]);
    }

    public function test_facture_unique_creee_alimente_uniquement_en_attente_periode(): void
    {
        $parts = new Collection([$this->makePart(StatutCommission::CREEE, 10_000)]);

        $buckets = CommissionKpiBuckets::calculer($parts);

        $this->assertSame(10_000.0, $buckets['total_genere']);
        $this->assertSame(10_000.0, $buckets['en_attente_periode']);
        $this->assertSame(0.0, $buckets['payable']);
        $this->assertSame(0.0, $buckets['deja_paye']);
    }

    public function test_part_impayee_alimente_uniquement_payable(): void
    {
        $parts = new Collection([$this->makePart(StatutCommission::IMPAYE, 5_000)]);

        $buckets = CommissionKpiBuckets::calculer($parts);

        $this->assertSame(5_000.0, $buckets['total_genere']);
        $this->assertSame(0.0, $buckets['en_attente_periode']);
        $this->assertSame(5_000.0, $buckets['payable']);
        $this->assertSame(0.0, $buckets['deja_paye']);
    }

    public function test_part_partielle_ventile_reste_en_payable_et_verse_en_deja_paye(): void
    {
        $parts = new Collection([$this->makePart(StatutCommission::PARTIEL, 5_000, 2_000)]);

        $buckets = CommissionKpiBuckets::calculer($parts);

        $this->assertSame(5_000.0, $buckets['total_genere']);
        $this->assertSame(0.0, $buckets['en_attente_periode']);
        $this->assertSame(3_000.0, $buckets['payable']); // reste = 5000 - 2000
        $this->assertSame(2_000.0, $buckets['deja_paye']);
    }

    public function test_part_payee_alimente_uniquement_deja_paye(): void
    {
        $parts = new Collection([$this->makePart(StatutCommission::PAYE, 5_000, 5_000)]);

        $buckets = CommissionKpiBuckets::calculer($parts);

        $this->assertSame(5_000.0, $buckets['total_genere']);
        $this->assertSame(0.0, $buckets['en_attente_periode']);
        $this->assertSame(0.0, $buckets['payable']); // reste = 0, déjà soldée
        $this->assertSame(5_000.0, $buckets['deja_paye']);
    }

    /** Une part annulée n'entre dans AUCUN compartiment, y compris total_genere. */
    public function test_part_annulee_najoute_jamais_au_total_genere(): void
    {
        $parts = new Collection([
            $this->makePart(StatutCommission::IMPAYE, 5_000),
            $this->makePart(StatutCommission::ANNULEE, 999_999),
        ]);

        $buckets = CommissionKpiBuckets::calculer($parts);

        $this->assertSame(5_000.0, $buckets['total_genere']);
        $this->assertSame(5_000.0, $buckets['payable']);
    }

    /**
     * Cas de l'exemple produit (décision du 20/08/2026) : plusieurs parts de statuts mélangés,
     * total_genere doit être une partition exacte de en_attente_periode + payable + deja_paye.
     */
    public function test_partition_exacte_avec_statuts_melanges(): void
    {
        $parts = new Collection([
            $this->makePart(StatutCommission::CREEE, 1_080_000),
            $this->makePart(StatutCommission::IMPAYE, 1_421_064),
        ]);

        $buckets = CommissionKpiBuckets::calculer($parts);

        $this->assertSame(2_501_064.0, $buckets['total_genere']);
        $this->assertSame(1_080_000.0, $buckets['en_attente_periode']);
        $this->assertSame(1_421_064.0, $buckets['payable']);
        $this->assertSame(0.0, $buckets['deja_paye']);
        $this->assertSame(
            $buckets['total_genere'],
            $buckets['en_attente_periode'] + $buckets['payable'] + $buckets['deja_paye'],
        );
    }

    public function test_collection_vide_retourne_des_compartiments_a_zero(): void
    {
        $buckets = CommissionKpiBuckets::calculer(new Collection);

        $this->assertSame(0.0, $buckets['total_genere']);
        $this->assertSame(0.0, $buckets['en_attente_periode']);
        $this->assertSame(0.0, $buckets['payable']);
        $this->assertSame(0.0, $buckets['deja_paye']);
    }

    public function test_fusionner_additionne_plusieurs_jeux_de_compartiments(): void
    {
        $bucketsA = CommissionKpiBuckets::calculer(new Collection([$this->makePart(StatutCommission::CREEE, 1_000)]));
        $bucketsB = CommissionKpiBuckets::calculer(new Collection([$this->makePart(StatutCommission::IMPAYE, 2_000)]));

        $fusion = CommissionKpiBuckets::fusionner([$bucketsA, $bucketsB]);

        $this->assertSame(3_000.0, $fusion['total_genere']);
        $this->assertSame(1_000.0, $fusion['en_attente_periode']);
        $this->assertSame(2_000.0, $fusion['payable']);
        $this->assertSame(0.0, $fusion['deja_paye']);
    }
}
