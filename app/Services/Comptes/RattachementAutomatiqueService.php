<?php

namespace App\Services\Comptes;

use App\Enums\AuditEvent;
use App\Enums\FicheRattachable;
use App\Enums\StatutCorrespondanceCompte;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Comptes\Data\CorrespondanceCompte;
use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rattachement automatique d'une fiche (livreur, propriétaire, parrain, consultant, interlocuteur) au
 * compte de la même personne (ADR 0056). L'identité est établie quand la fiche porte la fiche
 * Personne du compte, ou celle d'une autre activité déjà reliée à ce compte. Un téléphone identique
 * seul ne relie jamais rien : le cas reste au rattachement manuel (RattachementCompteService), comme
 * plusieurs comptes possibles, un détachement fait par un responsable ou un motif de refus.
 */
class RattachementAutomatiqueService
{
    /** Source imposée aux rattachements entraînés par l'observer pendant une opération (régularisation). */
    private static ?string $sourceImposee = null;

    public function __construct(
        private readonly RattachementCompteService $rattachement,
        private readonly AuditLogService $auditLog,
    ) {}

    public function analyser(Model $fiche): CorrespondanceCompte
    {
        $type = FicheRattachable::pour($fiche);

        if ($fiche->user_id !== null) {
            return new CorrespondanceCompte($type, $fiche, StatutCorrespondanceCompte::DEJA_LIE, $fiche->user);
        }

        $preuves = $fiche->personne_id !== null
            ? $this->comptesDeLaPersonne($fiche->personne_id, $fiche->organization_id)
            : collect();

        if ($preuves->isEmpty()) {
            $parTelephone = $this->rattachement->compteParTelephone($fiche->personne?->telephone);

            return $parTelephone === null
                ? new CorrespondanceCompte($type, $fiche, StatutCorrespondanceCompte::AUCUNE)
                : new CorrespondanceCompte($type, $fiche, StatutCorrespondanceCompte::AMBIGUE, $parTelephone,
                    motif: 'Seul le numéro de téléphone correspond, il n\'est pas vérifié.');
        }

        if ($preuves->count() > 1) {
            return new CorrespondanceCompte($type, $fiche, StatutCorrespondanceCompte::AMBIGUE,
                candidats: $preuves->keys()->map(fn ($id) => (string) $id)->values()->all(),
                motif: 'Plusieurs comptes partagent cette personne.');
        }

        $compte = User::find($preuves->keys()->first());
        if ($compte === null) {
            return new CorrespondanceCompte($type, $fiche, StatutCorrespondanceCompte::AUCUNE);
        }

        $motif = $this->detacheParUnResponsable($fiche, $compte)
            ? 'Un responsable a détaché ce compte de la fiche.'
            : $this->motifRefus($type, $fiche, $compte);

        return new CorrespondanceCompte(
            $type,
            $fiche,
            $motif === null ? StatutCorrespondanceCompte::CERTAINE : StatutCorrespondanceCompte::AMBIGUE,
            $compte,
            motif: $motif,
            preuve: $preuves->first(),
        );
    }

