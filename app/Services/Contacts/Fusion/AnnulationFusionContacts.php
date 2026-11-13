<?php

namespace App\Services\Contacts\Fusion;

use App\Enums\AuditEvent;
use App\Models\ContactsFusion;
use App\Models\ContactsFusionOperation;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Annulation d'une fusion (lot 9b, décision 7 et précision 3) : sans délai, par relecture du journal
 * à rebours. Une valeur n'est remise que si elle est encore celle que la fusion a écrite ; sinon
 * elle n'est jamais écrasée et reste à traiter par un responsable. Un rôle ajouté au compte n'est
 * jamais retiré automatiquement. Si la fiche absorbée n'est plus archivée par cette fusion, rien
 * n'est restauré.
 */
final class AnnulationFusionContacts
{
    private const TABLES_FICHES = ['personnes', 'entreprises_tierces'];

    private const COLONNES_NUMERO = ['telephone_normalise', 'telephone_secondaire_normalise'];

    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * Ce que l'annulation restaurerait, et ce qui demanderait une intervention. Aucune écriture.
     *
     * @return array{possible: bool, motif: ?string, restaurables: list<array<string, mixed>>, interventions: list<array<string, mixed>>}
     */
    public function analyser(ContactsFusion $fusion, User $acteur): array
    {
        $this->assertOrganisation($fusion, $acteur);

        if ($fusion->statut !== ContactsFusion::EXECUTEE) {
            return ['possible' => false, 'motif' => 'Cette fusion a déjà été annulée.', 'restaurables' => [], 'interventions' => []];
        }

        $table = ReferencesFusion::tableFiche($fusion->type);
        $absorbee = DB::table($table)->where('id', $fusion->fiche_absorbee_id)->first();
        if ($absorbee === null || $absorbee->deleted_at === null || $absorbee->fusionnee_dans_id !== $fusion->fiche_conservee_id) {
            return [
                'possible' => false,
                'motif' => 'La fiche absorbée n\'est plus archivée par cette fusion (restaurée ou modifiée depuis) : rien ne peut être restauré automatiquement.',
                'restaurables' => [],
                'interventions' => [],
            ];
        }

        $restaurables = [];
        $interventions = [];
        // Valeurs que l'annulation aura écrites avant d'examiner l'opération suivante.
        $prevu = [];

        foreach ($fusion->operations()->get()->reverse() as $operation) {
            if ($operation->nature === ContactsFusionOperation::LAISSE) {
                continue;
            }

            $motif = $this->motifConflit($operation, $prevu);
            $ligne = ['id' => $operation->id, 'libelle' => $operation->libelle, 'motif' => $motif];
            if ($motif === null) {
                $restaurables[] = $ligne;
                $prevu["{$operation->table_cible}|{$operation->cible_id}|{$operation->champ}"] = $operation->valeur_avant;
            } else {
                $interventions[] = $ligne;
            }
        }

        return ['possible' => true, 'motif' => null, 'restaurables' => $restaurables, 'interventions' => $interventions];
    }

