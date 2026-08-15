<?php

namespace App\Console\Commands;

use App\Models\CashbackSolde;
use App\Models\CashbackTransaction;
use App\Models\CashbackVersement;
use App\Models\CommandeAchat;
use App\Models\CommandeAchatLigne;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommissionLogistique;
use App\Models\CommissionPayment;
use App\Models\EncaissementVente;
use App\Models\JournalTresorerie;
use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\PaiementCommissionVente;
use App\Models\PieceComptable;
use App\Models\VarianteStock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Efface les ventes, achats, commissions (vente + logistique) et leurs données
 * dérivées (cashback, stock, écritures comptables) — sans jamais toucher aux
 * comptes, sites, produits, véhicules, livreurs, propriétaires, clients, dépenses
 * ni à la paie/RH.
 *
 * Conçu pour formation.eau-la-maman.com (préprod) : cet environnement tourne
 * volontairement avec APP_ENV=production (iso prod, cf. .env.preprod.example),
 * donc app()->environment('production') NE PEUT PAS servir de garde-fou
 * automatique ici — il vaudrait "true" aussi bien sur formation que sur la
 * vraie prod. La seule protection est humaine : la confirmation affiche l'URL
 * et SENTRY_ENVIRONMENT pour que l'opérateur vérifie qu'il est sur la bonne
 * base avant de taper la phrase de confirmation.
 */
