<?php

namespace App\Services\Comptes;

use App\Enums\AuditEvent;
use App\Enums\FicheRattachable;
use App\Enums\StatutCorrespondanceCompte;
use App\Models\AuditLog;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Comptes\Data\CorrespondanceCompte;
use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rattachement automatique d'une fiche (livreur, propriétaire, parrain, consultant, interlocuteur) au
 * compte de la même personne (ADR 0056). L'identité est établie quand la fiche porte la fiche
 * Personne du compte, ou celle d'une autre activité déjà reliée à ce compte. Pour un parrain, le numéro
 * de téléphone du compte suffit aussi, sans vérification de sa possession (décision métier en
 * attendant l'OTP). Restent au rattachement manuel (RattachementCompteService) : un numéro identique
 * pour les autres fiches, plusieurs comptes possibles, un détachement fait par un responsable, un motif
 * de refus.
 */
class RattachementAutomatiqueService
{
    /** Dit dans l'audit que ce lien ne repose que sur le numéro, à réexaminer quand l'OTP existera. */
    private const PREUVE_TELEPHONE = 'Même numéro de téléphone que le compte (numéro non vérifié).';

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
            return $this->analyserParTelephone($type, $fiche);
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

        return $this->decider($type, $fiche, $compte, $preuves->first());
    }

    private function analyserParTelephone(FicheRattachable $type, Model $fiche): CorrespondanceCompte
    {
        $compte = $this->rattachement->compteParTelephone($fiche->personne?->telephone);

        if ($compte === null) {
            return new CorrespondanceCompte($type, $fiche, StatutCorrespondanceCompte::AUCUNE);
        }

        if ($type !== FicheRattachable::PARRAIN) {
            return new CorrespondanceCompte($type, $fiche, StatutCorrespondanceCompte::AMBIGUE, $compte,
                motif: 'Seul le numéro de téléphone correspond.');
        }

        if ($compte->organization_id === null && $this->numeroParrainDansUneAutreOrganisation($fiche)) {
            return new CorrespondanceCompte($type, $fiche, StatutCorrespondanceCompte::AMBIGUE, $compte,
                motif: 'Ce numéro figure sur des fiches parrain de plusieurs organisations.');
        }

        return $this->decider($type, $fiche, $compte, self::PREUVE_TELEPHONE);
    }

    private function decider(FicheRattachable $type, Model $fiche, User $compte, string $preuve): CorrespondanceCompte
    {
        $motif = $this->detacheParUnResponsable($fiche, $compte)
            ? 'Un responsable a détaché ce compte de la fiche.'
            : $this->motifRefus($type, $fiche, $compte);

        return new CorrespondanceCompte(
            $type,
            $fiche,
            $motif === null ? StatutCorrespondanceCompte::CERTAINE : StatutCorrespondanceCompte::AMBIGUE,
            $compte,
            motif: $motif,
            preuve: $preuve,
        );
    }

    /** Un compte sans organisation ne se voit jamais attribuer l'une d'elles au hasard. */
    private function numeroParrainDansUneAutreOrganisation(Model $fiche): bool
    {
        return Parrain::query()
            ->where('organization_id', '!=', $fiche->organization_id)
            ->whereHas('personne', fn (Builder $q) => $q->where('telephone_normalise', $fiche->personne?->telephone_normalise))
            ->exists();
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
        $rattachees = 0;

        foreach (FicheRattachable::cases() as $type) {
            $rattachees += $this->relier(
                $type->requete()->with('personne')->where('personne_id', $personneId)->whereNull('user_id')->get(),
                $source,
            );
        }

        return $rattachees;
    }

    /** Fiches de la Personne du compte, puis fiches parrain portant son numéro. */
    public function rattacherCompte(User $compte, string $source): int
    {
        $rattachees = $compte->personne_id !== null ? $this->rattacherPersonne((string) $compte->personne_id, $source) : 0;
        $telephone = $compte->telephone;

        if (blank($telephone)) {
            return $rattachees;
        }

        $normalise = Personne::normaliserTelephone($telephone);

        return $rattachees + $this->relier(
            FicheRattachable::PARRAIN->requete()
                ->with('personne')
                ->whereNull('user_id')
                ->whereHas('personne', fn (Builder $q) => $q->where('telephone_normalise', $normalise))
                ->get(),
            $source,
        );
    }

    /** @param  Collection<int, Model>  $fiches */
    private function relier(Collection $fiches, string $source): int
    {
        $source = self::$sourceImposee ?? $source;

        return $fiches->filter(fn (Model $fiche) => $this->appliquer($this->analyser($fiche), $source))->count();
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