    public function annuler(ContactsFusion $fusion, User $acteur): ContactsFusion
    {
        $this->assertOrganisation($fusion, $acteur);

        return DB::transaction(function () use ($fusion, $acteur) {
            $fusion = ContactsFusion::whereKey($fusion->id)->lockForUpdate()->firstOrFail();
            $table = ReferencesFusion::tableFiche($fusion->type);
            DB::table($table)->whereIn('id', [$fusion->fiche_conservee_id, $fusion->fiche_absorbee_id])->orderBy('id')->lockForUpdate()->get();

            $analyse = $this->analyser($fusion, $acteur);
            if (! $analyse['possible']) {
                throw ValidationException::withMessages(['annulation' => $analyse['motif']]);
            }

            $operations = $fusion->operations()->get()->keyBy('id');
            $restaurees = 0;
            $interventions = $analyse['interventions'];

            foreach ($analyse['restaurables'] as $ligne) {
                $operation = $operations[$ligne['id']];
                try {
                    // Point de sauvegarde : un conflit d'unicité n'annule que cette valeur.
                    DB::transaction(fn () => DB::table($operation->table_cible)->where('id', $operation->cible_id)->update([$operation->champ => $operation->valeur_avant]));
                    $operation->update(['annulation_statut' => ContactsFusionOperation::RESTAUREE]);
                    $restaurees++;
                } catch (UniqueConstraintViolationException) {
                    $interventions[] = [...$ligne, 'motif' => 'Valeur déjà utilisée par une autre fiche.'];
                }
            }

            $idsConflit = array_column($interventions, 'id');
            ContactsFusionOperation::whereIn('id', $idsConflit)->update(['annulation_statut' => ContactsFusionOperation::CONFLIT]);

            $fusion->update([
                'statut' => $interventions === [] ? ContactsFusion::ANNULEE : ContactsFusion::ANNULEE_PARTIELLEMENT,
                'annulee_le' => now(),
                'annulee_par' => $acteur->id,
                'annulation' => ['restaurees' => $restaurees, 'interventions' => array_values($interventions)],
            ]);

            $this->audit->record($fusion, AuditEvent::CANCELLED, $acteur, null, null, [
                'origine' => 'fusion', 'restaurees' => $restaurees, 'interventions' => count($interventions),
            ]);

            return $fusion;
        });
    }

    /** Motif pour lequel cette valeur ne peut pas être remise, ou null si elle peut l'être. */
    private function motifConflit(ContactsFusionOperation $operation, array $prevu): ?string
    {
        if ($operation->table_cible === 'model_has_roles') {
            return 'Rôle ajouté au compte : jamais retiré automatiquement, à retirer par un responsable si nécessaire.';
        }

        $ligne = DB::table($operation->table_cible)->where('id', $operation->cible_id)->first();
        if ($ligne === null) {
            return 'Élément introuvable depuis la fusion.';
        }
        if (! FusionContacts::egales($ligne->{$operation->champ} ?? null, $operation->valeur_apres)) {
            return 'Modifié depuis la fusion : non écrasé.';
        }

        // Un numéro remis sur une fiche doit être libre à ce moment-là : principal d'aucune autre fiche
        // (unicité par organisation, archivées comprises), ni secondaire d'une fiche active (CONTACT-063).
        if (in_array($operation->champ, self::COLONNES_NUMERO, true) && $operation->valeur_avant !== null && in_array($operation->table_cible, self::TABLES_FICHES, true)) {
            $occupants = DB::table($operation->table_cible)
                ->where('organization_id', $ligne->organization_id)
                ->where('id', '!=', $operation->cible_id)
                ->where(fn ($q) => $q->where('telephone_normalise', $operation->valeur_avant)
                    ->orWhere(fn ($s) => $s->where('telephone_secondaire_normalise', $operation->valeur_avant)->whereNull('deleted_at')))
                ->get(['id', 'telephone_normalise', 'telephone_secondaire_normalise', 'deleted_at']);
            foreach ($occupants as $occupant) {
                foreach (self::COLONNES_NUMERO as $colonne) {
                    $occupe = FusionContacts::egales($occupant->{$colonne}, $operation->valeur_avant)
                        && ($colonne === 'telephone_normalise' || $occupant->deleted_at === null);
                    if ($occupe && ! $this->seraLibere($operation->table_cible, $occupant->id, $colonne, $operation->valeur_avant, $prevu)) {
                        return 'Numéro repris depuis par une autre fiche.';
                    }
                }
            }
        }

        return null;
    }

    /** L'annulation aura-t-elle déjà remplacé cette valeur avant l'opération examinée ? */
    private function seraLibere(string $table, string $id, string $colonne, string $valeur, array $prevu): bool
    {
        $cle = "{$table}|{$id}|{$colonne}";

        return array_key_exists($cle, $prevu) && ! FusionContacts::egales($prevu[$cle], $valeur);
    }

    private function assertOrganisation(ContactsFusion $fusion, User $acteur): void
    {
        if ($fusion->organization_id !== $acteur->organization_id) {
            throw new NotFoundHttpException;
        }
    }
}
