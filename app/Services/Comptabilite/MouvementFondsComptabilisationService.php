<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Models\MouvementFonds;
use App\Models\PieceComptable;
use Illuminate\Support\Carbon;

/**
 * Traduit un MouvementFonds (agence <-> siège) en écritures SYSCOHADA via le
 * compte de virements internes (58 — "en transit"), technique standard pour
 * de l'argent qui a quitté une caisse/banque sans être encore arrivé à
 * destination.
 *
 * Volontairement DEUX pièces mono-site plutôt qu'une seule pièce à deux sites :
 *   - à l'envoi   : débit 58 / crédit trésorerie ORIGINE     (site = origine)
 *   - à la réception : débit trésorerie DESTINATION / crédit 58  (site = destination)
 * Ce choix évite de faire porter un site_id par LIGNE à EcritureComptableService
 * (qui applique aujourd'hui un seul site_id à toute la pièce) — chaque pièce
 * reste cohérente avec toutes les autres pièces du système (un seul site par
 * pièce), et le solde du compte 58 au niveau organisation reflète correctement
 * les fonds en transit (il se solde à 0 dès que la réception est postée).
 *
 * Le compte de trésorerie de chaque jambe est déjà résolu par le
 * CompteTresorerie du mouvement — jamais via CompteMappingResolver (qui ne
 * connaît que des comptes génériques par moyen de paiement, pas un support
 * précis choisi par l'utilisateur) : la ligne trésorerie porte donc
 * directement `compte_comptable_id`, cf. EcritureComptableService::comptabiliser()
 * qui accepte ce cas (comme DepenseComptabilisationService pour les comptes
 * de charge spécifiques à un DepenseType).
 */
class MouvementFondsComptabilisationService
{
    public function __construct(
        private readonly EcritureComptableService $ecritures,
    ) {}

    public function comptabiliserEnvoi(MouvementFonds $mouvement): PieceComptable
    {
        $origine = $mouvement->compteTresorerieOrigine;

        $lignes = [
            [
                'role' => 'fonds_transit',
                'sens' => 'debit',
                'montant' => (float) $mouvement->montant,
            ],
            [
                'compte_comptable_id' => $origine->compte_comptable_id,
                'sens' => 'credit',
                'montant' => (float) $mouvement->montant,
                'libelle' => "Envoi fonds {$mouvement->reference} — {$origine->libelle}",
            ],
        ];

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::MOUVEMENT_FONDS_ENVOYE,
            source: $mouvement,
            organizationId: $mouvement->organization_id,
            dateComptable: Carbon::parse($mouvement->date_envoi ?? now()),
            libelle: "Mouvement de fonds {$mouvement->reference} — envoi",
            lignes: $lignes,
            siteId: $mouvement->site_origine_id,
            createdBy: $mouvement->sent_by,
        );
    }

    public function comptabiliserReception(MouvementFonds $mouvement): PieceComptable
    {
        $destination = $mouvement->compteTresorerieDestination;

        $lignes = [
            [
                'compte_comptable_id' => $destination->compte_comptable_id,
                'sens' => 'debit',
                'montant' => (float) $mouvement->montant,
                'libelle' => "Réception fonds {$mouvement->reference} — {$destination->libelle}",
            ],
            [
                'role' => 'fonds_transit',
                'sens' => 'credit',
                'montant' => (float) $mouvement->montant,
            ],
        ];

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::MOUVEMENT_FONDS_RECU,
            source: $mouvement,
            organizationId: $mouvement->organization_id,
            dateComptable: Carbon::parse($mouvement->date_reception ?? now()),
            libelle: "Mouvement de fonds {$mouvement->reference} — réception",
            lignes: $lignes,
            siteId: $mouvement->site_destination_id,
            createdBy: $mouvement->received_by,
        );
    }
}
