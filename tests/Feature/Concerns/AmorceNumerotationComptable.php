<?php

namespace Tests\Feature\Concerns;

use App\Models\JournalComptable;
use App\Services\Comptabilite\PeriodeComptableResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tests de concurrence MySQL : crée les compteurs de numérotation de tous les journaux de
 * l'organisation pour l'exercice en cours, comme en production où ils existent dès la première
 * pièce de chaque journal. Sans eux, chaque test (organisation neuve) tomberait sur la première
 * pièce d'un journal, seul cas où deux numérotations simultanées peuvent encore s'interbloquer
 * (PieceNumerotationService), et non sur le fonctionnement courant qu'il veut prouver. Aucun numéro
 * n'est consommé.
 */
trait AmorceNumerotationComptable
{
    private function amorcerNumerotation(string $organizationId): void
    {
        $exerciceId = app(PeriodeComptableResolver::class)->resolveOrCreate($organizationId, Carbon::now())->exercice_comptable_id;

        foreach (JournalComptable::where('organization_id', $organizationId)->pluck('id') as $journalId) {
            DB::table('compta_piece_sequences')->insertOrIgnore([
                'organization_id' => $organizationId,
                'journal_comptable_id' => $journalId,
                'exercice_comptable_id' => $exerciceId,
                'dernier_numero' => 0,
            ]);
        }
    }
}
