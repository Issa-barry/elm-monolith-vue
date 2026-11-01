<?php

namespace App\Services\Rh;

use App\Enums\AuditEvent;
use App\Enums\StatutEmploye;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PDG de l'organisation (ADR 0048) : un employé RH désigné, représentant de l'employeur et
 * signataire de tous les contrats de travail. Une seule référence par organisation
 * (`organizations.pdg_employe_id`) : jamais deux PDG à la fois. Aucun autre salarié n'est jamais
 * pris à sa place.
 */
class PdgService
{
    public const QUALITE = 'Président-directeur général';

    public const PERMISSION = 'rh-referentiels.gerer';

    public const MESSAGE_ABSENT = 'Impossible de créer ou de générer le contrat : aucun PDG n’est désigné pour cette organisation. Veuillez désigner un PDG dans le module RH (RH → Employés → fiche de la personne → « Désigner comme PDG »).';

    public function __construct(private readonly AuditLogService $audit) {}

    /** PDG en fonction : employé actif de l'organisation ; null sinon (aucun, sorti ou supprimé). */
    public function actuel(string $organizationId): ?Employe
    {
        $id = Organization::whereKey($organizationId)->value('pdg_employe_id');
        if ($id === null) {
            return null;
        }

        return Employe::with('personne')
            ->where('organization_id', $organizationId)
            ->where('statut', StatutEmploye::ACTIF->value)
            ->find($id);
    }

    public function exiger(string $organizationId, string $cle = 'pdg'): Employe
    {
        return $this->actuel($organizationId) ?? throw ValidationException::withMessages([$cle => self::MESSAGE_ABSENT]);
    }

    public static function nom(Employe $pdg): string
    {
        return trim(implode(' ', array_filter([$pdg->personne?->prenom, $pdg->personne?->nom])));
    }

    public static function peutDesigner(User $user, Employe $employe): bool
    {
        return $user->organization_id === $employe->organization_id && $user->can(self::PERMISSION);
    }

    /** Désigne le PDG ; remplace le précédent de façon explicite (journalisé). */
    public function designer(Employe $employe, User $acteur): void
    {
        if (! self::peutDesigner($acteur, $employe)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($employe, $acteur) {
            $organisation = Organization::whereKey($employe->organization_id)->lockForUpdate()->firstOrFail();
            $employe = Employe::whereKey($employe->id)->lockForUpdate()->firstOrFail();

            if ($employe->statut !== StatutEmploye::ACTIF) {
                throw ValidationException::withMessages(['pdg' => 'Seul un employé actif peut être désigné PDG.']);
            }
            if ($organisation->pdg_employe_id === $employe->id) {
                return;
            }

            $precedent = $organisation->pdg_employe_id;
            $organisation->forceFill(['pdg_employe_id' => $employe->id])->save();
            $this->audit->record($employe, AuditEvent::STATUS_CHANGED, $acteur, ['pdg_employe_id' => $precedent], ['pdg_employe_id' => $employe->id], [
                'module' => 'rh_pdg',
                'description' => 'Désigné PDG de l’organisation'.($precedent ? ' (remplace le PDG précédent)' : ''),
            ]);
        });
    }

    /** Départ ou suppression du PDG : la désignation est retirée, un successeur sera à désigner. */
    public function retirerSiPdg(Employe $employe): void
    {
        Organization::whereKey($employe->organization_id)
            ->where('pdg_employe_id', $employe->id)
            ->update(['pdg_employe_id' => null]);
    }
}
