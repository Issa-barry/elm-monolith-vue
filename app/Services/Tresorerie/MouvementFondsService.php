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
 * possibilité d'annuler (tant que non envoyé) ou de contester à réception
 * (fonds non reçus selon le destinataire). Une contestation ne contrepasse
 * rien tant qu'elle n'est pas résolue — cf. contester()/confirmerRetour().
 * Chaque transition est transactionnelle et protégée contre le double
 * déclenchement en revérifiant le statut ATTENDU sous verrou de ligne
 * (lockForUpdate) juste avant d'agir — l'idempotence réelle des écritures
 * elles-mêmes vient en plus de EcritureComptableService (contrainte unique
 * compta_pieces_idempotency_unique).
 */
class MouvementFondsService
{
    public function __construct(
        private readonly MouvementFondsComptabilisationService $comptabilisation,
    ) {}

    /** @param  array{site_origine_id:string,site_destination_id:string,compte_tresorerie_origine_id:string,compte_tresorerie_destination_id:string,montant:float,moyen_transfert?:?string,reference_externe?:?string,justificatif_path?:?string,commentaire?:?string,echeance_debut?:?string,echeance_fin?:?string}  $data */
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
            'echeance_debut' => $data['echeance_debut'] ?? null,
            'echeance_fin' => $data['echeance_fin'] ?? null,
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

    /**
     * Acceptée depuis ENVOYE (cas nominal) ou CONTESTE (l'investigation a
     * montré que les fonds avaient bien été reçus — contestation levée).
     */
    public function recevoir(MouvementFonds $mouvement, ?string $userId, ?\DateTimeInterface $dateReception = null): MouvementFonds
    {
        return DB::transaction(function () use ($mouvement, $userId, $dateReception) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($verrouille->statut, [StatutMouvementFonds::ENVOYE, StatutMouvementFonds::CONTESTE], true)) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'recevoir', [StatutMouvementFonds::ENVOYE, StatutMouvementFonds::CONTESTE]);
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
     * Contestation à réception : le site destinataire déclare ne pas avoir
     * reçu les fonds. AUCUNE contrepassation ici — une contestation n'est pas
     * une preuve que l'argent est physiquement revenu à l'origine, seulement
     * qu'un litige existe (revue Codex du 2026-08-22). Le mouvement reste "en
     * transit" (cf. StatutMouvementFonds::isEnTransit()) jusqu'à ce
     * qu'une investigation tranche via recevoir() ou confirmerRetour().
     */
    public function contester(MouvementFonds $mouvement, ?string $userId, string $motif): MouvementFonds
    {
        return DB::transaction(function () use ($mouvement, $motif) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutMouvementFonds::ENVOYE) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'contester', [StatutMouvementFonds::ENVOYE]);
            }

            $verrouille->update([
                'statut' => StatutMouvementFonds::CONTESTE->value,
                'motif_annulation' => $motif,
            ]);

            return $verrouille->fresh();
        });
    }

    /**
     * Retour confirmé : l'investigation d'une contestation a établi que les
     * fonds ont été physiquement rapportés à l'origine. Seule cette
     * transition contrepasse la pièce d'émission — la caisse d'origine ne
     * redevient "disponible" qu'à ce moment précis, jamais au simple constat
     * d'une contestation.
     */
    public function confirmerRetour(MouvementFonds $mouvement, ?string $userId, string $motif): MouvementFonds
    {
        return DB::transaction(function () use ($mouvement, $userId, $motif) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutMouvementFonds::CONTESTE) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'confirmerRetour', [StatutMouvementFonds::CONTESTE]);
            }

            if ($verrouille->pieceEnvoi) {
                app(EcritureComptableService::class)
                    ->contrepasser($verrouille->pieceEnvoi, "Retour confirmé mouvement {$verrouille->reference} — {$motif}", $userId);
            }

            $verrouille->update([
                'statut' => StatutMouvementFonds::RETOURNE->value,
                'cancelled_by' => $userId,
                'motif_annulation' => $motif,
            ]);

            return $verrouille->fresh();
        });
    }
}
