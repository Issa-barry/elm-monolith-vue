<?php

namespace App\Services\Rh;

use App\Enums\PeriodiciteRemuneration;
use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeContrat;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\TypeRemuneration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Création d'un contrat de travail, commune à l'écran Contrats et à l'import des dossiers RH :
 * employé de l'organisation, un seul contrat actif par employé, contrôlé sous verrou de l'employé
 * (deux créations simultanées ne peuvent pas produire deux contrats actifs).
 */
class ContratService
{
    /**
     * « Nouveau contrat » avec ses primes, en une seule opération (ADR 0048) : si une prime est
     * refusée, ni le contrat ni aucune prime ne reste enregistré. Les primes passent par le service
     * des primes et ses règles (type actif, chevauchement, mois figés, agence) ; elles sont
     * toujours validées d'emblée, jamais « à valider » : le droit de validation est exigé par
     * AffectationRemunerationService::affecter(). Le PDF se produit après, hors de cette transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{type_remuneration_id: string, date_debut: string, montant?: mixed, taux?: mixed}>  $primes
     */
    public function creerAvecPrimes(array $data, array $primes, string $organizationId, User $acteur): Contrat
    {
        return DB::transaction(function () use ($data, $primes, $organizationId, $acteur) {
            $contrat = $this->creer($data, $organizationId);

            foreach (array_values($primes) as $index => $prime) {
                try {
                    $this->ajouterPrime($contrat, $prime, $acteur);
                } catch (ValidationException $e) {
                    // L'erreur s'affiche sur la ligne concernée du formulaire.
                    throw ValidationException::withMessages(collect($e->errors())
                        ->mapWithKeys(fn (array $messages, string $champ) => ["primes.{$index}.{$champ}" => $messages])
                        ->all());
                }
            }

            return $contrat;
        });
    }

    /** @param  array{type_remuneration_id: string, date_debut: string, montant?: mixed, taux?: mixed}  $prime */
    private function ajouterPrime(Contrat $contrat, array $prime, User $acteur): void
    {
        $type = TypeRemuneration::where('organization_id', $contrat->organization_id)->find($prime['type_remuneration_id']);
        if ($type === null) {
            throw ValidationException::withMessages(['type_remuneration_id' => 'Type de prime ou d\'avantage inconnu.']);
        }

        $debut = Carbon::parse($prime['date_debut'])->startOfDay();
        $ponctuelle = $type->periodicite === PeriodiciteRemuneration::PONCTUELLE;
        // Une prime ponctuelle couvre tout son mois de versement.
        $finPeriode = $ponctuelle ? $debut->copy()->endOfMonth()->startOfDay() : $debut;

        if ($finPeriode->lt($contrat->date_debut)) {
            throw ValidationException::withMessages(['date_debut' => 'La prime ne peut pas commencer avant le début du contrat ('.$contrat->date_debut->format('d/m/Y').').']);
        }
        if ($contrat->date_fin !== null && $debut->gt($contrat->date_fin)) {
            throw ValidationException::withMessages(['date_debut' => 'La prime ne peut pas commencer après la fin du contrat ('.$contrat->date_fin->format('d/m/Y').').']);
        }

        // Une prime mensuelle s'arrête avec le CDD, ou plus tôt si le type lui-même prend fin.
        $fins = array_filter([$contrat->date_fin?->toDateString(), $type->date_fin_application?->toDateString()]);

        app(AffectationRemunerationService::class)->affecter([
            'employe_id' => $contrat->employe_id,
            'type_remuneration_id' => $type->id,
            'date_debut' => $debut->toDateString(),
            'date_fin' => $ponctuelle || $fins === [] ? null : min($fins),
            'montant' => $prime['montant'] ?? null,
            'taux' => $prime['taux'] ?? null,
        ], $acteur, valider: true);
    }

