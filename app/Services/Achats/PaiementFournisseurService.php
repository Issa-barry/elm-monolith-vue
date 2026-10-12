<?php

namespace App\Services\Achats;

use App\Enums\ModePaiement;
use App\Enums\StatutFactureFournisseur;
use App\Models\FactureFournisseur;
use App\Models\PaiementFournisseur;
use App\Models\Site;
use App\Models\User;
use App\Services\Comptabilite\FactureFournisseurComptabilisationService;
use App\Services\Tresorerie\DecaissementSupportResolver;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paiement d'une facture fournisseur (ADR 0024) = décaissement réel, sur le modèle du paiement des
 * fiches (ADR 0009), sans règle parallèle :
 * - facture validée ou partiellement payée ; montant ≤ reste dû, relu SOUS VERROU de la facture ;
 * - argent sortant d'un support de l'AGENCE QUI PAIE (DecaissementSupportResolver : caisse dédiée
 *   du payeur en espèces, sinon un moyen actif de cette agence), solde garanti sous verrou. Par
 *   défaut c'est l'agence de la facture (« Payé par » du bon) ; depuis la révision du 12/10/2026 le
 *   payeur peut en choisir une autre de son périmètre d'achat (virement ou chèque de la trésorerie
 *   principale, espèces remises à un agent d'une autre agence). La dette reste unique, portée par
 *   la facture ; chaque paiement garde l'agence d'où l'argent est réellement sorti (`site_id`) ;
 * - paiement, statut de la facture et pièce comptable dans UNE transaction : si l'écriture échoue,
 *   rien n'est enregistré (le solde du support est lu au grand livre) ;
 * - permission `factures-fournisseurs.payer` + agence de la facture ET agence qui paie couvertes
 *   par « Peut acheter pour », sans passe-droit de rôle (vérifié ici, jamais seulement par la
 *   policy : Gate::before). Choisir un support n'élargit aucun droit.
 */
class PaiementFournisseurService
{
    public function __construct(
        private readonly DecaissementSupportResolver $supports,
        private readonly TresorerieDisponibiliteService $disponibilite,
        private readonly PerimetreCommandesAchat $perimetre,
        private readonly FactureFournisseurComptabilisationService $comptabilisation,
    ) {}

    public static function regles(): array
    {
        return [
            'montant' => ['required', 'numeric', 'min:1'],
            'mode_paiement' => ['required', 'in:'.implode(',', array_column(ModePaiement::cases(), 'value'))],
            'compte_tresorerie_id' => ['nullable', 'string', 'required_unless:mode_paiement,'.ModePaiement::ESPECES->value],
            // Agence d'où sort l'argent ; absente = l'agence de la facture.
            'site_paiement_id' => ['nullable', 'string'],
            'reference_paiement' => ['nullable', 'string', 'max:190'],
            'date_paiement' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'compte_tresorerie_id.required_unless' => "Choisissez le compte d'où sort ce paiement.",
            'date_paiement.before_or_equal' => 'La date de paiement ne peut pas être dans le futur.',
        ];
    }

    /** Pourquoi cet utilisateur ne peut pas payer cette facture, ou null s'il le peut. */
    public function motifNonPayable(FactureFournisseur $facture, User $user): ?string
    {
        if (! in_array($facture->statut, [StatutFactureFournisseur::VALIDEE, StatutFactureFournisseur::PARTIELLEMENT_PAYEE], true)) {
            return 'Seule une facture validée et non soldée peut être payée.';
        }
        // checkPermissionTo() lit les permissions réelles des rôles — jamais le Gate::before.
        if (! $user->checkPermissionTo('factures-fournisseurs.payer')) {
            return "Vous n'avez pas la permission de payer les factures d’achat.";
        }
        if (! $this->perimetre->couvreSite($user, $facture->site_id)) {
            return "L'agence de cette facture n'est pas dans votre périmètre d'achat.";
        }
        // Révision du 10/10/2026 (ADR 0024) : pas de paiement tant que l'écriture de la facture
        // n'est pas passée, sinon le 401 deviendrait débiteur en attendant le rattrapage.
        if (! $this->comptabilisation->pieceDe($facture)?->isValidee()) {
            return "Paiement impossible : l'écriture comptable de cette facture est en attente (compte d'achat ou de TVA non paramétré). Faites paramétrer les comptes, relancez la comptabilisation de la facture, puis payez-la.";
        }

        return null;
    }

