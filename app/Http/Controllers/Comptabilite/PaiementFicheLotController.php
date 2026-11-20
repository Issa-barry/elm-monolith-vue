<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\ModePaiement;
use App\Http\Controllers\Controller;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Services\Commission\PaiementFicheService;
use App\Services\PeriodePayabilityChecker;
use App\Services\Tresorerie\DecaissementFicheResolver;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use App\Support\Paiements\OperationPaiement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Paiement groupé (ADR 0068) : marque comme payées plusieurs fiches réglées hors de l'application
 * (dépôts Orange Money…), chacune pour la totalité de son reste. Mêmes règles que le bouton Payer,
 * fiche par fiche (PaiementFicheService) : un seul support, d'une seule agence payeuse, et un lot
 * entier ou rien.
 */
class PaiementFicheLotController extends Controller
{
    /** Plafond d'un lot : l'écran envoie des lots plus petits (paiementGroupe.ts). */
    public const LOT_MAX = 50;

    public const MESSAGE_AGENCES_MULTIPLES = 'Les fiches sélectionnées sont payées par des agences différentes : un paiement groupé sort d\'un seul compte. Filtrez par agence, puis payez agence par agence.';

    public function __invoke(Request $request, DecaissementFicheResolver $decaissement, PaiementFicheService $paiements): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'fiches' => ['required', 'array', 'min:1', 'max:'.self::LOT_MAX],
            'fiches.*.id' => ['required', 'string', 'distinct'],
            // Montant affiché à l'écran : le reste réel doit lui être égal, sinon rien n'est payé.
            'fiches.*.montant' => ['required', 'numeric', 'min:1'],
            'fiches.*.reference_paiement' => ['nullable', 'string', 'max:190'],
            'mode_paiement' => ['required', 'in:'.implode(',', array_column(ModePaiement::cases(), 'value'))],
            'compte_tresorerie_id' => ['nullable', 'string', 'required_unless:mode_paiement,'.ModePaiement::ESPECES->value],
            'reference_paiement' => ['nullable', 'string', 'max:190'],
            'date_paiement' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string'],
        ], [
            'fiches.max' => 'Un paiement groupé porte sur '.self::LOT_MAX.' fiches au plus.',
            'compte_tresorerie_id.required_unless' => "Choisissez le compte d'où sort ce paiement.",
            'date_paiement.before_or_equal' => 'La date de paiement ne peut pas être dans le futur.',
        ]);

        $lignes = collect($data['fiches'])->keyBy('id');
        $fiches = PaiementFiche::with('periode')
            ->where('organization_id', $user->organization_id)
            ->whereIn('id', $lignes->keys())
            ->get()
            ->keyBy('id');

        if ($fiches->count() !== $lignes->count()) {
            throw ValidationException::withMessages(['fiches' => 'Une des fiches sélectionnées est introuvable.']);
        }

        foreach ($fiches as $fiche) {
            abort_unless($user->can('payer', $fiche), 403, "Vous n'avez pas le droit de payer la fiche de {$fiche->beneficiaire_nom}.");
        }

        // Idempotence (ADR 0069) : une clé par lot, liée à l'ensemble de ses paramètres. Le rejeu
        // d'un lot déjà passé restitue son résultat, avant les contrôles qui dépendent de l'état
        // actuel des fiches (reste à payer, période).
        $operation = OperationPaiement::depuisRequete($request, [
            'type' => 'paiement_fiches_lot',
            'mode_paiement' => $data['mode_paiement'],
            'compte_tresorerie_id' => $data['compte_tresorerie_id'] ?? null,
            'reference_paiement' => $data['reference_paiement'] ?? null,
            'date_paiement' => $data['date_paiement'],
            'note' => $data['note'] ?? null,
            'fiches' => $lignes->sortKeys()->map(fn (array $l) => [
                'id' => $l['id'],
                'montant' => OperationPaiement::montant($l['montant']),
                'reference_paiement' => $l['reference_paiement'] ?? null,
            ])->values()->all(),
        ]);
        $dejaEnregistres = $paiements->paiementsDeLOperation($user->organization_id, $operation);
        if ($dejaEnregistres->isNotEmpty()) {
            return $this->resultat($dejaEnregistres, $fiches, rejeu: true);
        }

        foreach ($fiches as $fiche) {
            if ($fiche->beneficiaire_type === 'salarie') {
                throw ValidationException::withMessages(['fiches' => PaiementFichePaiementController::MESSAGE_SALARIE]);
            }

            try {
                PeriodePayabilityChecker::assertPeriodePayable($fiche->periode);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['fiches' => "{$fiche->beneficiaire_nom} : {$e->getMessage()}"]);
            }
        }

        if ($fiches->map(fn (PaiementFiche $f) => $decaissement->siteTresorerie($f))->unique()->count() > 1) {
            throw ValidationException::withMessages(['fiches' => self::MESSAGE_AGENCES_MULTIPLES]);
        }

        // Une seule agence payeuse : le support et l'obligation de référence valent pour tout le lot.
        $premiere = $fiches->first();
        $support = $decaissement->supportPour($premiere, $user, $data['mode_paiement'], $data['compte_tresorerie_id'] ?? null);
        $referenceRequise = $decaissement->referenceRequise($premiere, $data['mode_paiement'], $support->id);

        $references = $lignes->map(fn (array $ligne) => trim((string) ($ligne['reference_paiement'] ?? '')) ?: (trim((string) ($data['reference_paiement'] ?? '')) ?: null));
        if ($referenceRequise && ($sansReference = $references->filter(fn (?string $r) => $r === null)->keys()->first()) !== null) {
            throw ValidationException::withMessages([
                'reference_paiement' => "La référence du paiement est obligatoire pour ce mode de paiement ({$fiches[$sansReference]->beneficiaire_nom}).",
            ]);
        }

        try {
            [$payes, $dejaPayees] = DB::transaction(function () use ($fiches, $lignes, $references, $support, $data, $paiements, $operation) {
                // Ordre fixe des verrous : deux lots concurrents ne s'interbloquent pas.
                $restants = PaiementFiche::whereIn('id', $fiches->keys())->orderBy('id')->lockForUpdate()->get()
                    ->mapWithKeys(fn (PaiementFiche $f) => [$f->id => $f->montant_restant]);

                // Relu sous les verrous : l'envoi jumeau de ce lot a pu passer pendant l'attente.
                $jumeaux = $paiements->paiementsDeLOperation($fiches->first()->organization_id, $operation);
                if ($jumeaux->isNotEmpty()) {
                    return [$jumeaux->map(fn ($p) => [$fiches[$p->fiche_id], $p])->all(), null];
                }

                $aPayer = [];
                $dejaPayees = [];
                foreach ($fiches as $id => $fiche) {
                    $restant = (float) $restants[$id];
                    if ($restant <= 0.004) {
                        // Déjà soldée (lot renvoyé après une coupure, ou payée entre-temps) : rien à refaire.
                        $dejaPayees[] = $fiche->beneficiaire_nom;

                        continue;
                    }
                    $attendu = (float) $lignes[$id]['montant'];
                    if (abs($restant - $attendu) > 0.5) {
                        throw ValidationException::withMessages([
                            'fiches' => "Le reste à payer de {$fiche->beneficiaire_nom} a changé (".self::gnf($restant).' au lieu de '.self::gnf($attendu).') : rechargez la page. Aucun paiement de ce lot n\'a été enregistré.',
                        ]);
                    }
                    $aPayer[$id] = $restant;
                }

                if ($aPayer !== []) {
                    app(TresorerieDisponibiliteService::class)->garantirSoldeSuffisant($support->id, array_sum($aPayer), now(), 'ce paiement groupé');
                }

                $payes = [];
                foreach ($aPayer as $id => $montant) {
                    $payes[] = [$fiches[$id], $paiements->enregistrer($fiches[$id], $support, [
                        'montant' => $montant,
                        'mode_paiement' => $data['mode_paiement'],
                        'reference_paiement' => $references[$id],
                        'date_paiement' => $data['date_paiement'],
                        'note' => $data['note'] ?? null,
                    ], $operation)];
                }

                return [$payes, $dejaPayees];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dernier filet (contrainte pfp_fiche_operation_unique) : l'envoi jumeau est passé.
            $jumeaux = $paiements->paiementsDeLOperation($user->organization_id, $operation);
            if ($jumeaux->isEmpty()) {
                throw $e;
            }

            return $this->resultat($jumeaux, $fiches, rejeu: true);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => "Paiement non enregistré : {$e->getMessage()}"], 422);
        }

        if ($dejaPayees === null) {
            return $this->resultat(collect($payes)->map(fn (array $p) => $p[1]), $fiches, rejeu: true);
        }

        foreach ($payes as [$fiche, $paiement]) {
            $paiements->apresEnregistrement($fiche, $paiement, $user, groupe: true);
        }

        return response()->json([
            'payees' => count($payes),
            'montant_total' => (float) collect($payes)->sum(fn (array $p) => (float) $p[1]->montant),
            'deja_payees' => $dejaPayees,
            'rejeu' => false,
        ]);
    }

    /**
     * Résultat d'un lot déjà enregistré par cette opération : ses paiements d'origine, et comme
     * « déjà payées » les fiches du lot qu'elle n'a pas payées (elles l'étaient déjà).
     *
     * @param  Collection<int, PaiementFichePaiement>  $paiements
     * @param  Collection<string, PaiementFiche>  $fiches
     */
    private function resultat(Collection $paiements, Collection $fiches, bool $rejeu): JsonResponse
    {
        $payees = $paiements->pluck('fiche_id')->all();

        return response()->json([
            'payees' => $paiements->count(),
            'montant_total' => (float) $paiements->sum(fn (PaiementFichePaiement $p) => (float) $p->montant),
            'deja_payees' => $fiches->reject(fn (PaiementFiche $f) => in_array($f->id, $payees, true))->pluck('beneficiaire_nom')->values()->all(),
            'rejeu' => $rejeu,
        ]);
    }

    private static function gnf(float $montant): string
    {
        return number_format($montant, 0, ',', ' ').' GNF';
    }
}
