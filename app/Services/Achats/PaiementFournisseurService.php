<?php

namespace App\Services\Achats;

use App\Enums\ModePaiement;
use App\Enums\StatutFactureFournisseur;
use App\Models\FactureFournisseur;
use App\Models\PaiementFournisseur;
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
 * - argent sortant d'un support de l'AGENCE DE LA FACTURE (DecaissementSupportResolver : caisse
 *   dédiée du payeur en espèces, sinon un moyen actif de l'agence), solde garanti sous verrou ;
 * - paiement, statut de la facture et pièce comptable dans UNE transaction : si l'écriture échoue,
 *   rien n'est enregistré (le solde du support est lu au grand livre) ;
 * - permission `factures-fournisseurs.payer` + agence couverte par « Peut acheter pour », sans
 *   passe-droit de rôle (vérifié ici, jamais seulement par la policy : Gate::before).
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

        return null;
    }

    public function payer(FactureFournisseur $facture, User $user, array $data): PaiementFournisseur
    {
        $refus = $this->motifNonPayable($facture, $user);
        if ($refus !== null) {
            throw ValidationException::withMessages(['paiement' => $refus]);
        }

        $support = $this->supports->supportPour($facture->organization_id, $facture->site_id, $user, $data['mode_paiement'], $data['compte_tresorerie_id'] ?? null);

        if ($this->supports->referenceRequise($facture->organization_id, $facture->site_id, $data['mode_paiement'], $support->id) && blank($data['reference_paiement'] ?? null)) {
            throw ValidationException::withMessages(['reference_paiement' => 'La référence du paiement est obligatoire pour ce mode de paiement.']);
        }

        return DB::transaction(function () use ($facture, $user, $data, $support) {
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
                'site_id' => $facture->site_id,
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
}
