<?php

namespace Tests\Feature\Contacts;

use App\Models\ContactsFusion;
use App\Services\Contacts\Fusion\ReferencesFusion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lot 9b : toute clé étrangère vers une fiche Personne ou Entreprise est transférée par la fusion
 * ou explicitement exclue. Une nouvelle relation non déclarée ferait échouer ce test, plutôt que
 * de laisser une fusion pointer encore vers la fiche archivée.
 */
class ReferencesFusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_registre_couvre_toutes_les_cles_etrangeres_vers_les_fiches(): void
    {
        $declarees = [];
        foreach ([ContactsFusion::TYPE_PERSONNE, ContactsFusion::TYPE_ENTREPRISE] as $type) {
            foreach (ReferencesFusion::pour($type) as $reference) {
                $declarees[] = "{$reference['table']}.{$reference['colonne']}";
            }
        }
        $declarees = [...$declarees, ...ReferencesFusion::EXCLUES];

        $manquantes = [];
        foreach (Schema::getTableListing() as $table) {
            $nom = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            foreach (Schema::getForeignKeys($nom) as $cle) {
                if (! in_array($cle['foreign_table'], ['personnes', 'entreprises_tierces'], true)) {
                    continue;
                }
                foreach ($cle['columns'] as $colonne) {
                    if (! in_array("{$nom}.{$colonne}", $declarees, true)) {
                        $manquantes[] = "{$nom}.{$colonne}";
                    }
                }
            }
        }

        $this->assertSame([], $manquantes, 'Clés étrangères vers une fiche absentes de ReferencesFusion.');
    }
}
