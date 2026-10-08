<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Models\CompteMapping;
use App\Models\FactureFournisseur;
use App\Models\PieceComptable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Comptabilisation d'une facture fournisseur validée (ADR 0022), par le moteur commun
 * (EcritureComptableService) — aucun numéro de compte ici, uniquement des rôles mappés dans
 * compta_mappings pour l'événement `facture_fournisseur_validee` :
 *
 *   Débit  `achat_{code du type de produit}` (repli : `achat`)   montant HT de ses lignes
 *   Débit  `tva_deductible`                                      montant de TVA (si > 0)
 *   Crédit `fournisseur` (tiers = le fournisseur)                montant TTC
 *
 * Seul `fournisseur` (401000, journal AC) est provisionné. Les comptes d'achat et de TVA
 * déductible attendent la validation du comptable : tant qu'ils ne sont pas mappés, la pièce ne
 * peut pas être passée (MappingComptableIndisponibleException), ce que l'appelant enregistre sans
 * bloquer la validation de la facture.
 */
class FactureFournisseurComptabilisationService
{
    public function __construct(private readonly EcritureComptableService $ecritures) {}

    /**
     * Le statut est relu SOUS VERROU de la facture, dans la même transaction que la pièce : une
     * relance (fiche, rattrapage) lancée sur une facture lue « validée » juste avant son annulation
     * ne peut pas passer une écriture après coup (l'annulation verrouille la même ligne).
     */
    public function comptabiliserFactureValidee(FactureFournisseur $facture): PieceComptable
    {
        return DB::transaction(function () use ($facture) {
            $facture = FactureFournisseur::whereKey($facture->id)->lockForUpdate()->firstOrFail();
            if (! $facture->isConstatee()) {
                throw new \RuntimeException("Facture {$facture->reference} non validée ou annulée : aucune écriture à passer.");
            }

            return $this->passerPiece($facture);
        });
    }

    private function passerPiece(FactureFournisseur $facture): PieceComptable
    {
        $facture->loadMissing(['lignes.variante.produit.produitType', 'fournisseur']);

        $parRole = [];
        foreach ($facture->lignes as $ligne) {
            $role = $this->roleAchat($facture->organization_id, $ligne->variante?->produit?->produitType?->code);
            $parRole[$role] = ($parRole[$role] ?? 0) + (float) $ligne->total_ht;
        }

        $lignes = [];
        foreach ($parRole as $role => $montant) {
            if ($montant > 0) {
                $lignes[] = ['role' => $role, 'sens' => 'debit', 'montant' => $montant];
            }
        }
        if ((float) $facture->montant_tva > 0) {
            $lignes[] = ['role' => 'tva_deductible', 'sens' => 'debit', 'montant' => (float) $facture->montant_tva];
        }
        $lignes[] = [
            'role' => 'fournisseur',
            'sens' => 'credit',
            'montant' => (float) $facture->montant_ttc,
            'tiers_type' => 'fournisseur',
            'tiers_model' => $facture->fournisseur,
        ];

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE,
            source: $facture,
            organizationId: $facture->organization_id,
            dateComptable: Carbon::parse($facture->date_facture),
            libelle: "Facture fournisseur {$facture->numero_facture_fournisseur} — {$facture->reference}",
            lignes: $lignes,
            siteId: $facture->site_id,
            createdBy: $facture->validee_par,
        );
    }

    public function pieceDe(FactureFournisseur $facture): ?PieceComptable
    {
        return $this->ecritures->pieceExistantePour($facture->organization_id, $facture, EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE);
    }

    /**
     * État comptable affiché, distinct du statut de la facture : une facture validée n'est jamais
     * présentée comme « comptabilisée » si sa pièce n'existe pas.
     *
     * @return array{statut: string, label: string, piece_numero: ?string, extourne_numero: ?string}
     */
    public function etat(FactureFournisseur $facture, ?PieceComptable $piece = null, bool $pieceConnue = false): array
    {
        $piece = $pieceConnue ? $piece : $this->pieceDe($facture);
        $extourne = $piece && ! $piece->isValidee()
            ? PieceComptable::where('piece_origine_id', $piece->id)->value('numero')
            : null;

        [$statut, $label] = match (true) {
            $piece !== null && $piece->isValidee() => ['comptabilisee', 'Comptabilisée'],
            $piece !== null => ['contrepassee', 'Contrepassée'],
            $facture->isConstatee() => ['en_attente', 'En attente de paramétrage'],
            default => ['sans_objet', $facture->isBrouillon() ? 'Pas encore (brouillon)' : 'Aucune écriture'],
        };

        return ['statut' => $statut, 'label' => $label, 'piece_numero' => $piece?->numero, 'extourne_numero' => $extourne];
    }

    /** Annulation d'une facture validée : contrepassation, jamais de suppression. */
    public function annuler(FactureFournisseur $facture, string $motif, ?string $userId): ?PieceComptable
    {
        $piece = $this->pieceDe($facture);

        return $piece && $piece->isValidee()
            ? $this->ecritures->contrepasser($piece, "Facture fournisseur annulée : {$motif}", $userId)
            : null;
    }

    /** Rôle d'achat propre au type de produit s'il est mappé, sinon le rôle générique `achat`. */
    private function roleAchat(string $organizationId, ?string $codeType): string
    {
        if ($codeType !== null) {
            $specifique = 'achat_'.$codeType;
            $existe = CompteMapping::where('organization_id', $organizationId)
                ->where('evenement', EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value)
                ->where('role', $specifique)
                ->where('actif', true)
                ->exists();
            if ($existe) {
                return $specifique;
            }
        }

        return 'achat';
    }
}
