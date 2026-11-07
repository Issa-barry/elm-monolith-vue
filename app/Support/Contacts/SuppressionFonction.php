<?php

namespace App\Support\Contacts;

use App\Models\Client;
use App\Models\Fournisseur;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Décision D17 (ADR 0060, lot 3, CONTACT-019) : une fonction qu'une opération référence ne se
 * supprime plus, elle se désactive. Sans cela, sa suppression (logique) faisait disparaître le nom
 * du bénéficiaire des historiques qui n'en gardent pas de copie (ventes, dépenses, parts de
 * commission, emballages...). Les références comptent aussi les lignes supprimées logiquement : ce
 * sont encore des historiques.
 *
 * Références recensées par l'audit du lot 0 (section E1).
 */
final class SuppressionFonction
{
    /**
     * @return list<array{0: string, 1: string, 2?: array<string, string>}> [table, colonne, conditions]
     */
    private static function references(string $classe): array
    {
        return match ($classe) {
            Client::class => [
                ['commandes_ventes', 'client_id'],
                ['cashback_transactions', 'client_id'],
                ['propositions_vehicules', 'client_id'],
            ],
            Fournisseur::class => [
                ['produits', 'fournisseur_id'],
                ['commandes_achats', 'fournisseur_id'],
                ['factures_fournisseurs', 'fournisseur_id'],
                ['paiements_fournisseurs', 'fournisseur_id'],
            ],
            Prestataire::class => [
                ['packings', 'prestataire_id'],
                ['commission_consultant_affectations', 'prestataire_id'],
                ['commission_regles', 'consultant_id'],
                ['depenses', 'beneficiaire_id', ['beneficiaire_type' => 'prestataire']],
                ['commission_enveloppe_parts', 'beneficiaire_id', ['beneficiaire_type' => 'prestataire']],
                ['paiement_fiches', 'beneficiaire_id', ['beneficiaire_type' => 'prestataire']],
            ],
            Proprietaire::class => [
                ['vehicules', 'proprietaire_id'],
                ['equipes_livraison', 'proprietaire_id'],
                ['commission_logistique_parts', 'proprietaire_id'],
                ['commission_payments', 'proprietaire_id'],
                ['propositions_vehicules', 'proprietaire_id'],
                ['depenses', 'beneficiaire_id', ['beneficiaire_type' => 'proprietaire']],
                ['commission_enveloppe_parts', 'beneficiaire_id', ['beneficiaire_type' => 'proprietaire']],
                ['paiement_fiches', 'beneficiaire_id', ['beneficiaire_type' => 'proprietaire']],
            ],
        };
    }

    public static function estSupprimable(Model $fonction): bool
    {
        return self::idsReferences($fonction::class, [$fonction->getKey()]) === [];
    }

    /**
     * Ids, parmi ceux donnés, qu'au moins une opération référence (une requête par table, pas une
     * par fiche : utilisable pour toute une liste).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function idsReferences(string $classe, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $references = [];
        foreach (self::references($classe) as $reference) {
            [$table, $colonne] = $reference;
            $references[] = DB::table($table)
                ->whereIn($colonne, $ids)
                ->when($reference[2] ?? [], fn (Builder $q, array $conditions) => $q->where($conditions))
                ->distinct()
                ->pluck($colonne)
                ->all();
        }

        // Compte auxiliaire : sa présence signale des écritures (le type stocké est celui que
        // TiersComptable::resoudrePour() écrit, jamais un alias ajouté ici).
        $references[] = DB::table('compta_tiers')
            ->where('tiersable_type', (new $classe)->getMorphClass())
            ->whereIn('tiersable_id', $ids)
            ->pluck('tiersable_id')
            ->all();

        return array_values(array_unique(array_merge(...$references)));
    }

    public static function message(Model $fonction): string
    {
        $libelle = match ($fonction::class) {
            Client::class => 'Ce client',
            Fournisseur::class => 'Ce fournisseur',
            Prestataire::class => 'Ce prestataire',
            Proprietaire::class => 'Ce propriétaire',
            default => 'Cette fiche',
        };

        return "{$libelle} a un historique (opérations enregistrées) : il ne peut pas être supprimé. Désactivez-le pour qu'il ne soit plus proposé ; son historique reste consultable.";
    }
}