    /**
     * @param  array{employe_id: string, type_contrat: string, date_debut: string, date_fin?: ?string, salaire_base?: float|string|null}  $data
     */
    public function creer(array $data, string $organizationId): Contrat
    {
        // CDI => date_fin null
        if ($data['type_contrat'] === TypeContrat::CDI->value) {
            $data['date_fin'] = null;
        }

        return DB::transaction(function () use ($data, $organizationId) {
            $employe = $this->verrouillerEmploye($data['employe_id'], $organizationId);
            $this->assertPasSorti($employe);
            $this->assertAucunAutreContratActif($employe->id);
            $this->assertPasDeChevauchement($employe->id, $data['date_debut'], $data['date_fin'] ?? null);

            // Date d'embauche du dossier : reprise du premier contrat quand elle n'est pas encore
            // connue (elle n'est plus saisie à la création de l'employé). Une date déjà renseignée
            // (ancienneté antérieure, import) n'est jamais modifiée ici.
            if ($employe->date_embauche === null) {
                $employe->update(['date_embauche' => $data['date_debut']]);
            }

            return Contrat::create([
                ...$data,
                'organization_id' => $organizationId,
                'statut_contrat' => StatutContrat::ACTIF->value,
            ]);
        });
    }

    /**
     * Salarié sorti (DepartEmployeService) : aucun contrat actif ne peut lui être créé ou rendu,
     * tant qu'une procédure de réembauche n'est pas définie — un contrat ne le réactiverait pas et
     * la paie s'arrêterait toujours à sa date de sortie. Le salarié n'est jamais réactivé ici.
     */
    public function assertPasSorti(Employe $employe): void
    {
        if ($employe->statut === StatutEmploye::SORTI) {
            throw ValidationException::withMessages([
                'employe_id' => 'Ce salarié est sorti de l’entreprise : aucun contrat ne peut lui être créé tant qu’une procédure de réembauche n’est pas définie. Si le départ a été enregistré par erreur, annulez-le depuis sa fiche.',
            ]);
        }
    }

    /**
     * Deux contrats d'un même employé ne peuvent pas couvrir les mêmes jours : la paie les refuse
     * (anomalie bloquante « contrats_chevauchants »), la saisie les refuse donc dès l'enregistrement.
     * Un contrat terminé ou rompu sans date de fin n'a pas de période connue : il est ignoré,
     * comme dans la paie.
     */
    public function assertPasDeChevauchement(string $employeId, string $dateDebut, ?string $dateFin, ?string $saufContratId = null): void
    {
        $autre = Contrat::where('employe_id', $employeId)
            ->when($saufContratId, fn ($q) => $q->whereKeyNot($saufContratId))
            ->where(fn ($q) => $q->where('statut_contrat', StatutContrat::ACTIF->value)->orWhereNotNull('date_fin'))
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $dateDebut))
            ->when($dateFin !== null, fn ($q) => $q->whereDate('date_debut', '<=', $dateFin))
            ->orderBy('date_debut')
            ->lockForUpdate()
            ->first();

        if ($autre !== null) {
            $periode = 'du '.$autre->date_debut->format('d/m/Y').($autre->date_fin ? ' au '.$autre->date_fin->format('d/m/Y') : ', sans date de fin');
            throw ValidationException::withMessages([
                'date_debut' => "Ces dates chevauchent un autre contrat de cet employé ({$autre->type_contrat->label()} {$periode}).",
            ]);
        }
    }

    /** Employé de l'organisation, verrouillé jusqu'à la fin de la transaction (404 sinon). */
    public function verrouillerEmploye(string $employeId, string $organizationId): Employe
    {
        return Employe::whereKey($employeId)
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Un seul contrat actif par employé, en création comme en modification. */
    public function assertAucunAutreContratActif(string $employeId, ?string $saufContratId = null): void
    {
        if ($this->aUnAutreContratActif($employeId, $saufContratId)) {
            throw ValidationException::withMessages([
                'employe_id' => 'Cet employé a déjà un contrat actif. Terminez-le avant d\'en créer ou d\'en activer un autre.',
            ]);
        }
    }

    /**
     * Lecture VERROUILLANTE : sous MySQL REPEATABLE READ, une lecture simple dans une transaction déjà
     * commencée (import RH : lignes précédentes) lit l'instantané du début de la transaction et ne
     * verrait pas un contrat validé entre-temps par une autre opération. FOR UPDATE lit la dernière
     * version validée.
     */
    public function aUnAutreContratActif(string $employeId, ?string $saufContratId = null): bool
    {
        return Contrat::where('employe_id', $employeId)
            ->where('statut_contrat', StatutContrat::ACTIF->value)
            ->when($saufContratId, fn ($q) => $q->whereKeyNot($saufContratId))
            ->lockForUpdate()
            ->exists();
    }
}
