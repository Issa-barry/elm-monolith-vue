<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CategorieJournal;
use App\Enums\SensJournal;
use App\Http\Controllers\Comptabilite\JournalFinancierController;
use App\Http\Controllers\Comptabilite\JournalTresorerieController;
use App\Models\JournalTresorerie;
use App\Services\JournalTresorerieService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verrou de non-régression du chantier du 2026-08-22 : l'ancien registre
 * parallèle JournalTresorerie (table, modèle, service, contrôleur, enums)
 * a été entièrement supprimé au profit du grand livre SYSCOHADA
 * (compta_pieces/compta_ecritures) comme source comptable unique.
 */
class JournalTresorerieSuppressionTest extends TestCase
{
    public function test_aucune_classe_journal_tresorerie_ne_subsiste(): void
    {
        $this->assertFalse(class_exists(JournalTresorerie::class, false));
        $this->assertFalse(class_exists(JournalTresorerieService::class, false));
        $this->assertFalse(class_exists(JournalTresorerieController::class, false));
        $this->assertFalse(enum_exists(CategorieJournal::class, false));
        $this->assertFalse(enum_exists(SensJournal::class, false));
    }

    public function test_la_table_journal_tresorerie_nexiste_plus_en_base(): void
    {
        $this->assertFalse(Schema::hasTable('journal_tresorerie'));
    }

    public function test_la_route_journal_financier_pointe_vers_le_nouveau_controleur(): void
    {
        $route = Route::getRoutes()->getByName('comptabilite.journal');

        $this->assertNotNull($route);
        $this->assertSame(
            JournalFinancierController::class,
            explode('@', $route->getActionName())[0],
        );
    }
}