class PurgeTransactionsCommand extends Command
{
    protected $signature = 'transactions:purge
        {--organization= : ID ou slug d\'organisation ; toutes si omis}
        {--force : Ne pas demander de confirmation interactive}';

    protected $description = 'Efface ventes, achats, commissions et données dérivées (cashback, stock, écritures comptables) — conserve comptes/sites/produits/véhicules/livreurs/propriétaires/clients/dépenses/RH';

    /** Événements compta (compta_pieces.type_evenement) générés par les ventes. */
    private const EVENEMENTS_COMPTA_VENTE = ['vente_facturee', 'encaissement_vente_recu'];

    /** Sources (journal_tresorerie.source_type) à effacer : vente, commission, cashback. */
    private const SOURCES_TRESORERIE_A_EFFACER = [
        EncaissementVente::class,
        CashbackVersement::class,
        PaiementCommissionVente::class,
        CommissionPayment::class,
    ];

    /** Sources (mouvements_stock.source_type) à effacer : uniquement vente/achat, jamais transfert. */
    private const SOURCES_STOCK_A_EFFACER = [
        CommandeVenteLigne::class,
        CommandeAchatLigne::class,
    ];

    public function handle(): int
    {
        $organizationId = null;
        if ($value = $this->option('organization')) {
            $organization = Organization::query()->where('id', $value)->orWhere('slug', $value)->first();
            if (! $organization) {
                $this->error("Organisation « {$value} » introuvable.");

                return self::FAILURE;
            }
            $organizationId = $organization->id;
        }

        $scoped = fn ($query) => $organizationId ? $query->where('organization_id', $organizationId) : $query;

        $counts = [
            'commandes_ventes' => $scoped(CommandeVente::query())->count(),
            'commandes_achats' => $scoped(CommandeAchat::query())->count(),
            'commissions_logistiques' => $scoped(CommissionLogistique::query())->count(),
            'paiements_commissions_ventes' => $scoped(PaiementCommissionVente::query())->count(),
            'commission_payments' => $scoped(CommissionPayment::query())->count(),
            'cashback_transactions' => $scoped(CashbackTransaction::query())->count(),
            'mouvements_stock (vente/achat)' => $scoped(MouvementStock::whereIn('source_type', self::SOURCES_STOCK_A_EFFACER))->count(),
            'compta_pieces (vente)' => $scoped(PieceComptable::whereIn('type_evenement', self::EVENEMENTS_COMPTA_VENTE))->count(),
            'journal_tresorerie (vente/commission/cashback)' => $scoped(JournalTresorerie::whereIn('source_type', self::SOURCES_TRESORERIE_A_EFFACER))->count(),
        ];

        $this->table(['Table', 'Lignes concernées'], collect($counts)->map(fn ($n, $label) => [$label, $n])->values()->all());

        if (array_sum($counts) === 0) {
            $this->info('Rien à effacer.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('IRRÉVERSIBLE. Ne touche pas aux comptes/sites/produits/véhicules/livreurs/propriétaires/clients/dépenses/RH.');
        $this->line('URL de cette instance : '.config('app.url'));
        $this->line('SENTRY_ENVIRONMENT   : '.(env('SENTRY_ENVIRONMENT') ?: '(non défini)'));
        $this->line('Base de données      : '.config('database.connections.'.config('database.default').'.database'));

        if (! $this->option('force')) {
            if ($this->ask('Vérifiez bien que c\'est la bonne instance ci-dessus. Tapez SUPPRIMER pour confirmer') !== 'SUPPRIMER') {
                $this->info('Annulé.');

                return self::SUCCESS;
            }
        }

        DB::transaction(function () use ($organizationId, $scoped) {
            // forceDelete() impératif : CommandeVente utilise SoftDeletes, un ->delete() se
            // contenterait de poser deleted_at sans jamais émettre de vrai DELETE SQL — ce qui
            // ne déclenche PAS les ON DELETE CASCADE ci-dessous (constaté en test : lignes,
            // factures, commissions restaient toutes en base après un ->delete() classique).
            // Cascade DB (ON DELETE CASCADE) : lignes, activités, factures_ventes,
            // encaissements_ventes, commissions_ventes, commission_parts,
            // versements_commissions, paiements_commissions_ventes_items.
            $scoped(CommandeVente::query())->forceDelete();

            // forceDelete() impératif : CommandeAchat utilise aussi SoftDeletes (même raison).
            // Cascade DB : commande_achat_lignes.
            $scoped(CommandeAchat::query())->forceDelete();

            // Cascade DB : commission_logistique_parts, versements_commission_logistique,
            // commission_payment_items. Ne touche pas transferts_logistiques (parent, pas enfant).
            $scoped(CommissionLogistique::query())->delete();

            // Headers non cascadés depuis ce qui précède (leurs lignes/items le sont, pas eux).
            $scoped(PaiementCommissionVente::query())->delete();
            $scoped(CommissionPayment::query())->delete();

            // Cascade DB : cashback_versements.
            $scoped(CashbackTransaction::query())->delete();

            // Solde client remis à zéro plutôt que supprimé (une ligne par client, unique
            // organization_id+client_id — on garde la ligne pour ne pas casser ce qui compte dessus).
            $scoped(CashbackSolde::query())->update([
                'cumul_achats' => 0,
                'cashback_en_attente' => 0,
                'total_cashback_gagne' => 0,
                'total_cashback_verse' => 0,
            ]);

            // Historique de mouvements de stock issus des ventes/achats uniquement — les
            // mouvements de transferts logistiques (source_type=TransfertLigne) ne sont pas
            // concernés, transferts_logistiques reste intact.
            $scoped(MouvementStock::whereIn('source_type', self::SOURCES_STOCK_A_EFFACER))->delete();

            // Quantités en stock remises à zéro — conséquence logique de l'effacement de
            // l'historique des ventes/achats qui les faisait bouger.
            $scoped(VarianteStock::query())->update(['qte_stock' => 0]);

            // Compteurs de numérotation des commandes (périodes YYYY-MM) — partagés entre
            // toutes les organisations, donc uniquement remis à zéro sur une purge globale
            // (sinon on décale la numérotation d'une organisation non purgée).
            if (! $organizationId) {
                DB::table('commande_sequences')->delete();
            }

            // Écritures comptables générales : uniquement celles des événements
            // vente_facturee / encaissement_vente_recu — dépenses, fiches propriétaire/livreur
            // et paie restent intactes (compta_ecritures cascade via piece_comptable_id).
            $scoped(PieceComptable::whereIn('type_evenement', self::EVENEMENTS_COMPTA_VENTE))->delete();

            // Journal de trésorerie : uniquement les entrées sourcées vente/commission/cashback —
            // dépenses, fiches de paiement et paie restent intactes.
            $scoped(JournalTresorerie::whereIn('source_type', self::SOURCES_TRESORERIE_A_EFFACER))->delete();
        });

        $this->info('Ventes, achats, commissions et données dérivées effacés.');

        return self::SUCCESS;
    }
}
