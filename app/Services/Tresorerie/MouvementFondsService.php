<?php

namespace App\Services\Tresorerie;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Enums\TypeSupportTresorerie;
use App\Exceptions\Tresorerie\TransitionMouvementFondsInvalideException;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\User;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\MouvementFondsComptabilisationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 *
 * Le support de trésorerie d'ORIGINE est toujours choisi à la création
 * (l'émetteur sait d'où part l'argent). Le support de DESTINATION, lui, est
 * choisi à la réception par `recevoir()`, pas à la création par
 * `creerBrouillon()` — le site destinataire est connu à l'avance, mais pas
 * forcément la caisse/wallet précis qui recevra réellement les fonds tant que
 * le destinataire ne l'a pas confirmé (revue produit du 2026-09-13).
 *
 * Deux natures (NatureMouvementFonds). Le versement d'une caisse dédiée à un agent vers la caisse
 * de l'agence (`interne_caisses`, même site, cf. verserCaisseAgent()) suit le même workflow et les
 * mêmes écritures via le compte de transit, avec des règles propres : destination fixée à
 * l'envoi, et l'envoyeur ne confirme pas lui-même la réception
 * (MouvementFonds::separationEnvoiReceptionRespectee()).
 *
 * Solde suffisant contrôlé sous verrou À L'ENVOI, pour les DEUX natures (garantirSoldeSuffisant(),
 * revue produit du 22/09/2026) : une caisse dont le solde disponible est nul ou insuffisant ne
 * peut envoyer aucun montant, entre agences comme en versement de caisse dédiée.
 */
class MouvementFondsService
{
    public function __construct(
        private readonly MouvementFondsComptabilisationService $comptabilisation,
        private readonly TresorerieDisponibiliteService $disponibilite,
    ) {}

