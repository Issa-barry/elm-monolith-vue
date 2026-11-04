<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\ActiviteCompte;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\CommissionRegleController;
use App\Http\Requests\Api\Client\CommissionsMineRequest;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\Client\BeneficiairesCompte;
use App\Services\Client\BeneficiairesCompteResolver;
use App\Services\Client\ClientEarningsService;
use App\Services\Client\Data\ActiviteEarningsRow;
use App\Services\Client\Data\CommissionClientRow;
use App\Services\Client\Data\VehiculeSummary;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Détail des commissions du compte, toutes activités (ADR 0054). Chaque ligne est une part dont le
 * bénéficiaire est le compte lui-même ; jamais la part d'un autre bénéficiaire de la même opération.
 */
class CommissionsCompteController extends Controller
{
    private const SOURCES = [CommandeVente::class, TransfertLogistique::class];

    public function __construct(
        private readonly BeneficiairesCompteResolver $beneficiairesResolver,
        private readonly ClientEarningsService $earningsService,
    ) {}

    #[Endpoint(
        title: 'Détail des commissions (toutes activités)',
        description: 'Une ligne par part du compte, de la plus récente à la plus ancienne (date de gain). '
            .'`activite` (`proprietaire`, `livreur`, `parrain`, `consultant`) vient du type de bénéficiaire '
            .'enregistré sur la part, jamais des rôles du compte ; `beneficiaire_libelle` nomme l\'entreprise '
            .'(ou la personne) pour un consultant. Les commissions annulées restent listées avec leur statut '
            .'mais sortent de `resume`. `resume` porte, sur les mêmes filtres et toutes les pages, les mêmes '
            .'champs que `summary` et `par_activite` du dashboard. Période : `periode` (période rapide), '
            .'`annee` + `mois` + `quinzaine` (`P1` du 1er au 15, `P2` du 16 à la fin du mois, `TOUT` ou omise '
            .'= le mois) ou `date_debut`/`date_fin`, exclusifs (422 sinon). `recherche` : référence de '
            .'l\'opération, nom ou immatriculation du véhicule. Un `vehicule_id` sans part du compte renvoie une '
            .'liste vide.',
    )]
    public function __invoke(CommissionsMineRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $beneficiaires = $this->beneficiairesResolver->resolve($user);

        [$debut, $fin] = $request->bornes();
        $activite = $request->activite();
        $vehiculeIds = $request->input('vehicule_id') !== null ? [(string) $request->input('vehicule_id')] : null;

        $parts = $this->earningsService
            ->partsQuery($beneficiaires, $debut, $fin, $request->input('statut'), $vehiculeIds, $activite)
            ->tap(fn (Builder $q) => $this->rechercher($q, $request->recherche()));

        $paginator = $this->earningsService->avecOperation(clone $parts)
            ->orderByDesc(CommissionEnveloppe::query()
                ->select('earned_at')
                ->whereColumn('commission_enveloppes.id', 'commission_enveloppe_parts.enveloppe_id'))
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString()
            ->through(fn (CommissionEnveloppePart $part) => $this->ligne($part, $beneficiaires));

        $parActivite = $this->resume($beneficiaires, $parts, $debut, $fin, $vehiculeIds, $activite);

        return response()->json([
            'data' => $paginator->items(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'resume' => [
                'summary' => $this->earningsService->totaux($parActivite),
                'par_activite' => $parActivite,
            ],
            'filters' => $request->filters(),
        ]);
    }

    /**
     * Mêmes règles que le dashboard, sur les parts filtrées (les annulées en sortent). Les dépenses
     * déduites ne dépendent ni du statut ni de la recherche.
     *
     * @param  array<string>|null  $vehiculeIds
     * @return array<int, ActiviteEarningsRow>
     */
    private function resume(BeneficiairesCompte $beneficiaires, Builder $parts, ?string $debut, ?string $fin, ?array $vehiculeIds, ?ActiviteCompte $activite): array
    {
        $actives = (clone $parts)->get()->reject(fn (CommissionEnveloppePart $p) => $p->statut?->isAnnulee());
        $frais = $this->earningsService->fraisDepensesParVehicule($beneficiaires->organizationId, $beneficiaires->proprietaire, $debut, $fin, $vehiculeIds);
        $deductions = $this->earningsService->deductionsParActivite($beneficiaires, $debut, $fin, $vehiculeIds, $frais);

        return array_values(array_filter(
            $this->earningsService->parActivite($beneficiaires, $actives, collect(), $deductions),
            fn (ActiviteEarningsRow $ligne) => $activite === null || $ligne->activite === $activite->value,
        ));
    }

    private function rechercher(Builder $parts, ?string $recherche): void
    {
        if ($recherche === null) {
            return;
        }

        $like = '%'.$recherche.'%';

        $parts->whereHas('enveloppe', fn (Builder $e) => $e->whereHasMorph('source', self::SOURCES, fn (Builder $s) => $s
            ->where(fn (Builder $w) => $w
                ->where('reference', 'like', $like)
                ->orWhereHas('vehicule', fn (Builder $v) => $v
                    ->where('nom_vehicule', 'like', $like)
                    ->orWhere('immatriculation', 'like', $like)))));
    }

    private function ligne(CommissionEnveloppePart $part, BeneficiairesCompte $beneficiaires): CommissionClientRow
    {
        $enveloppe = $part->enveloppe;
        $operation = $enveloppe?->source;
        $vehicule = $operation?->vehicule;
        $processus = $enveloppe?->processus?->code;
        $activite = ActiviteCompte::depuisBeneficiaireType($part->beneficiaire_type);

        return new CommissionClientRow(
            id: (string) $part->id,
            activite: $activite?->value,
            activiteLabel: $activite?->label(),
            beneficiaireLibelle: $activite === ActiviteCompte::CONSULTANT ? $beneficiaires->libelleConsultant((string) $part->beneficiaire_id) : null,
            operation: $enveloppe?->source_type === TransfertLogistique::class ? 'logistique' : 'vente',
            operationLabel: $processus ? CommissionRegleController::processusLabel($processus) : null,
            reference: $operation?->reference,
            vehicule: $vehicule ? new VehiculeSummary(
                id: (string) $vehicule->id,
                nomVehicule: (string) $vehicule->nom_vehicule,
                immatriculation: (string) $vehicule->immatriculation,
            ) : null,
            date: $enveloppe?->earned_at?->toDateString(),
            montantAPayer: (float) $part->montant_a_payer,
            montantVerse: (float) $part->montant_verse,
            montantRestant: (float) $part->montant_restant,
            statut: $part->statut?->value,
            statutLabel: $part->statut?->label(),
        );
    }
}
