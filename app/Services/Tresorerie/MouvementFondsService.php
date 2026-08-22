<?php

namespace App\Services\Tresorerie;

use App\Enums\StatutMouvementFonds;
use App\Exceptions\Tresorerie\TransitionMouvementFondsInvalideException;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\MouvementFondsComptabilisationService;
use Illuminate\Support\Facades\DB;

/**
 * Workflow du mouvement de fonds : brouillon -> envoyé -> reçu, avec
 * possibilité d'annuler (tant que non reçu) ou de rejeter à réception (fonds
 * contestés par le site destinataire). Chaque transition est transactionnelle
 * et protégée contre le double déclenchement en revérifiant le statut ATTENDU
 * sous verrou de ligne (lockForUpdate) juste avant d'agir — l'idempotence
 * réelle des écritures elles-mêmes vient en plus de EcritureComptableService
 * (contrainte unique compta_pieces_idempotency_unique).
 */
class MouvementFondsService
{
    public function __construct(
        private readonly MouvementFondsComptabilisationService $comptabilisation,
    ) {}

    /** @param  array{site_origine_id:string,site_destination_id:string,compte_tresorerie_origine_id:string,compte_tresorerie_destination_id:string,montant:float,moyen_transfert?:?string,reference_externe?:?string,justificatif_path?:?string,commentaire?:?string}  $data */
    public function creerBrouillon(string $organizationId, array $data, ?string $createdBy): MouvementFonds
    {
        if ($data['site_origine_id'] === $data['site_destination_id']) {
            throw new \InvalidArgumentException('Le site d\'origine et le site de destination doivent être différents.');
        }

        $origine = CompteTresorerie::forOrg($organizationId)->findOrFail($data['compte_tresorerie_origine_id']);
        $destination = CompteTresorerie::forOrg($organizationId)->findOrFail($data['compte_tresorerie_destination_id']);

        if ($origine->site_id !== $data['site_origine_id'] || $destination->site_id !== $data['site_destination_id']) {
            throw new \InvalidArgumentException('Le support de trésorerie choisi ne correspond pas au site sélectionné.');
        }

        if ((float) $data['montant'] <= 0) {
            throw new \InvalidArgumentException('Le montant du mouvement doit être positif.');
        }

        return MouvementFonds::create([
            'organization_id' => $organizationId,
            'site_origine_id' => $data['site_origine_id'],
            'site_destination_id' => $data['site_destination_id'],
            'compte_tresorerie_origine_id' => $origine->id,
            'compte_tresorerie_destination_id' => $destination->id,
            'montant' => $data['montant'],
            'moyen_transfert' => $data['moyen_transfert'] ?? null,
            'reference_externe' => $data['reference_externe'] ?? null,
            'justificatif_path' => $data['justificatif_path'] ?? null,
            'commentaire' => $data['commentaire'] ?? null,
            'statut' => StatutMouvementFonds::BROUILLON->value,
            'created_by' => $createdBy,
        ]);
    }

    public function envoyer(MouvementFonds $mouvement, ?string $userId, ?\DateTimeInterface $dateEnvoi = null): MouvementFonds
    {
        return DB::transaction(function () use ($mouvement, $userId, $dateEnvoi) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutMouvementFonds::BROUILLON) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'envoyer', [StatutMouvementFonds::BROUILLON]);
            }

            $verrouille->date_envoi = $dateEnvoi ?? now();
            $verrouille->sent_by = $userId;
            $verrouille->statut = StatutMouvementFonds::ENVOYE->value;
            $verrouille->save();

            $piece = $this->comptabilisation->comptabiliserEnvoi($verrouille->fresh());
            $verrouille->update(['piece_comptable_envoi_id' => $piece->id]);

            return $verrouille->fresh();
        });
    }

    public function recevoir(MouvementFonds $mouvement, ?string $userId, ?\DateTimeInterface $dateReception = null): MouvementFonds
    {
        return DB::transaction(function () use ($mouvement, $userId, $dateReception) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutMouvementFonds::ENVOYE) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'recevoir', [StatutMouvementFonds::ENVOYE]);
            }

            $verrouille->date_reception = $dateReception ?? now();
            $verrouille->received_by = $userId;
            $verrouille->statut = StatutMouvementFonds::RECU->value;
            $verrouille->save();

            $piece = $this->comptabilisation->comptabiliserReception($verrouille->fresh());
            $verrouille->update(['piece_comptable_reception_id' => $piece->id]);

            return $verrouille->fresh();
        });
    }

    /** Annulation : uniquement tant qu'aucun fonds n'a quitté l'origine (BROUILLON). */
    public function annuler(MouvementFonds $mouvement, ?string $userId, string $motif): MouvementFonds
    {
        return DB::transaction(function () use ($mouvement, $userId, $motif) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutMouvementFonds::BROUILLON) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'annuler', [StatutMouvementFonds::BROUILLON]);
            }

            $verrouille->update([
                'statut' => StatutMouvementFonds::ANNULE->value,
                'cancelled_by' => $userId,
                'motif_annulation' => $motif,
            ]);

            return $verrouille->fresh();
        });
    }

    /**
     * Rejet à réception : le site destinataire conteste des fonds déjà
     * envoyés (jamais reçus physiquement, écart de justificatif...). Contrepasse
     * la pièce d'émission — les fonds ne redeviennent PAS automatiquement
     * "disponibles" à l'origine par une simple suppression, cf. règle générale
     * de contrepassation du moteur comptable.
     */
    public function rejeter(MouvementFonds $mouvement, ?string $userId, string $motif): MouvementFonds
    {
        return DB::transaction(function () use ($mouvement, $userId, $motif) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutMouvementFonds::ENVOYE) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'rejeter', [StatutMouvementFonds::ENVOYE]);
            }

            if ($verrouille->pieceEnvoi) {
                app(EcritureComptableService::class)
                    ->contrepasser($verrouille->pieceEnvoi, "Rejet mouvement {$verrouille->reference} — {$motif}", $userId);
            }

            $verrouille->update([
                'statut' => StatutMouvementFonds::REJETE->value,
                'cancelled_by' => $userId,
                'motif_annulation' => $motif,
            ]);

            return $verrouille->fresh();
        });
    }
}
