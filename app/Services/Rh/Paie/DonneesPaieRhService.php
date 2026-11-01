<?php

namespace App\Services\Rh\Paie;

use App\Contracts\Rh\SourceDonneesPaie;
use App\Enums\AuditEvent;
use App\Enums\StatutTransmissionPaie;
use App\Models\TransmissionPaie;
use App\Models\TransmissionPaieLigne;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Implémentation RH de SourceDonneesPaie (ADR 0040, ADR 0041). La reprise par la Comptabilité
 * (App\Services\Comptabilite\ReprisePaieService) appelle prendreEnCharge() : la mise à jour
 * conditionnelle (transmise ET jamais prise en charge) garantit qu'une version n'est traitée qu'une
 * fois, même en cas d'appels simultanés.
 */
class DonneesPaieRhService implements SourceDonneesPaie
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly CalculElementsPaieService $calcul,
    ) {}

    public function brouillonEnCours(string $organizationId, int $annee, int $mois): ?array
    {
        $brouillon = TransmissionPaie::where('organization_id', $organizationId)
            ->where('annee', $annee)->where('mois', $mois)
            ->where('statut', StatutTransmissionPaie::BROUILLON->value)
            ->first();
        if ($brouillon === null) {
            return null;
        }

        return [
            'version' => $brouillon->version,
            'nb_salaries' => $brouillon->nb_salaries,
            'total_verse' => (float) $brouillon->total_verse,
            'total_valorise' => (float) $brouillon->total_valorise,
            'nb_anomalies_bloquantes' => count(array_filter($brouillon->anomaliesListe(), fn (array $a) => $a['niveau'] === 'bloquante')),
            'a_jour' => app(TransmissionPaieService::class)->estAJour($brouillon),
            'preparee_le' => $brouillon->preparee_le?->format('d/m/Y à H:i'),
        ];
    }

    public function transmissionCourante(string $organizationId, int $annee, int $mois): ?TransmissionPaie
    {
        return TransmissionPaie::where('organization_id', $organizationId)
            ->where('annee', $annee)->where('mois', $mois)
            ->where('statut', StatutTransmissionPaie::TRANSMISE->value)
            ->first();
    }

    public function lignes(TransmissionPaie $transmission): Collection
    {
        if (! in_array($transmission->statut, [StatutTransmissionPaie::TRANSMISE, StatutTransmissionPaie::REMPLACEE], true)) {
            throw ValidationException::withMessages(['transmission' => 'Seule une version transmise est lisible par la Comptabilité.']);
        }

        $lignes = TransmissionPaieLigne::where('transmission_paie_id', $transmission->id)
            ->orderBy('nom_salarie')->orderBy('ordre')
            ->get();

        if ($transmission->empreinte === null || ! hash_equals($transmission->empreinte, CalculElementsPaieService::empreinte($lignes))) {
            throw ValidationException::withMessages([
                'transmission' => "Le contenu de la version {$transmission->version} ne correspond plus à son empreinte : elle ne peut pas être lue.",
            ]);
        }

        return $lignes;
    }

    public function estimationVersements(string $organizationId, int $annee, int $mois): Collection
    {
        $transmission = $this->transmissionCourante($organizationId, $annee, $mois);
        $lignes = $transmission !== null
            ? TransmissionPaieLigne::where('transmission_paie_id', $transmission->id)->get()
                ->map(fn (TransmissionPaieLigne $l) => $l->only(['employe_id', 'nom_salarie', 'site_id', 'versement', 'montant']))
            : collect($this->calcul->calculer($organizationId, $annee, $mois)['lignes']);

        return $lignes
            ->filter(fn ($l) => $l['versement'] === TransmissionPaieLigne::VERSEMENT_VERSE)
            ->groupBy('employe_id')
            ->map(fn (Collection $parSalarie, string $employeId) => [
                'employe_id' => $employeId,
                'nom' => (string) $parSalarie->first()['nom_salarie'],
                'site_id' => $parSalarie->first()['site_id'],
                'montant' => round((float) $parSalarie->sum(fn ($l) => (float) $l['montant']), 2),
            ])
            ->values();
    }

    public function prendreEnCharge(TransmissionPaie $transmission, User $acteur, string $reference): TransmissionPaie
    {
        // Reprise et journal ensemble : rien n'est enregistré si l'un des deux échoue.
        return DB::transaction(function () use ($transmission, $acteur, $reference) {
            $misesAJour = TransmissionPaie::whereKey($transmission->id)
                ->where('organization_id', $acteur->organization_id)
                ->where('statut', StatutTransmissionPaie::TRANSMISE->value)
                ->whereNull('prise_en_charge_le')
                ->update([
                    'prise_en_charge_par' => $acteur->id,
                    'prise_en_charge_le' => now(),
                    'prise_en_charge_reference' => mb_substr($reference, 0, 100),
                    'updated_at' => now(),
                ]);

            if ($misesAJour !== 1) {
                throw ValidationException::withMessages([
                    'transmission' => 'Cette version a déjà été reprise par la Comptabilité, ou n\'est pas la version transmise du mois.',
                ]);
            }

            $transmission = $transmission->fresh();
            $this->audit->record($transmission, AuditEvent::UPDATED, $acteur, null,
                ['prise_en_charge_reference' => $transmission->prise_en_charge_reference], [
                    'module' => 'rh_transmissions_paie',
                    'description' => "Version {$transmission->version} reprise par la Comptabilité ({$transmission->prise_en_charge_reference})",
                    'periode' => sprintf('%02d/%d', $transmission->mois, $transmission->annee),
                    'version' => $transmission->version,
                ]);

            return $transmission;
        });
    }
}
