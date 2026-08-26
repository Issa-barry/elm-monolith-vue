<?php

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionGenerationAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Support de test E2E UNIQUEMENT — jamais routé en dehors de APP_ENV=e2e (cf.
 * routes/web.php, gate `app()->environment('e2e')` autour de l'enregistrement de
 * la route, pas juste un contrôle en runtime : la route n'existe littéralement pas
 * ailleurs).
 *
 * Playwright ne peut pas lire la base de données directement (pas de client MySQL
 * côté Node dans ce projet) — ce contrôleur expose en lecture seule l'état réel de
 * commission_enveloppes/lignes/parts pour une commande, pour permettre aux specs
 * tests/e2e/commissions/*.spec.ts de faire de vraies assertions "niveau base" (pas
 * seulement visuelles) sans court-circuiter le moteur applicatif : la génération
 * elle-même reste toujours déclenchée par le vrai parcours UI.
 *
 * Scopé par organisation (auth()->user()->organization_id) même si e2e-only, par
 * cohérence avec le reste de l'application plutôt que par nécessité de sécurité.
 */
class CommissionE2eDiagnosticController extends Controller
{
    public function commande(Request $request, string $commandeId): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $commande = CommandeVente::where('organization_id', $orgId)
            ->with('facture.encaissements')
            ->find($commandeId);
        if (! $commande) {
            return response()->json(['message' => 'Commande introuvable pour cette organisation.'], 404);
        }

        $attempts = CommissionGenerationAttempt::where('organization_id', $orgId)
            ->where('source_type', CommandeVente::class)
            ->where('source_id', $commandeId)
            ->orderBy('created_at')
            ->get(['id', 'statut', 'motif_erreur', 'created_at'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'statut' => $a->statut?->value ?? (string) $a->statut,
                'motif_erreur' => $a->motif_erreur,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        $enveloppes = CommissionEnveloppe::where('organization_id', $orgId)
            ->where('source_type', CommandeVente::class)
            ->where('source_id', $commandeId)
            ->with(['lignes', 'parts'])
            ->get()
            ->map(fn (CommissionEnveloppe $e) => [
                'id' => $e->id,
                'cible_type' => $e->cible_type,
                'cible_id' => $e->cible_id,
                'montant_total' => (float) $e->montant_total,
                'statut' => $e->statut?->value ?? (string) $e->statut,
                'earned_at' => $e->earned_at?->toDateString(),
                'lignes_count' => $e->lignes->count(),
                'lignes' => $e->lignes->map(fn ($l) => [
                    'id' => $l->id,
                    'variante_id' => $l->variante_id,
                    'categorie_id_snapshot' => $l->categorie_id_snapshot,
                    'quantite' => (float) $l->quantite,
                    'montant_ligne' => (float) $l->montant_ligne,
                ]),
                'parts' => $e->parts->map(fn ($p) => [
                    'id' => $p->id,
                    'beneficiaire_type' => $p->beneficiaire_type,
                    'beneficiaire_id' => $p->beneficiaire_id,
                    'montant_brut' => (float) $p->montant_brut,
                    'montant_net' => (float) $p->montant_net,
                    'statut' => $p->statut?->value ?? (string) $p->statut,
                ]),
            ]);

        return response()->json([
            'commande' => [
                'id' => $commande->id,
                'statut' => $commande->statut?->value ?? (string) $commande->statut,
                'commission_eligible_snapshot' => (bool) $commande->commission_eligible_snapshot,
            ],
            'facture' => $commande->facture ? [
                'id' => $commande->facture->id,
                'statut' => $commande->facture->statut?->value ?? (string) $commande->facture->statut,
                'encaissements' => $commande->facture->encaissements->map(fn ($e) => [
                    'id' => $e->id,
                    'montant' => (float) $e->montant,
                ]),
            ] : null,
            'generation_attempts' => $attempts,
            'enveloppes' => $enveloppes,
            'enveloppes_count' => $enveloppes->count(),
            'parts_count' => $enveloppes->sum('lignes_count') !== null ? $enveloppes->sum(fn ($e) => count($e['parts'])) : 0,
        ]);
    }
}
