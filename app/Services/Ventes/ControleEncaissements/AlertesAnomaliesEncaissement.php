<?php

namespace App\Services\Ventes\ControleEncaissements;

use App\Models\AnomalieEncaissement;
use App\Models\AnomalieEncaissementNotification as Envoi;
use App\Models\User;
use App\Notifications\AnomalieEncaissementNotification;
use App\Notifications\AnomaliesEncaissementEmailNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications du contrôle des encaissements (ADR 0036).
 *
 * Destinataires : l'agent ou les agents qui ont encaissé, puis les responsables — utilisateurs
 * actifs de l'organisation ayant `encaissements.surveiller` (rôle super administrateur compris)
 * et voyant l'agence de l'anomalie. Aucun nom de rôle personnalisé n'est codé : « Administrateur
 * supérieur », « Assistante de direction »… reçoivent la permission dans Rôles.
 *
 * Chaque envoi (anomalie × destinataire × canal × vague) est une ligne d'`anomalie_encaissement_
 * notifications`, unique : jamais deux fois la même alerte. Application : envoyée tout de suite
 * (écriture en base, rapide). E-mail : « en attente », puis envoyé hors de l'opération qui l'a
 * déclenché (après la réponse HTTP ou en fin de contrôle), regroupé par destinataire ; un échec
 * (SMTP indisponible, quota atteint) reste « echec » et est retenté au contrôle suivant.
 */
class AlertesAnomaliesEncaissement
{
    private const VERROU_EMAILS = 'controle-encaissements:emails';

    /** @return Collection<int, User> */
    public function responsables(AnomalieEncaissement $anomalie): Collection
    {
        return User::query()
            ->where('organization_id', $anomalie->organization_id)
            ->with(['roles', 'permissions', 'sites'])
            ->get()
            ->filter(fn (User $u) => $u->is_active !== false
                && ($u->hasRole('super_admin') || $u->checkPermissionTo('encaissements.surveiller'))
                && $this->voitAgence($u, $anomalie->site_id))
            ->values();
    }

    /** @return Collection<int, User> */
    public function agents(AnomalieEncaissement $anomalie): Collection
    {
        $ids = array_values(array_filter((array) $anomalie->agent_ids));
        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->where('organization_id', $anomalie->organization_id)
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (User $u) => $u->is_active !== false)
            ->values();
    }

    public function voitAgence(User $user, ?string $siteId): bool
    {
        if ($siteId === null || $user->voitToutesLesAgences()) {
            return true;
        }

        return $user->sites->contains('id', $siteId);
    }

    /** Détection (ou réapparition) : agents + responsables. */
    public function alerterDetection(AnomalieEncaissement $anomalie): void
    {
        $this->preparer($anomalie, 'detection:'.$anomalie->occurrence, $this->agents($anomalie)->merge($this->responsables($anomalie)), false);
    }

    /** Relance d'une anomalie restée « Nouvelle » : responsables seulement. */
    public function alerterRelance(AnomalieEncaissement $anomalie): void
    {
        $this->preparer($anomalie, 'relance:'.$anomalie->occurrence.':'.$anomalie->nb_relances, $this->responsables($anomalie), true);
    }

    /** @param  Collection<int, User>  $destinataires */
    private function preparer(AnomalieEncaissement $anomalie, string $envoi, Collection $destinataires, bool $relance): void
    {
        foreach ($destinataires->unique('id') as $user) {
            $application = $this->enregistrer($anomalie, $user, Envoi::CANAL_APPLICATION, $envoi);
            if ($application?->wasRecentlyCreated) {
                $this->envoyerApplication($application, $anomalie, $user, $relance);
            }
            if ($anomalie->gravite->parEmail()) {
                $this->enregistrer($anomalie, $user, Envoi::CANAL_EMAIL, $envoi);
            }
        }
    }

    private function enregistrer(AnomalieEncaissement $anomalie, User $user, string $canal, string $envoi): ?Envoi
    {
        try {
            return Envoi::firstOrCreate(
                ['anomalie_encaissement_id' => $anomalie->id, 'user_id' => $user->id, 'canal' => $canal, 'envoi' => $envoi],
                ['statut' => Envoi::EN_ATTENTE],
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function envoyerApplication(Envoi $ligne, AnomalieEncaissement $anomalie, User $user, bool $relance): void
    {
        try {
            $user->notify(new AnomalieEncaissementNotification($anomalie, $relance));
            $ligne->update(['statut' => Envoi::ENVOYEE, 'envoyee_le' => now(), 'tentatives' => $ligne->tentatives + 1]);
        } catch (Throwable $e) {
            $ligne->update(['statut' => Envoi::ECHEC, 'tentatives' => $ligne->tentatives + 1, 'derniere_erreur' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::error('Contrôle des encaissements : alerte application non enregistrée', ['anomalie_id' => $anomalie->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Envoie les alertes en attente ou en échec (moins de MAX_TENTATIVES) : un e-mail par
     * destinataire. Jamais d'exception : un échec est enregistré sur les lignes concernées.
     * Un seul envoi à la fois (verrou) : deux déclenchements simultanés ne doublent aucun e-mail.
     *
     * @param  list<string>|null  $anomalieIds  limite l'envoi à ces anomalies (contrôle immédiat)
     * @return array{envoyes: int, echecs: int}
     */
    public function envoyerEnAttente(?array $anomalieIds = null): array
    {
        $bilan = ['envoyes' => 0, 'echecs' => 0];

        $lock = Cache::lock(self::VERROU_EMAILS, 300);
        if (! $lock->get()) {
            return $bilan;
        }

        try {
            $this->retenterApplication($anomalieIds);

            $lignes = Envoi::query()
                ->where('canal', Envoi::CANAL_EMAIL)
                ->whereIn('statut', [Envoi::EN_ATTENTE, Envoi::ECHEC])
                ->where('tentatives', '<', Envoi::MAX_TENTATIVES)
                ->when($anomalieIds !== null, fn ($q) => $q->whereIn('anomalie_encaissement_id', $anomalieIds))
                ->with(['user', 'anomalie.site:id,nom'])
                ->get();

            foreach ($lignes->groupBy('user_id') as $lignesUser) {
                $user = $lignesUser->first()->user;
                $anomalies = $lignesUser->pluck('anomalie')->filter()->unique('id')->values();
                if (! $user || $anomalies->isEmpty()) {
                    continue;
                }

                try {
                    $user->notify(new AnomaliesEncaissementEmailNotification($anomalies));
                    Envoi::whereIn('id', $lignesUser->pluck('id'))->update(['statut' => Envoi::ENVOYEE, 'envoyee_le' => now(), 'derniere_erreur' => null]);
                    Envoi::whereIn('id', $lignesUser->pluck('id'))->increment('tentatives');
                    $bilan['envoyes']++;
                } catch (Throwable $e) {
                    Envoi::whereIn('id', $lignesUser->pluck('id'))->update(['statut' => Envoi::ECHEC, 'derniere_erreur' => mb_substr($e->getMessage(), 0, 1000)]);
                    Envoi::whereIn('id', $lignesUser->pluck('id'))->increment('tentatives');
                    $bilan['echecs']++;
                    Log::error('Contrôle des encaissements : e-mail non envoyé', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            $lock->release();
        }

        return $bilan;
    }

    /** @param  list<string>|null  $anomalieIds */
    private function retenterApplication(?array $anomalieIds): void
    {
        Envoi::query()
            ->where('canal', Envoi::CANAL_APPLICATION)
            ->where('statut', '!=', Envoi::ENVOYEE)
            ->where('tentatives', '<', Envoi::MAX_TENTATIVES)
            ->when($anomalieIds !== null, fn ($q) => $q->whereIn('anomalie_encaissement_id', $anomalieIds))
            ->with(['user', 'anomalie'])
            ->get()
            ->each(function (Envoi $ligne) {
                if ($ligne->user && $ligne->anomalie) {
                    $this->envoyerApplication($ligne, $ligne->anomalie, $ligne->user, str_starts_with($ligne->envoi, 'relance'));
                }
            });
    }
}
