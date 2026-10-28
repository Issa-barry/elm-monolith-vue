<?php

namespace App\Services\ImportRh;

use App\Enums\AuditEvent;
use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\ImportRh;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Rh\ContratService;
use App\Services\Rh\EmployeAffectationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Applique un import RH confirmé : nouvelle analyse au moment de confirmer (jamais l'aperçu
 * enregistré), puis tout ou rien dans une seule transaction. Écritures limitées à une liste fermée :
 * date d'embauche et service (employé), fonction (via EmployeAffectationService, historisée),
 * création de contrat (via ContratService : un seul contrat actif, sous verrou). Ne crée jamais de
 * salarié, ne modifie jamais un contrat existant.
 *
 * Journal : une entrée par salarié et par contrat, source « import_rh ». Aucun montant de salaire
 * n'y est écrit (seulement « renseigné »).
 */
class ImportRhExecutor
{
    public function __construct(
        private readonly ImportRhParser $parser,
        private readonly ContratService $contrats,
        private readonly EmployeAffectationService $affectations,
        private readonly AuditLogService $audit,
    ) {}

    /** @return array{succes: bool, rapport: array, compteurs: array{employes: int, contrats: int}|null} */
    public function executer(ImportRh $import, User $acteur): array
    {
        $analyse = $this->parser->analyserFichier(Storage::disk('local')->path($import->fichier_path), $acteur);

        $erreurs = array_filter($analyse['lignes'], fn ($l) => $l['statut'] === 'erreur');
        if ($analyse['erreur_globale'] !== null || $erreurs !== []) {
            return ['succes' => false, 'rapport' => $analyse, 'compteurs' => null];
        }

        $compteurs = ['employes' => 0, 'contrats' => 0];

        DB::transaction(function () use ($analyse, $import, $acteur, &$compteurs) {
            foreach ($analyse['lignes'] as $ligne) {
                if ($ligne['statut'] === 'mise_a_jour') {
                    $this->appliquerLigne($ligne, $import, $acteur, $compteurs);
                }
            }
        });

        return ['succes' => true, 'rapport' => $analyse, 'compteurs' => $compteurs];
    }

    private function appliquerLigne(array $ligne, ImportRh $import, User $acteur, array &$compteurs): void
    {
        $orgId = $import->organization_id;
        $maj = $ligne['mise_a_jour'];

        $employe = Employe::where('organization_id', $orgId)->whereKey($ligne['employe_id'])->lockForUpdate()->firstOrFail();
        $meta = ['module' => 'import_rh', 'import_id' => $import->id];

        // Liste fermée : jamais un fill() à partir de la ligne Excel.
        $donnees = array_intersect_key($maj['employe'], array_flip(['date_embauche', 'service_rh_id']));
        $avant = ['date_embauche' => $employe->date_embauche?->toDateString(), 'service_rh_id' => $employe->service_rh_id, 'fonction_rh_id' => $employe->fonction_rh_id];

        if ($donnees !== []) {
            $employe->update($donnees);
        }

        if ($maj['fonction_rh_id'] !== null) {
            $fonction = FonctionRh::where('organization_id', $orgId)->findOrFail($maj['fonction_rh_id']);
            // Site actuel conservé : seule la fonction change, avec son historique d'affectation.
            $this->affectations->definir($employe->fresh(), $employe->site, $fonction, $acteur, 'import_rh');
        }

        if ($donnees !== [] || $maj['fonction_rh_id'] !== null) {
            $employe->refresh();
            $this->audit->record($employe, AuditEvent::UPDATED, $acteur, $avant, [
                'date_embauche' => $employe->date_embauche?->toDateString(),
                'service_rh_id' => $employe->service_rh_id,
                'fonction_rh_id' => $employe->fonction_rh_id,
            ], $meta);
        }

        if ($maj['contrat'] !== null) {
            $contrat = $this->contrats->creer([...$maj['contrat'], 'employe_id' => $employe->id], $orgId);
            $this->audit->record($contrat, AuditEvent::CREATED, $acteur, null, [
                'type_contrat' => $contrat->type_contrat->value,
                'date_debut' => $contrat->date_debut?->toDateString(),
                'date_fin' => $contrat->date_fin?->toDateString(),
                'salaire_base' => $contrat->salaire_base !== null ? 'renseigné' : null,
            ], $meta);
            $compteurs['contrats']++;
        }

        $compteurs['employes']++;
    }
}
