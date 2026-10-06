<?php

namespace App\Services\Ventes;

use App\Enums\ModePaiement;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\User;
use App\Services\Tresorerie\AgenceEncaissementResolver;
use App\Services\Tresorerie\CaisseAgentResolver;
use App\Services\Tresorerie\MoyensEncaissementResolver;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Contrôles serveur d'une saisie d'encaissement de vente, partagés par l'encaissement d'une facture
 * (Ventes\StoreEncaissementVenteController) et l'acompte d'une précommande (ADR 0019) : un seul
 * circuit de paiement, jamais deux jeux de règles qui pourraient diverger.
 *
 * mode_paiement reste l'une des 4 valeurs génériques (especes/mobile_money/virement/cheque) attendues
 * par la comptabilisation — jamais un opérateur (cf. docs/encaissements.md). Hors espèces,
 * l'utilisateur choisit un SUPPORT de trésorerie de l'agence d'encaissement (`compte_tresorerie_id`,
 * décision du 24/09/2026) : c'est lui qui détermine le compte débité et, pour le Mobile Money,
 * l'opérateur — jamais une valeur libre venue du navigateur. Référence obligatoire pour Mobile Money
 * et Virement (rapprochement), unique dans l'organisation pour le Mobile Money (ADR 0014).
 */
class SaisieEncaissementVente
{
    public function __construct(
        private readonly AgenceEncaissementResolver $agences,
        private readonly MoyensEncaissementResolver $moyens,
        private readonly CaisseAgentResolver $caisses,
    ) {}

    /**
     * Règles de validation des champs de paiement (hors montant, propre à chaque appelant).
     *
     * @return array<string, mixed>
     */
    public static function regles(): array
    {
        return [
            'date_encaissement' => 'nullable|date',
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'compte_tresorerie_id' => [
                'nullable', 'string',
                'required_unless:mode_paiement,'.ModePaiement::ESPECES->value,
            ],
            'reference_paiement' => [
                'nullable', 'string', 'max:190',
                'required_if:mode_paiement,'.ModePaiement::MOBILE_MONEY->value.','.ModePaiement::VIREMENT->value,
            ],
            'note' => 'nullable|string|max:2000',
            'site_encaissement_id' => 'nullable|string',
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'mode_paiement.in' => 'Mode de paiement invalide.',
            'compte_tresorerie_id.required_unless' => 'Choisissez le compte qui reçoit ce paiement.',
            'reference_paiement.required_if' => 'La reference du paiement est obligatoire pour ce mode de paiement.',
        ];
    }

    /**
     * Résout et contrôle la saisie validée ; renvoie les attributs de l'encaissement à créer
     * (`site_encaissement_id`, `mode_paiement`, `operateur_mobile_money`, `compte_tresorerie_id`,
     * `reference_paiement`, `date_encaissement`, `note`). Aucune écriture.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function preparer(User $user, FactureVente $facture, array $data): array
    {
        // Agence qui reçoit réellement l'argent (ADR 0012) : toujours une agence de l'utilisateur qui
        // encaisse — jamais l'agence de la facture par défaut ; une agence autre que celle de la
        // commande exige `factures.encaisser_autre_agence` ; sans agence d'affectation, refus.
        // Tout ce qui suit (moyens, caisse dédiée, pièce comptable) se rapporte à CETTE agence.
        $siteEncaissementId = $this->agences->resoudre($user, $facture, $data['site_encaissement_id'] ?? null);

        // Un moyen n'est accepté que s'il figure dans la liste proposée pour l'agence d'encaissement
        // (support actif de cette agence, du bon type/opérateur) — même source que PaymentCard.
        $operateur = null;
        $compteTresorerieId = $data['compte_tresorerie_id'] ?? null;
        if ($data['mode_paiement'] === ModePaiement::ESPECES->value) {
            $compteTresorerieId = null;
        } else {
            $support = $this->moyens->supportPour($facture->organization_id, $siteEncaissementId, $compteTresorerieId, $data['mode_paiement']);

            if (! $support) {
                throw ValidationException::withMessages([
                    'compte_tresorerie_id' => "Ce moyen de paiement n'est pas disponible dans l'agence d'encaissement : aucun support de trésorerie actif ne peut le recevoir.",
                ]);
            }

            $operateur = $support->operateur_mobile_money?->value;
        }

        $reference = $data['reference_paiement'] ?? null;
        if ($data['mode_paiement'] === ModePaiement::MOBILE_MONEY->value) {
            $reference = $this->referenceMobileMoneyLibre($facture, $reference);
        }

        $dateEncaissement = $data['date_encaissement'] ?? now()->toDateString();

        // Espèces = argent physiquement détenu par l'auteur : il doit atterrir dans SA caisse dédiée,
        // jamais sur le compte partagé de l'agence (règle du 23/09/2026). Vérifié ici, côté serveur,
        // quel que soit l'état du bouton désactivé dans PaymentCard (CLAUDE.md §9).
        $this->caisses->garantirCaissePourEspeces(
            $data['mode_paiement'],
            (string) $user->id,
            $facture,
            $dateEncaissement,
            $siteEncaissementId,
        );

        return [
            'site_encaissement_id' => $siteEncaissementId,
            'mode_paiement' => $data['mode_paiement'],
            'operateur_mobile_money' => $operateur,
            'compte_tresorerie_id' => $compteTresorerieId,
            'reference_paiement' => $reference,
            'date_encaissement' => $dateEncaissement,
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * Le message nomme la facture qui utilise déjà la référence ; `reference_paiement_facture` porte
     * ce numéro seul, que PaymentCard rend copiable. Sans facture identifiable, message générique.
     */
    public function referenceDejaUtilisee(?string $factureReference): ValidationException
    {
        if (blank($factureReference)) {
            return ValidationException::withMessages([
                'reference_paiement' => EncaissementVente::MESSAGE_REFERENCE_MOBILE_MONEY_UTILISEE,
            ]);
        }

        return ValidationException::withMessages([
            'reference_paiement' => "Référence déjà utilisée — facture {$factureReference}",
            'reference_paiement_facture' => $factureReference,
        ]);
    }

    /**
     * Une référence Mobile Money ne sert qu'une fois dans l'organisation, quels que soient la vente,
     * l'agence, l'agent ou l'opérateur (ADR 0014). Ce contrôle donne le message ; l'index unique de
     * `cle_reference_mobile_money` tranche entre deux saisies simultanées (à l'appelant d'intercepter
     * la violation, cf. EncaissementVente::estDoublonReferenceMobileMoney()).
     */
    private function referenceMobileMoneyLibre(FactureVente $facture, ?string $reference): ?string
    {
        $reference = EncaissementVente::normaliserReference($reference);

        $factureUtilisatrice = EncaissementVente::factureUtilisantReferenceMobileMoney($facture->organization_id, $reference);
        if ($factureUtilisatrice !== null) {
            throw $this->referenceDejaUtilisee($factureUtilisatrice);
        }

        return $reference;
    }
}
