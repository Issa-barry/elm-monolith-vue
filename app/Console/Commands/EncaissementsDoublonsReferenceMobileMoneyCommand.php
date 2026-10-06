<?php

namespace App\Console\Commands;

use App\Enums\ModePaiement;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Références Mobile Money utilisées par plusieurs encaissements d'une même organisation (espaces et
 * casse ignorés, tous opérateurs confondus — ADR 0014). Depuis la règle d'unicité, seuls des
 * encaissements antérieurs peuvent apparaître ici : la migration les a conservés tels quels, seul le
 * plus ancien de chaque groupe portant la clé d'unicité.
 *
 * Ne modifie jamais rien : lecture seule. La régularisation éventuelle d'un doublon est une décision
 * humaine, jamais automatique.
 */
class EncaissementsDoublonsReferenceMobileMoneyCommand extends Command
{
    protected $signature = 'encaissements:doublons-reference-mobile-money
        {--organization= : ID d\'organisation ; toutes si omis}';

    protected $description = 'Lecture seule : liste les références Mobile Money utilisées par plusieurs encaissements d\'une même organisation.';

    public function handle(): int
    {
        $groupes = $this->utilisations()
            ->groupBy(fn (object $u) => $u->organization_id.'|'.mb_strtoupper(trim((string) $u->reference_paiement)))
            ->filter(fn (Collection $groupe) => $groupe->count() > 1);

        if ($groupes->isEmpty()) {
            $this->info('Aucune référence Mobile Money en doublon.');

            return self::SUCCESS;
        }

        $this->warn("{$groupes->count()} référence(s) Mobile Money utilisée(s) plusieurs fois :");
        $this->table(
            ['Organisation', 'Référence', 'Encaissement', 'Facture', 'Opérateur', 'Montant', 'Date', 'Clé d\'unicité'],
            $groupes->flatMap(fn (Collection $groupe) => $groupe->map(fn (object $u) => [
                $u->organization_name,
                mb_strtoupper(trim((string) $u->reference_paiement)),
                $u->id,
                $u->facture_reference,
                $u->operateur_mobile_money ?? '—',
                number_format((float) $u->montant, 0, ',', ' '),
                substr((string) $u->date_encaissement, 0, 10),
                $u->cle_reference_mobile_money !== null ? 'oui' : 'non (historique)',
            ]))->all(),
        );

        return self::SUCCESS;
    }

    /** @return Collection<int, object> */
    private function utilisations(): Collection
    {
        return DB::table('encaissements_ventes as e')
            ->join('factures_ventes as f', 'f.id', '=', 'e.facture_vente_id')
            ->join('organizations as o', 'o.id', '=', 'f.organization_id')
            ->where('e.mode_paiement', ModePaiement::MOBILE_MONEY->value)
            ->whereNotNull('e.reference_paiement')
            ->whereRaw("TRIM(e.reference_paiement) <> ''")
            ->when($this->option('organization'), fn ($q, $id) => $q->where('f.organization_id', $id))
            ->orderBy('e.created_at')
            ->orderBy('e.id')
            ->get([
                'e.id', 'e.reference_paiement', 'e.operateur_mobile_money', 'e.montant', 'e.date_encaissement',
                'e.cle_reference_mobile_money', 'f.reference as facture_reference', 'f.organization_id',
                'o.name as organization_name',
            ]);
    }
}