    /**
     * Relie la fiche si la correspondance est toujours certaine au moment de l'écriture. Sans effet
     * sinon, et sans effet une seconde fois : la fiche est alors déjà reliée.
     */
    public function appliquer(CorrespondanceCompte $correspondance, string $source): bool
    {
        if (! $correspondance->estCertaine()) {
            return false;
        }

        return DB::transaction(function () use ($correspondance, $source) {
            $compte = User::query()->lockForUpdate()->find($correspondance->compte?->id);
            $fiche = $correspondance->fiche->newQuery()->lockForUpdate()->find($correspondance->fiche->getKey());

            if ($compte === null || $fiche === null) {
                return false;
            }

            $verifiee = $this->analyser($fiche);
            if (! $verifiee->estCertaine() || $verifiee->compte?->id !== $compte->id) {
                return false;
            }

            // Sauvegarde de l'instance : BusinessProfileRoleObserver pose le rôle livreur/propriétaire.
            $fiche->user_id = $compte->id;
            $fiche->save();

            $organisationRenseignee = $compte->organization_id === null;
            if ($organisationRenseignee) {
                $compte->update(['organization_id' => $fiche->organization_id]);
            }

            $this->auditLog->record(
                $fiche,
                AuditEvent::COMPTE_RATTACHE,
                null,
                ['user_id' => null],
                ['user_id' => $compte->id],
                [
                    'automatique' => true,
                    'source' => $source,
                    'preuve' => $verifiee->preuve,
                    'compte_nom' => $compte->personne?->nom_complet,
                    'compte_telephone' => $compte->telephone,
                    'organisation_compte_renseignee' => $organisationRenseignee,
                ],
            );

            return true;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function avecSource(string $source, callable $operation): mixed
    {
        $precedente = self::$sourceImposee;
        self::$sourceImposee = $source;

        try {
            return $operation();
        } finally {
            self::$sourceImposee = $precedente;
        }
    }

    /** Relie toutes les fiches encore libres de cette personne dont la correspondance est certaine. */
    public function rattacherPersonne(string $personneId, string $source): int
    {
        $source = self::$sourceImposee ?? $source;
        $rattachees = 0;

        foreach (FicheRattachable::cases() as $type) {
            $fiches = $type->requete()->with('personne')->where('personne_id', $personneId)->whereNull('user_id')->get();

            foreach ($fiches as $fiche) {
                if ($this->appliquer($this->analyser($fiche), $source)) {
                    $rattachees++;
                }
            }
        }

        return $rattachees;
    }

    /**
     * Comptes dont l'identité recouvre cette personne, avec la preuve retenue pour chacun : la fiche
     * Personne du compte, ou celle d'une activité déjà reliée au compte dans l'organisation.
     *
     * @return Collection<string, string>
     */
    private function comptesDeLaPersonne(string $personneId, string $organizationId): Collection
    {
        $preuves = User::query()->where('personne_id', $personneId)->pluck('id')
            ->mapWithKeys(fn ($id) => [(string) $id => 'Même fiche Personne que le compte.']);

        foreach (FicheRattachable::cases() as $type) {
            $type->modele()::query()
                ->where('organization_id', $organizationId)
                ->where('personne_id', $personneId)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->each(function ($id) use ($preuves, $type) {
                    if (! $preuves->has((string) $id)) {
                        $preuves->put((string) $id, "Même fiche Personne que sa fiche {$type->libelleFiche()}, déjà reliée au compte.");
                    }
                });
        }

        return $preuves;
    }

    private function motifRefus(FicheRattachable $type, Model $fiche, User $compte): ?string
    {
        if ($type !== FicheRattachable::LIVREUR && $type !== FicheRattachable::PROPRIETAIRE) {
            return $this->rattachement->motifRefus($fiche, $compte);
        }

        // Livreur et propriétaire : mêmes refus que le rattachement à la connexion, sans condition
        // sur la fiche (un ancien livreur garde ses commissions, ADR 0055).
        if (AccountEligibility::status($compte) !== AccountStatus::Ok) {
            return "Ce compte est désactivé ou n'est pas encore activé.";
        }

        if ($compte->organization_id !== null && $compte->organization_id !== $fiche->organization_id) {
            return 'Ce compte appartient à une autre organisation.';
        }

        $autreFiche = $type->modele()::query()
            ->where('organization_id', $fiche->organization_id)
            ->where('user_id', $compte->id)
            ->whereKeyNot($fiche->getKey())
            ->exists();

        return $autreFiche ? "Ce compte est déjà relié à une autre fiche {$type->libelleFiche()}." : null;
    }

    private function detacheParUnResponsable(Model $fiche, User $compte): bool
    {
        return AuditLog::query()
            ->where('auditable_type', $fiche->getMorphClass())
            ->where('auditable_id', $fiche->getKey())
            ->where('event_code', AuditEvent::COMPTE_DETACHE->value)
            ->get(['old_values'])
            ->contains(fn (AuditLog $log) => ($log->old_values['user_id'] ?? null) === $compte->id);
    }
}