    /**
     * Agences d'où cet utilisateur peut payer cette facture, pour le dialogue de paiement : celles
     * de son périmètre d'achat, avec leurs moyens et sa caisse dédiée sur chacune. L'agence de la
     * facture vient en premier (choix par défaut) ; les autres ne sont listées que si un paiement y
     * est réellement possible (un moyen actif ou une caisse du payeur).
     *
     * @return list<array{site_id: string, nom: string, moyens: list<array<string, mixed>>, peut_encaisser_especes: bool, solde_especes: ?float}>
     */
    public function agencesDePaiement(FactureFournisseur $facture, User $user): array
    {
        $sites = $this->perimetre->sites($user);
        $options = $this->supports->optionsPourSites($facture->organization_id, $sites->pluck('id'), $user);

        return $sites
            ->map(fn (Site $site) => [
                'site_id' => $site->id,
                'nom' => $site->nom,
                'moyens' => $options[$site->id]['moyens'] ?? [],
                'peut_encaisser_especes' => $options[$site->id]['especes_disponibles'] ?? false,
                'solde_especes' => $options[$site->id]['solde_especes'] ?? null,
            ])
            ->filter(fn (array $a) => $a['site_id'] === $facture->site_id || $a['moyens'] !== [] || $a['peut_encaisser_especes'])
            ->sortBy(fn (array $a) => $a['site_id'] === $facture->site_id ? 0 : 1)
            ->values()
            ->all();
    }

    public function payer(FactureFournisseur $facture, User $user, array $data): PaiementFournisseur
    {
        $refus = $this->motifNonPayable($facture, $user);
        if ($refus !== null) {
            throw ValidationException::withMessages(['paiement' => $refus]);
        }

        $sitePaiementId = $this->sitePaiementId($facture, $user, $data);
        $support = $this->supports->supportPour($facture->organization_id, $sitePaiementId, $user, $data['mode_paiement'], $data['compte_tresorerie_id'] ?? null);

        if ($this->supports->referenceRequise($facture->organization_id, $sitePaiementId, $data['mode_paiement'], $support->id) && blank($data['reference_paiement'] ?? null)) {
            throw ValidationException::withMessages(['reference_paiement' => 'La référence du paiement est obligatoire pour ce mode de paiement.']);
        }

        return DB::transaction(function () use ($facture, $user, $data, $support, $sitePaiementId) {
            // Sous verrou : deux paiements concurrents de la même facture relisent le reste dû déjà
            // diminué (lecture verrouillante, dernière version validée).
            $facture = FactureFournisseur::whereKey($facture->id)->lockForUpdate()->firstOrFail();

            $refus = $this->motifNonPayable($facture, $user);
            if ($refus !== null) {
                throw ValidationException::withMessages(['paiement' => $refus]);
            }

            $montant = round((float) $data['montant'], 2);
            $resteDu = $facture->resteDu();
            if ($montant > $resteDu + 0.004) {
                throw ValidationException::withMessages([
                    'montant' => 'Le montant dépasse le reste dû de la facture ('.number_format($resteDu, 0, ',', ' ').' GNF).',
                ]);
            }

            // Solde du support relu au grand livre, sous verrou, juste avant la sortie.
            $this->disponibilite->garantirSoldeSuffisant($support->id, $montant, now(), 'un paiement');

            $paiement = PaiementFournisseur::create([
                'organization_id' => $facture->organization_id,
                'facture_fournisseur_id' => $facture->id,
                'fournisseur_id' => $facture->fournisseur_id,
                // Agence d'où l'argent est sorti — pas forcément celle de la facture.
                'site_id' => $sitePaiementId,
                'montant' => $montant,
                'mode_paiement' => $data['mode_paiement'],
                'moyen_paiement_detail' => $support->operateur_mobile_money?->value,
                'compte_tresorerie_id' => $support->id,
                'reference_paiement' => $data['reference_paiement'] ?? null,
                'date_paiement' => $data['date_paiement'],
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            $paye = round((float) $facture->montant_paye + $montant, 2);
            $facture->update([
                'montant_paye' => $paye,
                'statut' => $paye + 0.004 >= (float) $facture->montant_ttc
                    ? StatutFactureFournisseur::PAYEE
                    : StatutFactureFournisseur::PARTIELLEMENT_PAYEE,
            ]);

            $this->comptabilisation->comptabiliserPaiement($paiement);

            return $paiement;
        });
    }

    /**
     * Agence d'où sort l'argent : celle de la facture par défaut, sinon une agence de l'organisation
     * que le périmètre d'achat du payeur couvre. Contrôle serveur, indépendant de la liste affichée.
     *
     * @throws ValidationException
     */
    private function sitePaiementId(FactureFournisseur $facture, User $user, array $data): string
    {
        $siteId = $data['site_paiement_id'] ?? null;
        if (blank($siteId) || $siteId === $facture->site_id) {
            return $facture->site_id;
        }

        $dansOrganisation = Site::where('organization_id', $facture->organization_id)->whereKey($siteId)->exists();
        if (! $dansOrganisation || ! $this->perimetre->couvreSite($user, $siteId)) {
            throw ValidationException::withMessages([
                'site_paiement_id' => "Vous ne pouvez pas payer depuis cette agence : elle n'est pas dans votre périmètre d'achat.",
            ]);
        }

        return $siteId;
    }
}
