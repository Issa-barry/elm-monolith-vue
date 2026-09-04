<?php

namespace App\Support\Commission;

use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Filtre optionnel par processus (vente/distribution_client/logistique_transfert) pour les écrans
 * de reporting Comptabilité qui interrogent CommissionEnveloppePart — jamais appliqué à la
 * machinerie de paiement/période (PeriodeCalculatorService, CommissionEnveloppePartAllocationService),
 * qui doit au contraire toujours unir tous les processus (cf. docs/commissions.md).
 *
 * Décision produit du 02/09/2026 : le filtre accepte UN ou PLUSIEURS codes à la fois (case à
 * cocher multiple côté UI, jamais un simple menu déroulant) — plusieurs codes cochés s'unissent
 * (whereIn), jamais une intersection. Aucune sélection = "Tous les processus" = valeur par défaut
 * des écrans Index (plus jamais un repli silencieux sur "vente").
 *
 * Garde volontairement les 3 codes, y compris distribution_client, alors que
 * Settings\CommissionRegleController::processusCodesDisponibles() (routage/configuration de
 * NOUVELLES opérations, depuis le 01/09/2026) n'en propose plus que 2 : ce filtre sert à
 * REPORTER des CommissionEnveloppe déjà générées, dont certaines restent historiquement
 * rattachées à distribution_client. Comptabilite\CommissionVenteController::breakdownParProcessus()
 * répartit le total déjà généré sur exactement ces options — en retirer une ferait disparaître
 * silencieusement sa part du total affiché (aucune commission perdue en base, mais une
 * réconciliation visuellement fausse). Ne jamais aligner cette liste sur processusCodesDisponibles().
 */
class CommissionProcessusFilter
{
    /** @return array<array{value:string,label:string}> */
    public static function options(): array
    {
        return [
            ['value' => CommissionProcessus::CODE_VENTE, 'label' => 'Vente'],
            ['value' => CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 'label' => 'Distribution client'],
            ['value' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 'label' => 'Transfert logistique'],
        ];
    }

    /**
     * Mêmes options que options(), avec en tête un choix "Tous les processus" (valeur vide, donc
     * sans effet dans appliquer()) — pour les fiches détail bénéficiaire, où la situation globale
     * est la valeur par défaut la plus utile (cf. docs/commissions.md).
     *
     * @return array<array{value:string,label:string}>
     */
    public static function optionsAvecTous(): array
    {
        return [
            ['value' => '', 'label' => 'Tous les processus'],
            ...self::options(),
        ];
    }

    /** Résout le libellé d'un code processus depuis options() — jamais dupliqué localement. */
    public static function labelFor(?string $processusCode): ?string
    {
        if (! $processusCode) {
            return null;
        }

        foreach (self::options() as $option) {
            if ($option['value'] === $processusCode) {
                return $option['label'];
            }
        }

        return $processusCode;
    }

    /**
     * Normalise une entrée de filtre "processus" — scalaire legacy (?processus=vente), tableau
     * (?processus[]=vente&processus[]=logistique_transfert, cocher plusieurs cases = union) ou
     * absent — en tableau de codes valides. Un code inconnu (jamais envoyé par l'UI, mais une
     * requête forgée reste possible) est silencieusement filtré, jamais transmis tel quel à une
     * requête SQL.
     *
     * @return array<int, string>
     */
    public static function normaliserCodes(array|string|null $input): array
    {
        $valides = collect(self::options())->pluck('value')->all();

        return collect(is_array($input) ? $input : [$input])
            ->filter(fn ($c) => is_string($c) && $c !== '')
            ->unique()
            ->filter(fn ($c) => in_array($c, $valides, true))
            ->values()
            ->all();
    }

    /**
     * Applique le filtre sur une requête CommissionEnveloppePart si $processusCodes est non vide —
     * sans effet sinon (vue consolidée par défaut, cf. décision produit : "les vues comptables
     * globales peuvent naturellement consolider plusieurs processus"). Plusieurs codes s'unissent
     * (whereIn, jamais une intersection) : cocher Vente + Transfert logistique doit montrer l'union
     * des deux, jamais rien ou uniquement le premier (régression du 02/09/2026 :
     * DataFilters.vue n'envoyait jusque-là que la première valeur cochée).
     */
    public static function appliquer(Builder $query, array|string|null $processusCodes): Builder
    {
        $codes = self::normaliserCodes($processusCodes);
        if (empty($codes)) {
            return $query;
        }

        return $query->whereHas('enveloppe.processus', fn ($q) => $q->whereIn('code', $codes));
    }

    /**
     * Libellés (ordre stable de options()) des processus réellement présents dans une collection
     * de CommissionEnveloppePart — alimente la colonne "Processus" du détail par bénéficiaire,
     * toujours affichée (décision produit du 02/09/2026) même quand un seul processus contribue,
     * pour que la provenance reste visible sans devoir rouvrir le filtre.
     *
     * @param  Collection<int, CommissionEnveloppePart>  $parts
     * @return array<int, string>
     */
    public static function labelsPresents(Collection $parts): array
    {
        $codesPresents = $parts->pluck('enveloppe.processus.code')->filter()->unique()->all();

        return collect(self::options())
            ->filter(fn (array $o) => in_array($o['value'], $codesPresents, true))
            ->pluck('label')
            ->values()
            ->all();
    }
}
