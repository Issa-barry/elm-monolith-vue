<?php

namespace App\Services\Comptes;

use App\Enums\AuditEvent;
use App\Enums\PrestataireType;
use App\Models\EntrepriseInterlocuteur;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\AuditLogService;
use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seul point qui relie un compte à une fiche parrain, consultant ou interlocuteur (ADR 0050).
 * Le rattachement est toujours une décision d'un responsable : l'inscription ne prouve pas la
 * possession du numéro, un téléphone identique sert donc à proposer un compte, jamais à le relier.
 * Aucune fiche Personne ni aucun compte n'est fusionné ou supprimé.
 */
class RattachementCompteService
{
    private const ROLES_ESPACE_CLIENT = ['client', 'proprietaire', 'livreur'];

    public function __construct(private readonly AuditLogService $auditLog) {}

    /** Compte dont le numéro correspond, à vérifier par le responsable avant tout rattachement. */
    public function compteParTelephone(?string $telephone): ?User
    {
        if (blank($telephone)) {
            return null;
        }

        return UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($telephone));
    }

    /**
     * Informations qui permettent au responsable de vérifier le compte, avec le motif qui
     * empêcherait le rattachement. Le compte d'une autre organisation ne montre que ce motif : son
     * identité ne doit jamais sortir de son organisation.
     *
     * @return array{id: ?string, nom: ?string, telephone: ?string, email: ?string, inscrit_le: ?string, roles: list<string>, motif_refus: ?string}
     */
    public function apercu(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, User $compte): array
    {
        if ($this->autreOrganisation($fiche, $compte)) {
            return [
                'id' => null,
                'nom' => null,
                'telephone' => null,
                'email' => null,
                'inscrit_le' => null,
                'roles' => [],
                'motif_refus' => $this->motifRefus($fiche, $compte),
            ];
        }

        return [
            'id' => $compte->id,
            'nom' => $compte->personne?->nom_complet ?: null,
            'telephone' => $compte->telephone,
            'email' => $compte->email,
            'inscrit_le' => $compte->created_at?->toIso8601String(),
            'roles' => $compte->getRoleNames()->values()->all(),
            'motif_refus' => $this->motifRefus($fiche, $compte),
        ];
    }

    public function motifRefus(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, User $compte): ?string
    {
        return $this->motifRefusFiche($fiche, $compte) ?? $this->motifRefusCompte($fiche, $compte);
    }

    /**
     * @throws ValidationException si le rattachement est refusé
     */
    public function rattacher(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, User $compte, User $acteur): void
    {
        $this->assertMemeOrganisation($fiche, $acteur);

        DB::transaction(function () use ($fiche, $compte, $acteur) {
            // Le verrou sur le compte empêche deux rattachements simultanés du même compte, que
            // la base ne bloque pas : `user_id` n'est pas unique (plusieurs interlocuteurs possibles).
            $compteVerrouille = User::query()->lockForUpdate()->findOrFail($compte->getKey());
            $ficheVerrouillee = $fiche->newQuery()->lockForUpdate()->findOrFail($fiche->getKey());

            // Double clic : déjà relié à ce compte, rien à refaire ni à tracer une seconde fois.
            if ($ficheVerrouillee->user_id === $compteVerrouille->id) {
                return;
            }

            $motif = $this->motifRefus($ficheVerrouillee, $compteVerrouille);
            if ($motif !== null) {
                throw ValidationException::withMessages(['compte' => $motif]);
            }

            $ficheVerrouillee->forceFill(['user_id' => $compteVerrouille->id])->save();

            // Un compte auto-inscrit n'a pas encore d'organisation : ses données y seraient introuvables.
            $organisationRenseignee = $compteVerrouille->organization_id === null;
            if ($organisationRenseignee) {
                $compteVerrouille->update(['organization_id' => $ficheVerrouillee->organization_id]);
            }

            $this->auditLog->record(
                $ficheVerrouillee,
                AuditEvent::COMPTE_RATTACHE,
                $acteur,
                ['user_id' => null],
                ['user_id' => $compteVerrouille->id],
                [
                    'compte_nom' => $compteVerrouille->personne?->nom_complet,
                    'compte_telephone' => $compteVerrouille->telephone,
                    'organisation_compte_renseignee' => $organisationRenseignee,
                ],
            );
        });

        $fiche->refresh();
    }

    /** Coupe l'accès sans toucher au compte, qui garde ses autres activités et son organisation. */
    public function detacher(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, User $acteur): void
    {
        $this->assertMemeOrganisation($fiche, $acteur);

        DB::transaction(function () use ($fiche, $acteur) {
            $ficheVerrouillee = $fiche->newQuery()->lockForUpdate()->findOrFail($fiche->getKey());
            $ancienCompteId = $ficheVerrouillee->user_id;

            if ($ancienCompteId === null) {
                return;
            }

            $ficheVerrouillee->forceFill(['user_id' => null])->save();

            $ancienCompte = User::find($ancienCompteId);

            $this->auditLog->record(
                $ficheVerrouillee,
                AuditEvent::COMPTE_DETACHE,
                $acteur,
                ['user_id' => $ancienCompteId],
                ['user_id' => null],
                [
                    'compte_nom' => $ancienCompte?->personne?->nom_complet,
                    'compte_telephone' => $ancienCompte?->telephone,
                ],
            );
        });

        $fiche->refresh();
    }

    private function motifRefusFiche(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, User $compte): ?string
    {
        if ($fiche->user_id !== null && $fiche->user_id !== $compte->id) {
            return 'Cette fiche est déjà rattachée à un autre compte.';
        }

        if (! $fiche->is_active) {
            return 'Cette fiche est désactivée.';
        }

        if ($fiche instanceof Prestataire) {
            if ($fiche->entreprise_tierce_id !== null) {
                return 'Une entreprise se rattache par ses interlocuteurs.';
            }

            if ($fiche->type !== PrestataireType::CONSULTANT) {
                return 'Seul un prestataire consultant peut être rattaché à un compte.';
            }
        }

        if ($fiche instanceof EntrepriseInterlocuteur && ! $fiche->entrepriseTierce?->is_active) {
            return "L'entreprise de cet interlocuteur est désactivée.";
        }

        return null;
    }

    private function motifRefusCompte(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, User $compte): ?string
    {
        if (AccountEligibility::status($compte) !== AccountStatus::Ok) {
            return "Ce compte est désactivé ou n'est pas encore activé.";
        }

        if ($this->autreOrganisation($fiche, $compte)) {
            return 'Ce compte appartient à une autre organisation.';
        }

        if (! $compte->hasAnyRole(self::ROLES_ESPACE_CLIENT)) {
            return "Ce compte n'a pas accès à l'espace client.";
        }

        return match (true) {
            $fiche instanceof Parrain => $this->autreFicheParrain($fiche, $compte)
                ? 'Ce compte est déjà rattaché à un autre parrain.'
                : null,
            $fiche instanceof Prestataire => $this->autreFicheConsultant($fiche, $compte)
                ? 'Ce compte est déjà rattaché à un autre consultant.'
                : null,
            default => $this->autreInterlocuteurMemeEntreprise($fiche, $compte)
                ? 'Ce compte est déjà interlocuteur de cette entreprise.'
                : null,
        };
    }

    private function autreOrganisation(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, User $compte): bool
    {
        return $compte->organization_id !== null && $compte->organization_id !== $fiche->organization_id;
    }

    private function autreFicheParrain(Parrain $fiche, User $compte): bool
    {
        return Parrain::where('organization_id', $fiche->organization_id)
            ->where('user_id', $compte->id)
            ->whereKeyNot($fiche->getKey())
            ->exists();
    }

    private function autreFicheConsultant(Prestataire $fiche, User $compte): bool
    {
        return Prestataire::where('organization_id', $fiche->organization_id)
            ->where('user_id', $compte->id)
            ->whereKeyNot($fiche->getKey())
            ->exists();
    }

    private function autreInterlocuteurMemeEntreprise(EntrepriseInterlocuteur $fiche, User $compte): bool
    {
        return EntrepriseInterlocuteur::where('entreprise_tierce_id', $fiche->entreprise_tierce_id)
            ->where('user_id', $compte->id)
            ->whereKeyNot($fiche->getKey())
            ->exists();
    }

    private function assertMemeOrganisation(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, User $acteur): void
    {
        abort_unless($acteur->organization_id === $fiche->organization_id, 403);
    }
}
