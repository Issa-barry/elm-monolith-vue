<?php

namespace App\Support\Parrains;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Enums\StatutFichePaiement;
use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\CommissionEnveloppePart;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\Parrain;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Commission\PartParrainService;
use App\Services\CommissionStatusResolver;
use App\Services\ModuleService;
use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Données de la liste et de la fiche des parrains au back-office. Aucun calcul de commission ici :
 * les montants sont lus sur les fiches de paiement existantes (PARRAIN-017), avec le même périmètre
 * d'agences et le même droit que l'écran Comptabilité → Commissions → Parrains.
 */
final class FicheParrainData
{
    private const NB_FICHES = 24;

    private const NB_PAIEMENTS = 20;

    private const NB_HISTORIQUE = 20;

    public function peutVoirCommissions(User $user): bool
    {
        return $user->can('viewAny', PaiementFiche::class)
            && ModuleService::isActive(ModuleFeature::COMPTABILITE, $user->organization);
    }

    /** Hors vision toutes agences : uniquement les véhicules des agences de l'utilisateur. */
    public function limiterVehicules(Builder $query, User $user): Builder
    {
        return $user->voitToutesLesAgences()
            ? $query
            : $query->whereIn('site_id', $user->sites()->pluck('sites.id')->all());
    }

    /**
     * @param  Collection<int, Parrain>  $parrains
     * @return list<array<string, mixed>>
     */
    public function liste(Collection $parrains, User $user): array
    {
        $restes = $this->peutVoirCommissions($user) ? $this->restesAPayer($parrains, $user) : null;

        return $parrains->map(fn (Parrain $p) => [
            ...$this->identite($p),
            'vehicules_count' => $p->vehicules_count,
            'compte' => $p->user ? [
                'nom' => $p->user->personne?->nom_complet ?: null,
                'telephone' => $p->user->telephone,
            ] : null,
            'reste_a_payer' => $restes === null ? null : (float) ($restes[$p->id] ?? 0),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function fiche(Parrain $parrain, User $user): array
    {
        $parrain->load(['personne', 'user.personne']);

        return [
            'parrain' => $this->identite($parrain),
            'compte' => $this->compte($parrain),
            'historique_compte' => $this->historiqueCompte($parrain),
            'vehicules' => $this->vehicules($parrain, $user),
            'commissions' => $this->peutVoirCommissions($user) ? $this->commissions($parrain, $user) : null,
            'perimetre_limite' => ! $user->voitToutesLesAgences(),
        ];
    }

    /** @return array<string, mixed> */
    private function identite(Parrain $parrain): array
    {
        return [
            'id' => $parrain->id,
            'nom_complet' => $parrain->nom_complet,
            'surnom' => $parrain->surnom,
            'telephone' => $parrain->telephone,
            'code_phone_pays' => $parrain->code_phone_pays,
            'email' => $parrain->email,
            'ville' => $parrain->ville,
            'pays' => $parrain->pays,
            'adresse' => $parrain->adresse,
            'is_active' => (bool) $parrain->is_active,
            'cree_le' => $parrain->created_at?->format('d/m/Y'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function compte(Parrain $parrain): ?array
    {
        $compte = $parrain->user;
        if ($compte === null) {
            return null;
        }

        return [
            'id' => $compte->id,
            'nom' => $compte->personne?->nom_complet ?: null,
            'telephone' => $compte->telephone,
            'email' => $compte->email,
            'inscrit_le' => $compte->created_at?->format('d/m/Y'),
            'roles' => $compte->getRoleNames()->values()->all(),
            'actif' => AccountEligibility::status($compte) === AccountStatus::Ok,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function historiqueCompte(Parrain $parrain): array
    {
        return AuditLog::query()
            ->where('organization_id', $parrain->organization_id)
            ->where('auditable_type', $parrain->getMorphClass())
            ->where('auditable_id', $parrain->id)
            ->whereIn('event_code', [AuditEvent::COMPTE_RATTACHE->value, AuditEvent::COMPTE_DETACHE->value])
            ->latest('created_at')
            ->limit(self::NB_HISTORIQUE)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'evenement' => $log->event_label,
                'date' => $log->created_at?->format('d/m/Y H:i'),
                'auteur' => $log->actor_name_snapshot,
                'compte_nom' => $log->meta['compte_nom'] ?? null,
                'compte_telephone' => $log->meta['compte_telephone'] ?? null,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function vehicules(Parrain $parrain, User $user): array
    {
        $vehicules = $this->limiterVehicules(
            Vehicule::query()->with('site')->where('parrain_id', $parrain->id),
            $user,
        )->orderBy('nom_vehicule')->get();

        $parts = PartParrainService::statuts($vehicules);
        $peutOuvrir = $user->can('vehicules.read');

        return $vehicules->map(fn (Vehicule $v) => [
            'id' => $v->id,
            'nom' => $v->nom_vehicule,
            'immatriculation' => $v->immatriculation,
            'site_nom' => $v->site?->nom,
            'is_active' => (bool) $v->is_active,
            'part' => $parts[$v->id] ?? null,
            'url' => $peutOuvrir ? route('vehicules.show', ['vehicule' => $v->id, 'tab' => 'parrain']) : null,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function commissions(Parrain $parrain, User $user): array
    {
        $fiches = $this->fichesVisibles($user)
            ->where('beneficiaire_id', $parrain->id)
            ->with(['periode', 'site'])
            ->orderByDesc('created_at')
            ->get();

        return [
            'total_net' => (float) $fiches->sum('montant_net'),
            'total_paye' => (float) $fiches->sum('montant_paye'),
            'total_restant' => (float) $fiches->sum(fn (PaiementFiche $f) => $f->montant_restant),
            'nb_fiches' => $fiches->count(),
            'fiches' => $fiches->take(self::NB_FICHES)->map(function (PaiementFiche $f) {
                $statut = CommissionStatusResolver::resolve($f->periode, null, $f->statut?->value ?? '', $f->statut?->label() ?? '');

                return [
                    'id' => $f->id,
                    'reference' => $f->reference,
                    'periode' => $f->periode?->reference,
                    'site_nom' => $f->site?->nom,
                    'montant_net' => (float) $f->montant_net,
                    'montant_paye' => (float) $f->montant_paye,
                    'montant_restant' => $f->montant_restant,
                    'display_status' => $statut['display_status'],
                    'display_label' => $statut['display_label'],
                    'url' => route('comptabilite.fiches.show', $f->id),
                ];
            })->values()->all(),
            'paiements' => PaiementFichePaiement::query()
                ->with(['fiche', 'compteTresorerie'])
                ->whereIn('fiche_id', $fiches->pluck('id'))
                ->orderByDesc('date_paiement')
                ->orderByDesc('created_at')
                ->limit(self::NB_PAIEMENTS)
                ->get()
                ->map(fn (PaiementFichePaiement $p) => [
                    'id' => $p->id,
                    'date' => $p->date_paiement?->format('d/m/Y'),
                    'montant' => (float) $p->montant,
                    'mode_paiement' => ModePaiement::tryFrom((string) $p->mode_paiement)?->label() ?? $p->mode_paiement,
                    'reference_paiement' => $p->reference_paiement,
                    'support' => $p->compteTresorerie?->libelle,
                    'fiche_reference' => $p->fiche?->reference,
                ])
                ->values()
                ->all(),
            'toutes_les_fiches_url' => route('comptabilite.fiches.parrains', ['search' => $parrain->nom_complet]),
        ];
    }

    /**
     * Reste à payer par parrain : montant_restant reste calculé par la fiche (ADR 0043), jamais
     * recopié ici.
     *
     * @param  Collection<int, Parrain>  $parrains
     * @return array<string, float>
     */
    private function restesAPayer(Collection $parrains, User $user): array
    {
        return $this->fichesVisibles($user)
            ->whereIn('beneficiaire_id', $parrains->pluck('id'))
            ->where('statut', '!=', StatutFichePaiement::PAYE->value)
            ->get()
            ->groupBy('beneficiaire_id')
            ->map(fn (Collection $fiches) => (float) $fiches->sum(fn (PaiementFiche $f) => $f->montant_restant))
            ->all();
    }

    /** Même périmètre que PaiementFicheController::buildQuery(). */
    private function fichesVisibles(User $user): Builder
    {
        $query = PaiementFiche::query()
            ->where('organization_id', $user->organization_id)
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN);

        return $user->voitToutesLesAgences()
            ? $query
            : $query->whereIn('site_id', $user->sites()->pluck('sites.id')->all());
    }
}
