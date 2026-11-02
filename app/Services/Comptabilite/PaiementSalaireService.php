<?php

namespace App\Services\Comptabilite;

use App\Enums\ModePaiement;
use App\Models\CompteTresorerie;
use App\Models\Employe;
use App\Models\PaieLigne;
use App\Models\PaiePaiement;
use App\Models\User;
use App\Services\PaieCalculService;
use App\Services\PeriodePayabilityChecker;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\DecaissementSalaireResolver;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Seul point d'écriture des paiements de salaire (Comptabilité > Paiement salaire et ancienne route
 * Paie RH). Chaque paiement se fait en transaction, ligne de paie verrouillée : le reste à payer est
 * relu sous verrou à partir des paiements réellement enregistrés, jamais depuis l'écran ni depuis la
 * colonne `reste_a_payer`, qui peut être en retard. Deux paiements simultanés ne peuvent donc pas
 * dépasser le net dû.
 *
 * Payer un salaire est une sortie de trésorerie réelle (ADR 0049) : l'argent sort d'un support de
 * l'agence du salarié (DecaissementSalaireResolver), caisse dédiée du payeur en espèces, solde relu
 * au grand livre sous verrou du support. Solde insuffisant : aucun effet.
 *
 * La pièce comptable est créée par PaiePaiement::booted() dans la même transaction : si elle échoue
 * (RuntimeException), rien n'est enregistré.
 *
 * Organisation et agence sont contrôlées ici explicitement : le Gate::before du super administrateur
 * court-circuite les Policies. Agence = périmètre d'écriture (SiteScopeService::assignedSiteIds()),
 * la vision 360° ne permet pas de payer.
 *
 * Erreurs métier (montant, période non validée) : InvalidArgumentException. Support, référence, solde :
 * ValidationException. Accès refusé : 403.
 */
class PaiementSalaireService
{
    /** @var array<string, Collection<int, string>> agences d'écriture par utilisateur, le temps de la requête */
    private array $sitesEcriture = [];

    public function __construct(
        private readonly PaieCalculService $calcul,
        private readonly SiteScopeService $siteScope,
        private readonly DecaissementSalaireResolver $decaissement,
        private readonly TresorerieDisponibiliteService $disponibilite,
    ) {}

    /** Règles communes aux trois chemins de paiement (ligne, salarié, ancienne route Paie RH). */
    public static function regles(): array
    {
        return [
            'montant' => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', 'in:'.implode(',', array_column(ModePaiement::cases(), 'value'))],
            // Support d'où sort l'argent — inutile en espèces : la caisse dédiée du payeur est résolue
            // côté serveur, jamais choisie par l'écran.
            'compte_tresorerie_id' => ['nullable', 'string', 'required_unless:mode_paiement,'.ModePaiement::ESPECES->value],
            'reference_paiement' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public static function messages(): array
    {
        return [
            'compte_tresorerie_id.required_unless' => "Choisissez le compte d'où sort ce paiement.",
        ];
    }

    /**
     * @param  array{montant: mixed, mode_paiement: string, compte_tresorerie_id?: ?string, reference_paiement?: ?string, date_paiement: string, note?: ?string}  $data
     */
    public function payerLigne(PaieLigne $ligne, User $acteur, array $data): PaiePaiement
    {
        return DB::transaction(function () use ($ligne, $acteur, $data) {
            $montant = (float) $data['montant'];

            $ligne = PaieLigne::whereKey($ligne->getKey())->lockForUpdate()->firstOrFail();
            $ligne->load(['periode', 'employe']);

            $this->assertPeutPayer($ligne, $acteur);
            PeriodePayabilityChecker::assertPeriodePayable($ligne->periode);

            $reste = $this->resteSousVerrou($ligne);
            if ($montant > $reste + 0.01) {
                throw new InvalidArgumentException('Le montant dépasse le reste à payer ('.$this->format($reste).' GNF).');
            }

            $support = $this->supportDebite($ligne->employe, $acteur, $data, $montant);

            $paiement = $ligne->paiements()->create($this->attributs($data, $montant, $support, $acteur));

            $this->calcul->recalculerApresPaiement($ligne);

            return $paiement;
        });
    }

    /**
     * Paiement global d'un salarié : répartit le montant en FIFO (mois le plus ancien d'abord) sur ses
     * lignes de paie impayées. Toutes ses lignes sont verrouillées avant la répartition.
     *
     * @param  array{montant: mixed, mode_paiement: string, compte_tresorerie_id?: ?string, reference_paiement?: ?string, date_paiement: string, note?: ?string}  $data
     * @return Collection<int, PaiePaiement>
     */
    public function payerEmploye(Employe $employe, User $acteur, array $data): Collection
    {
        return DB::transaction(function () use ($employe, $acteur, $data) {
            $montant = (float) $data['montant'];

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

            // Solde contrôlé sur le montant total : la répartition par mois ne sort l'argent qu'une fois.
            $support = $this->supportDebite($employe, $acteur, $data, $montant);

            $paiements = collect();
            $restant = $montant;
            foreach ($lignes as [$ligne, $reste]) {
                if ($restant <= 0.01) {
                    break;
                }

                $alloue = min($restant, $reste);
                $paiements->push($ligne->paiements()->create($this->attributs($data, $alloue, $support, $acteur)));
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

    /**
     * Support d'où sort l'argent, solde garanti sous verrou juste avant la sortie.
     *
     * @throws ValidationException
     */
    private function supportDebite(Employe $employe, User $acteur, array $data, float $montant): CompteTresorerie
    {
        $support = $this->decaissement->supportPour($employe, $acteur, $data['mode_paiement'], $data['compte_tresorerie_id'] ?? null);

        if ($this->decaissement->referenceRequise($employe, $data['mode_paiement'], $support->id) && blank($data['reference_paiement'] ?? null)) {
            throw ValidationException::withMessages([
                'reference_paiement' => 'La référence du paiement est obligatoire pour ce mode de paiement.',
            ]);
        }

        $this->disponibilite->garantirSoldeSuffisant($support->id, $montant, now(), 'un paiement');

        return $support;
    }

    /** @return array<string, mixed> */
    private function attributs(array $data, float $montant, CompteTresorerie $support, User $acteur): array
    {
        return [
            // Agence d'où sort l'argent : la pièce comptable et le solde du support y sont rattachés.
            'site_id' => $support->site_id,
            'montant' => $montant,
            'date_paiement' => $data['date_paiement'],
            'mode_paiement' => $data['mode_paiement'],
            'moyen_paiement_detail' => $support->operateur_mobile_money?->value,
            'reference_paiement' => $data['reference_paiement'] ?? null,
            'compte_tresorerie_id' => $support->id,
            'note' => $data['note'] ?? null,
            'created_by' => $acteur->id,
        ];
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