    /**
     * Le support de trésorerie de destination est facultatif ici : choisi au
     * moment de la réception (cf. docblock de la classe et de `recevoir()`).
     *
     * @param  array{site_origine_id:string,site_destination_id:string,compte_tresorerie_origine_id:string,compte_tresorerie_destination_id?:?string,montant:float,moyen_transfert?:?string,reference_externe?:?string,justificatif_path?:?string,commentaire?:?string,echeance_debut?:?string,echeance_fin?:?string}  $data
     */
    public function creerBrouillon(string $organizationId, array $data, ?string $createdBy): MouvementFonds
    {
        if ($data['site_origine_id'] === $data['site_destination_id']) {
            throw new \InvalidArgumentException('Le site d\'origine et le site de destination doivent être différents.');
        }

        $origine = CompteTresorerie::forOrg($organizationId)->findOrFail($data['compte_tresorerie_origine_id']);

        if ($origine->site_id !== $data['site_origine_id']) {
            throw new \InvalidArgumentException('Le support de trésorerie choisi ne correspond pas au site sélectionné.');
        }
        $this->refuserCaisseDediee($origine, 'compte_tresorerie_origine_id');
        $this->refuserSupportNonValide($origine, 'compte_tresorerie_origine_id');

        $destinationId = null;
        if (! empty($data['compte_tresorerie_destination_id'])) {
            $destination = CompteTresorerie::forOrg($organizationId)->findOrFail($data['compte_tresorerie_destination_id']);
            if ($destination->site_id !== $data['site_destination_id']) {
                throw new \InvalidArgumentException('Le support de trésorerie choisi ne correspond pas au site sélectionné.');
            }
            $this->refuserCaisseDediee($destination, 'compte_tresorerie_destination_id');
            $this->refuserSupportNonValide($destination, 'compte_tresorerie_destination_id');
            $destinationId = $destination->id;
        }

        if ((float) $data['montant'] <= 0) {
            throw new \InvalidArgumentException('Le montant du mouvement doit être positif.');
        }

        return MouvementFonds::create([
            'organization_id' => $organizationId,
            'nature' => NatureMouvementFonds::INTER_SITES->value,
            'site_origine_id' => $data['site_origine_id'],
            'site_destination_id' => $data['site_destination_id'],
            'compte_tresorerie_origine_id' => $origine->id,
            'compte_tresorerie_destination_id' => $destinationId,
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

    /**
     * Versement d'une caisse dédiée à un agent vers une caisse de l'agence, au sein d'un même
     * site : crée le mouvement (nature `interne_caisses`) ET l'envoie en une seule opération —
     * pas de brouillon, la correction d'une erreur passe par contestation puis retour. Le solde
     * de la caisse d'agent baisse dès l'envoi (pièce d'envoi vers le compte de transit) ; celui
     * de la caisse d'agence n'augmente qu'à la confirmation de réception par un autre utilisateur.
     *
     * Règles garanties ici, sous verrou sur la caisse source :
     *  - source = caisse dédiée ACTIVE de l'organisation ;
     *  - destination = caisse (type Caisse) d'AGENCE active, du même site — jamais une banque, un
     *    compte Mobile Money, ni la caisse d'un agent ;
     *  - montant > 0 et au plus égal au solde de la caisse source au grand livre.
     *
     * La destination est fixée ICI (contrairement à un mouvement entre agences) : la réception
     * ne demande plus de choix.
     */
    public function verserCaisseAgent(string $organizationId, CompteTresorerie $source, string $destinationId, float $montant, ?string $motif, string $userId): MouvementFonds
    {
        return DB::transaction(function () use ($organizationId, $source, $destinationId, $montant, $motif, $userId) {
            // Verrou sur la caisse source : deux versements simultanés se sérialisent, le second
            // relit le solde déjà diminué par le premier (cf. garantirSoldeSuffisant()).
            $caisse = CompteTresorerie::forOrg($organizationId)->whereKey($source->id)->lockForUpdate()->first();

            if (! $caisse || ! $caisse->isDediee()) {
                throw ValidationException::withMessages([
                    'compte_tresorerie_id' => 'Seule une caisse dédiée à un agent peut être versée à la caisse de l\'agence.',
                ]);
            }
            if (! $caisse->actif) {
                throw ValidationException::withMessages([
                    'compte_tresorerie_id' => $this->supportInactif($caisse, 'elle ne peut pas être versée'),
                ]);
            }

            $destination = CompteTresorerie::forOrg($organizationId)->find($destinationId);
            $this->verifierDestinationDuVersement($caisse, $destination);

            if ($montant <= 0) {
                throw ValidationException::withMessages(['montant' => 'Le montant du versement doit être positif.']);
            }

            $mouvement = MouvementFonds::create([
                'organization_id' => $organizationId,
                'nature' => NatureMouvementFonds::INTERNE_CAISSES->value,
                'site_origine_id' => $caisse->site_id,
                'site_destination_id' => $caisse->site_id,
                'compte_tresorerie_origine_id' => $caisse->id,
                'compte_tresorerie_destination_id' => $destination->id,
                'montant' => $montant,
                'moyen_transfert' => 'especes',
                'commentaire' => $motif !== null && trim($motif) !== '' ? trim($motif) : null,
                'statut' => StatutMouvementFonds::BROUILLON->value,
                'created_by' => $userId,
            ]);

            return $this->envoyer($mouvement, $userId);
        });
    }

    private function verifierDestinationDuVersement(CompteTresorerie $source, ?CompteTresorerie $destination): void
    {
        $champ = 'compte_tresorerie_destination_id';

        if (! $destination) {
            throw ValidationException::withMessages([$champ => 'Caisse de destination introuvable.']);
        }
        if ($destination->isDediee()) {
            throw ValidationException::withMessages([$champ => "« {$destination->libelle} » est la caisse d'un agent : un versement va vers une caisse de l'agence."]);
        }
        if ($destination->type !== TypeSupportTresorerie::CAISSE) {
            throw ValidationException::withMessages([$champ => "« {$destination->libelle} » n'est pas une caisse : un versement d'espèces va vers une caisse de l'agence."]);
        }
        if (! $destination->actif) {
            throw ValidationException::withMessages([$champ => $this->supportInactif($destination)]);
        }
        if ($destination->site_id !== $source->site_id) {
            throw ValidationException::withMessages([$champ => 'La caisse de destination doit appartenir à la même agence que la caisse source.']);
        }
    }

    /**
     * Le solde de la caisse source (grand livre, source de vérité) doit couvrir le montant —
     * qu'il s'agisse d'un versement de caisse dédiée (`interne_caisses`) ou d'un mouvement entre
     * agences (`inter_sites`, cf. revue produit du 22/09/2026 : rien n'empêchait jusque-là un
     * envoi entre agences depuis une caisse à sec, seul le versement de caisse dédiée l'avait).
     * Appelé sous verrou de la caisse (`lockForUpdate()`) : le solde est relu ici, jamais transmis
     * par l'appelant ni mis en cache — deux envois qui se succèdent (concurremment ou non) relisent
     * chacun le solde déjà diminué par le précédent, jamais le solde d'avant.
     */
    private function garantirSoldeSuffisant(MouvementFonds $mouvement, ?\DateTimeInterface $dateEnvoi): void
    {
        if ((float) $mouvement->montant <= 0) {
            throw ValidationException::withMessages([
                'montant' => 'Le montant du mouvement doit être positif.',
            ]);
        }

        $source = CompteTresorerie::whereKey($mouvement->compte_tresorerie_origine_id)->lockForUpdate()->firstOrFail();

        if (! $source->actif) {
            throw ValidationException::withMessages([
                'compte_tresorerie_id' => $this->supportInactif($source, 'elle ne peut pas être versée'),
            ]);
        }

        $solde = $this->disponibilite->soldePourSupport($source, Carbon::instance($dateEnvoi ?? now()));

        if ((float) $mouvement->montant > $solde + 0.004) {
            $disponible = number_format(max($solde, 0), 0, ',', ' ');
            $demande = number_format((float) $mouvement->montant, 0, ',', ' ');
            throw ValidationException::withMessages([
                'montant' => "Solde insuffisant : {$disponible} GNF disponible dans « {$source->libelle} » pour un envoi de {$demande} GNF.",
            ]);
        }
    }

    /**
     * Séparation envoi/réception d'un versement de caisse : celui qui confirme ou conteste n'est
     * pas celui qui a envoyé les fonds (sauf super admin, cf.
     * MouvementFonds::separationEnvoiReceptionRespectee()). $champ désigne le champ d'erreur
     * affiché par l'écran.
     */
    private function garantirSeparationEnvoiReception(MouvementFonds $mouvement, ?string $userId, string $champ): void
    {
        $acteur = $userId !== null ? User::find($userId) : null;

        if (! $mouvement->separationEnvoiReceptionRespectee($acteur)) {
            throw ValidationException::withMessages([
                $champ => 'Vous avez envoyé ces fonds : un autre utilisateur habilité doit confirmer la réception du versement.',
            ]);
        }
    }

    /**
     * Un mouvement entre agences ne part jamais d'une caisse dédiée à un agent
     * et n'y arrive jamais : cet argent transite d'abord par un versement vers la
     * caisse de l'agence. Garde serveur, indépendante des listes proposées par
     * l'écran qui, elles, excluent déjà ces caisses.
     */
    private function refuserCaisseDediee(CompteTresorerie $support, string $champ): void
    {
        if ($support->isDediee()) {
            throw ValidationException::withMessages([
                $champ => "« {$support->libelle} » est une caisse dédiée à un agent : elle ne peut pas être utilisée pour un mouvement entre agences. Versez d'abord son solde à la caisse de l'agence.",
            ]);
        }
    }

    /**
     * Un support en brouillon (jamais validé) est inutilisable : garde serveur, indépendante des
     * listes proposées par l'écran. Un support validé puis désactivé n'est pas concerné ici — c'est
     * le comportement historique des mouvements entre agences.
     */
    private function refuserSupportNonValide(CompteTresorerie $support, string $champ): void
    {
        if (! $support->estValide()) {
            throw ValidationException::withMessages([
                $champ => "« {$support->libelle} » n'est pas encore validé : il ne peut pas être utilisé pour un mouvement.",
            ]);
        }
    }

    /** « X est désactivée [: suite] » ou « X n'est pas encore validée [: suite] » selon l'état du support. */
    private function supportInactif(CompteTresorerie $support, ?string $suite = null): string
    {
        $etat = $support->estValide() ? 'est désactivée' : "n'est pas encore validée";

        return "« {$support->libelle} » {$etat}".($suite !== null ? " : {$suite}." : '.');
    }

    public function envoyer(MouvementFonds $mouvement, ?string $userId, ?\DateTimeInterface $dateEnvoi = null): MouvementFonds
    {
        return DB::transaction(function () use ($mouvement, $userId, $dateEnvoi) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutMouvementFonds::BROUILLON) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'envoyer', [StatutMouvementFonds::BROUILLON]);
            }

            // Toute nature de mouvement : une caisse à sec ou insuffisamment garnie ne peut pas
            // envoyer, cf. docblock de garantirSoldeSuffisant().
            $this->garantirSoldeSuffisant($verrouille, $dateEnvoi);

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
     *
     * Le support de trésorerie de destination est choisi ICI par le
     * destinataire (jamais à la création, cf. creerBrouillon()) : c'est lui
     * qui sait dans quelle caisse/wallet les fonds sont réellement arrivés.
     * Toujours requis, même si le mouvement en portait déjà un (ancien
     * comportement où il était figé à la création) — on ne réutilise jamais
     * silencieusement une valeur non confirmée par le destinataire à cet instant.
     */
    public function recevoir(MouvementFonds $mouvement, ?string $userId, string $compteTresorerieDestinationId, ?\DateTimeInterface $dateReception = null): MouvementFonds
    {
        return DB::transaction(function () use ($mouvement, $userId, $compteTresorerieDestinationId, $dateReception) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($verrouille->statut, [StatutMouvementFonds::ENVOYE, StatutMouvementFonds::CONTESTE], true)) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'recevoir', [StatutMouvementFonds::ENVOYE, StatutMouvementFonds::CONTESTE]);
            }

            $destination = CompteTresorerie::forOrg($verrouille->organization_id)->findOrFail($compteTresorerieDestinationId);
            if ($destination->site_id !== $verrouille->site_destination_id) {
                throw new \InvalidArgumentException('Le support de trésorerie choisi ne correspond pas au site de destination du mouvement.');
            }
            $this->refuserCaisseDediee($destination, 'compte_tresorerie_destination_id');
            $this->refuserSupportNonValide($destination, 'compte_tresorerie_destination_id');

            if ($verrouille->isInterne()) {
                // La caisse de destination d'un versement est fixée à l'envoi : la réception la
                // confirme, elle ne la remplace pas.
                if ($destination->id !== $verrouille->compte_tresorerie_destination_id) {
                    throw ValidationException::withMessages([
                        'compte_tresorerie_destination_id' => 'La caisse de destination d\'un versement est fixée à l\'envoi et ne peut pas être changée.',
                    ]);
                }
                if (! $destination->actif) {
                    throw ValidationException::withMessages([
                        'compte_tresorerie_destination_id' => $this->supportInactif(
                            $destination,
                            $destination->estValide() ? 'réactivez-la avant de confirmer la réception' : 'validez-la avant de confirmer la réception',
                        ),
                    ]);
                }
                $this->garantirSeparationEnvoiReception($verrouille, $userId, 'compte_tresorerie_destination_id');
            }

            $verrouille->date_reception = $dateReception ?? now();
            $verrouille->received_by = $userId;
            $verrouille->compte_tresorerie_destination_id = $destination->id;
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
        return DB::transaction(function () use ($mouvement, $userId, $motif) {
            $verrouille = MouvementFonds::whereKey($mouvement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== StatutMouvementFonds::ENVOYE) {
                throw TransitionMouvementFondsInvalideException::pour($verrouille, 'contester', [StatutMouvementFonds::ENVOYE]);
            }

            if ($verrouille->isInterne()) {
                $this->garantirSeparationEnvoiReception($verrouille, $userId, 'motif');
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
