<?php

namespace App\Services\Comptabilite;

use App\Models\Employe;
use App\Models\PaieLigne;
use App\Models\PaiePaiement;
use App\Models\User;
use App\Services\PaieCalculService;
use App\Services\PeriodePayabilityChecker;
use App\Services\SiteScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Seul point d'écriture des paiements de salaire (Comptabilité > Paiement salaire et ancienne route
 * Paie RH). Chaque paiement se fait en transaction, ligne de paie verrouillée : le reste à payer est
 * relu sous verrou à partir des paiements réellement enregistrés, jamais depuis l'écran ni depuis la
 * colonne `reste_a_payer`, qui peut être en retard. Deux paiements simultanés ne peuvent donc pas
 * dépasser le net dû.
 *
 * La pièce comptable est créée par PaiePaiement::booted() dans la même transaction : si elle échoue
 * (RuntimeException), rien n'est enregistré.
 *
 * Organisation et agence sont contrôlées ici explicitement : le Gate::before du super administrateur
 * court-circuite les Policies. Agence = périmètre d'écriture (SiteScopeService::assignedSiteIds()),
 * la vision 360° ne permet pas de payer.
 *
 * Erreurs métier (montant, période non validée) : InvalidArgumentException. Accès refusé : 403.
 */
class PaiementSalaireService
{
    /** @var array<string, Collection<int, string>> agences d'écriture par utilisateur, le temps de la requête */
    private array $sitesEcriture = [];

    public function __construct(
        private readonly PaieCalculService $calcul,
        private readonly SiteScopeService $siteScope,
    ) {}

    public function payerLigne(PaieLigne $ligne, User $acteur, float $montant, string $modePaiement, string $datePaiement, ?string $note = null): PaiePaiement
    {
        return DB::transaction(function () use ($ligne, $acteur, $montant, $modePaiement, $datePaiement, $note) {
            $ligne = PaieLigne::whereKey($ligne->getKey())->lockForUpdate()->firstOrFail();
            $ligne->load(['periode', 'employe']);

            $this->assertPeutPayer($ligne, $acteur);
            PeriodePayabilityChecker::assertPeriodePayable($ligne->periode);

            $reste = $this->resteSousVerrou($ligne);
            if ($montant > $reste + 0.01) {
                throw new InvalidArgumentException('Le montant dépasse le reste à payer ('.$this->format($reste).' GNF).');
            }

            $paiement = $ligne->paiements()->create([
                'montant' => $montant,
                'date_paiement' => $datePaiement,
                'mode_paiement' => $modePaiement,
                'note' => $note,
            ]);

            $this->calcul->recalculerApresPaiement($ligne);

            return $paiement;
        });
    }

    /**
     * Paiement global d'un salarié : répartit le montant en FIFO (mois le plus ancien d'abord) sur ses
     * lignes de paie impayées. Toutes ses lignes sont verrouillées avant la répartition.
     *
     * @return Collection<int, PaiePaiement>
     */
    public function payerEmploye(Employe $employe, User $acteur, float $montant, string $modePaiement, string $datePaiement, ?string $note = null): Collection
    {
        return DB::transaction(function () use ($employe, $acteur, $montant, $modePaiement, $datePaiement, $note) {
            // Lecture verrouillante sur la seule table paie_lignes (pas de whereHas) : sous MySQL
            // REPEATABLE READ, c'est elle qui garantit de lire les paiements déjà validés par une
            // transaction concurrente.
            $lignes = PaieLigne::where('employe_id', $employe->getKey())->lockForUpdate()->get();
            $lignes->load(['periode', 'employe']);

            $lignes = $lignes
                ->filter(fn (PaieLigne $l) => $l->periode?->organization_id === $employe->organization_id)
                ->each(fn (PaieLigne $l) => $this->assertPeutPayer($l, $acteur))
                ->map(fn (PaieLigne $l) => [$l, $this->resteSousVerrou($l)])
                ->filter(fn (array $ligneEtReste) => $ligneEtReste[1] > 0)
                ->sortBy(fn (array $ligneEtReste) => sprintf('%04d-%02d', $ligneEtReste[0]->periode->annee, $ligneEtReste[0]->periode->mois))
                ->values();

            $totalDisponible = (float) $lignes->sum(fn (array $ligneEtReste) => $ligneEtReste[1]);
            if ($montant > $totalDisponible + 0.01) {
                throw new InvalidArgumentException('Le montant dépasse le reste à payer ('.$this->format($totalDisponible).' GNF).');
            }

            $touchees = PeriodePayabilityChecker::touchedUntilAmount($lignes, $montant, fn (array $ligneEtReste) => $ligneEtReste[1]);
            PeriodePayabilityChecker::assertLignesPayables($touchees->map(fn (array $ligneEtReste) => $ligneEtReste[0]));

            $paiements = collect();
            $restant = $montant;
            foreach ($lignes as [$ligne, $reste]) {
                if ($restant <= 0.01) {
                    break;
                }

                $alloue = min($restant, $reste);
                $paiements->push($ligne->paiements()->create([
                    'montant' => $alloue,
                    'date_paiement' => $datePaiement,
                    'mode_paiement' => $modePaiement,
                    'note' => $note,
                ]));
                $this->calcul->recalculerApresPaiement($ligne);
                $restant -= $alloue;
            }

            return $paiements;
        });
    }

    /** Suppression d'un paiement : l'écriture est contrepassée par PaiePaiement::booted(). */
    public function annulerPaiement(PaiePaiement $paiement, User $acteur): void
    {
        DB::transaction(function () use ($paiement, $acteur) {
            $ligne = PaieLigne::whereKey($paiement->paie_ligne_id)->lockForUpdate()->firstOrFail();
            $ligne->load(['periode', 'employe']);

            $this->assertPeutPayer($ligne, $acteur);

            PaiePaiement::whereKey($paiement->getKey())->lockForUpdate()->firstOrFail()->delete();

            $this->calcul->recalculerApresPaiement($ligne);
        });
    }

    /** Vrai si l'acteur peut payer ce salarié au regard de l'organisation et de ses agences. */
    public function peutPayerEmploye(Employe $employe, User $acteur): bool
    {
        if ($employe->organization_id !== $acteur->organization_id) {
            return false;
        }

        $siteIds = $this->sitesEcriture[$acteur->getKey()] ??= $this->siteScope->assignedSiteIds($acteur);

        return $siteIds->isEmpty() || ($employe->site_id !== null && $siteIds->contains($employe->site_id));
    }

    private function assertPeutPayer(PaieLigne $ligne, User $acteur): void
    {
        abort_if($ligne->periode?->organization_id !== $acteur->organization_id, 403);
        abort_if($ligne->employe === null, 403);
        abort_unless($this->peutPayerEmploye($ligne->employe, $acteur), 403, 'Ce salarié ne relève pas de vos agences.');
    }

    private function resteSousVerrou(PaieLigne $ligne): float
    {
        $dejaPaye = (float) PaiePaiement::where('paie_ligne_id', $ligne->getKey())->lockForUpdate()->sum('montant');

        return max(0.0, round((float) $ligne->net - $dejaPaye, 2));
    }

    private function format(float $montant): string
    {
        return number_format($montant, 0, ',', "\u{202F}");
    }
}
