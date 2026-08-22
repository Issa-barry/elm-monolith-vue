<?php

namespace App\Services\Comptabilite;

use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\JournalComptable;
use Illuminate\Support\Facades\DB;

/**
 * Provisionne un plan comptable minimal (inspiré SYSCOHADA) et les mappings
 * par défaut pour qu'une organisation puisse comptabiliser dès le premier
 * événement — entièrement idempotent (rejouable sans effet de bord) et
 * entièrement modifiable ensuite par un administrateur/expert-comptable.
 *
 * Les numéros de compte ci-dessous sont un point de départ raisonnable, PAS
 * une vérité gravée dans le marbre : à faire valider/ajuster par un
 * expert-comptable SYSCOHADA (Guinée) avant mise en production réelle —
 * cf. compte-rendu final, point "laissé configurable".
 */
class PlanComptableBootstrapService
{
    public function bootstrap(string $organizationId): void
    {
        DB::transaction(function () use ($organizationId) {
            $journaux = $this->seedJournaux($organizationId);
            $comptes = $this->seedComptes($organizationId);
            $this->seedMappings($organizationId, $journaux, $comptes);
        });
    }

    /** @return array<string, JournalComptable> */
    private function seedJournaux(string $organizationId): array
    {
        $defs = [
            'VE' => 'Ventes',
            'AC' => 'Achats',
            'CA' => 'Caisse',
            'BQ' => 'Banque',
            'MM' => 'Mobile Money',
            'OD' => 'Opérations diverses',
        ];

        $journaux = [];
        foreach ($defs as $code => $libelle) {
            $journaux[$code] = JournalComptable::query()->firstOrCreate(
                ['organization_id' => $organizationId, 'code' => $code],
                ['libelle' => $libelle, 'actif' => true],
            );
        }

        return $journaux;
    }

    /** @return array<string, CompteComptable> */
    private function seedComptes(string $organizationId): array
    {
        $defs = [
            '622100' => 'Commissions propriétaires de véhicules',
            '622200' => 'Commissions livreurs',
            '467110' => 'Propriétaires — commissions à payer',
            '467120' => 'Livreurs — commissions à payer',
            '467130' => 'Propriétaires — avances et dépenses à récupérer',
            '467140' => 'Livreurs — avances et dépenses à récupérer',
            '467150' => 'Propriétaires — charges à payer (provision de clôture)',
            '467160' => 'Livreurs — charges à payer (provision de clôture)',
            '411000' => 'Clients',
            '701000' => 'Ventes de marchandises',
            '571000' => 'Caisse',
            '521000' => 'Banque',
            '561000' => 'Mobile Money (générique)',
            // Exemples de wallets Mobile Money dédiés — à adapter/compléter librement
            // par organisation (compta_mappings.moyen_paiement accepte n'importe quelle
            // étiquette "mobile_money:xxx", aucun opérateur n'est codé en dur dans le
            // moteur). Ceux-ci ne sont que des points de départ raisonnables.
            '561100' => 'Mobile Money — Orange Money',
            '561200' => 'Mobile Money — MTN MoMo',
            '561300' => 'Mobile Money — Djomy',
            '628800' => 'Charges diverses de gestion courante',
            // Chantier Financement des agences (2026-08) — cf. docblock de
            // MouvementFondsComptabilisationService et SoldeOuvertureTresorerieService.
            '588000' => 'Virements de fonds internes (en transit)',
            '661000' => 'Rémunérations du personnel',
            '109000' => 'Solde d\'ouverture trésorerie (contrepartie technique)',
        ];

        $comptes = [];
        foreach ($defs as $numero => $libelle) {
            $comptes[$numero] = CompteComptable::query()->firstOrCreate(
                ['organization_id' => $organizationId, 'numero' => $numero],
                ['libelle' => $libelle, 'actif' => true],
            );
        }

        return $comptes;
    }

    /**
     * @param  array<string, JournalComptable>  $j
     * @param  array<string, CompteComptable>  $c
     */
    private function seedMappings(string $organizationId, array $j, array $c): void
    {
        // [evenement, role, moyen_paiement, compte_numero, journal_code|null]
        $lignes = [
            // Engagement — fiche propriétaire validée
            ['fiche_proprietaire_validee', 'charge_commission', null, '622100', 'OD'],
            ['fiche_proprietaire_validee', 'dette_tiers', null, '467110', 'OD'],
            ['fiche_proprietaire_validee', 'avance_tiers_proprietaire', null, '467130', 'OD'],

            // Engagement — fiche livreur validée
            ['fiche_livreur_validee', 'charge_commission', null, '622200', 'OD'],
            ['fiche_livreur_validee', 'dette_tiers', null, '467120', 'OD'],
            ['fiche_livreur_validee', 'avance_tiers_livreur', null, '467140', 'OD'],

            // Règlement — paiement propriétaire (dette : journal résolu via la ligne
            // trésorerie ci-dessous, donc journal=null ici). moyen_paiement reprend
            // les valeurs réelles de App\Enums\ModePaiement (especes/mobile_money/
            // virement/cheque) — 'especes' sert aussi de repli par défaut (null).
            ['paiement_proprietaire', 'dette_tiers', null, '467110', null],
            ['paiement_proprietaire', 'tresorerie', null, '571000', 'CA'],
            ['paiement_proprietaire', 'tresorerie', 'especes', '571000', 'CA'],
            // Générique (repli si aucun wallet précis n'est renseigné/configuré) + trois
            // wallets d'exemple : CompteMappingResolver essaie d'abord "mobile_money:xxx"
            // puis retombe sur "mobile_money" tout court — libre à l'organisation d'en
            // ajouter/retirer sans toucher au moteur.
            ['paiement_proprietaire', 'tresorerie', 'mobile_money', '561000', 'MM'],
            ['paiement_proprietaire', 'tresorerie', 'mobile_money:orange', '561100', 'MM'],
            ['paiement_proprietaire', 'tresorerie', 'mobile_money:mtn', '561200', 'MM'],
            ['paiement_proprietaire', 'tresorerie', 'mobile_money:djomy', '561300', 'MM'],
            ['paiement_proprietaire', 'tresorerie', 'virement', '521000', 'BQ'],
            ['paiement_proprietaire', 'tresorerie', 'cheque', '521000', 'BQ'],

            // Règlement — paiement livreur
            ['paiement_livreur', 'dette_tiers', null, '467120', null],
            ['paiement_livreur', 'tresorerie', null, '571000', 'CA'],
            ['paiement_livreur', 'tresorerie', 'especes', '571000', 'CA'],
            ['paiement_livreur', 'tresorerie', 'mobile_money', '561000', 'MM'],
            ['paiement_livreur', 'tresorerie', 'mobile_money:orange', '561100', 'MM'],
            ['paiement_livreur', 'tresorerie', 'mobile_money:mtn', '561200', 'MM'],
            ['paiement_livreur', 'tresorerie', 'mobile_money:djomy', '561300', 'MM'],
            ['paiement_livreur', 'tresorerie', 'virement', '521000', 'BQ'],
            ['paiement_livreur', 'tresorerie', 'cheque', '521000', 'BQ'],

            // Vente facturée (engagement — créance client constatée à la sortie du
            // statut CREEE de la facture, montant définitif). Journal VE, provisionné
            // ci-dessus mais resté inutilisé jusqu'ici (V1 se limitait aux commissions).
            ['vente_facturee', 'client', null, '411000', 'VE'],
            ['vente_facturee', 'produit_vente', null, '701000', 'VE'],

            // Encaissement client (règlement — solde tout ou partie de la créance).
            // Journal résolu via la ligne trésorerie (moyen_paiement réel), comme pour
            // paiement_proprietaire/paiement_livreur ci-dessus — mêmes 4 valeurs
            // App\Enums\ModePaiement (especes/mobile_money/virement/cheque), même
            // chaîne de repli CompteMappingResolver.
            ['encaissement_vente_recu', 'client', null, '411000', null],
            ['encaissement_vente_recu', 'tresorerie', null, '571000', 'CA'],
            ['encaissement_vente_recu', 'tresorerie', 'especes', '571000', 'CA'],
            ['encaissement_vente_recu', 'tresorerie', 'mobile_money', '561000', 'MM'],
            ['encaissement_vente_recu', 'tresorerie', 'mobile_money:orange', '561100', 'MM'],
            ['encaissement_vente_recu', 'tresorerie', 'mobile_money:mtn', '561200', 'MM'],
            ['encaissement_vente_recu', 'tresorerie', 'mobile_money:djomy', '561300', 'MM'],
            ['encaissement_vente_recu', 'tresorerie', 'virement', '521000', 'BQ'],
            ['encaissement_vente_recu', 'tresorerie', 'cheque', '521000', 'BQ'],

            // Dépense interne (vraie charge ELM)
            ['depense_interne_validee', 'charge_defaut', null, '628800', 'OD'],
            ['depense_interne_validee', 'tresorerie', null, '571000', 'CA'],

            // Dépense imputée à un tiers (avance récupérable, jamais une charge)
            ['depense_avance_tiers_validee', 'avance_tiers_proprietaire', null, '467130', null],
            ['depense_avance_tiers_validee', 'avance_tiers_livreur', null, '467140', null],
            ['depense_avance_tiers_validee', 'tresorerie', null, '571000', 'CA'],

            // Paiement de salaire (jambe trésorerie uniquement, cf. EvenementComptable::PAIEMENT_SALAIRE
            // et App\Services\Comptabilite\PaieComptabilisationService — pas d'engagement/dette
            // préalable comptabilisé pour la paie, contrairement aux fiches livreur/propriétaire).
            ['paiement_salaire', 'charge_salaire', null, '661000', null],
            ['paiement_salaire', 'tresorerie', null, '571000', 'CA'],
            ['paiement_salaire', 'tresorerie', 'especes', '571000', 'CA'],
            ['paiement_salaire', 'tresorerie', 'mobile_money', '561000', 'MM'],
            ['paiement_salaire', 'tresorerie', 'mobile_money:orange', '561100', 'MM'],
            ['paiement_salaire', 'tresorerie', 'mobile_money:mtn', '561200', 'MM'],
            ['paiement_salaire', 'tresorerie', 'mobile_money:djomy', '561300', 'MM'],
            ['paiement_salaire', 'tresorerie', 'virement', '521000', 'BQ'],
            ['paiement_salaire', 'tresorerie', 'cheque', '521000', 'BQ'],

            // Mouvement de fonds interne (agence <-> siège) — la jambe "trésorerie" (compte
            // caisse/banque/mobile money réel) est résolue directement depuis le
            // CompteTresorerie du mouvement, jamais via ce mapping (cf.
            // MouvementFondsComptabilisationService) : seul le compte de virements internes
            // (58) a besoin d'être configurable ici.
            ['mouvement_fonds_envoye', 'fonds_transit', null, '588000', 'OD'],
            ['mouvement_fonds_recu', 'fonds_transit', null, '588000', 'OD'],

            // Solde d'ouverture d'un support de trésorerie — contrepartie technique, jamais
            // un vrai résultat/charge (cf. SoldeOuvertureTresorerieService).
            ['solde_ouverture_tresorerie', 'contrepartie_ouverture', null, '109000', 'OD'],

            // Paiement direct de commission logistique (App\Models\CommissionPayment) —
            // circuit parallèle à paiement_livreur/paiement_proprietaire, mêmes comptes de
            // charge (622100/622200) réutilisés : c'est la même nature de dépense, seule la
            // source du paiement diffère (audit du 2026-08-22).
            ['paiement_commission_logistique_direct', 'charge_commission_livreur', null, '622200', null],
            ['paiement_commission_logistique_direct', 'charge_commission_proprietaire', null, '622100', null],
            ['paiement_commission_logistique_direct', 'tresorerie', null, '571000', 'CA'],
            ['paiement_commission_logistique_direct', 'tresorerie', 'especes', '571000', 'CA'],
            ['paiement_commission_logistique_direct', 'tresorerie', 'mobile_money', '561000', 'MM'],
            ['paiement_commission_logistique_direct', 'tresorerie', 'mobile_money:orange', '561100', 'MM'],
            ['paiement_commission_logistique_direct', 'tresorerie', 'mobile_money:mtn', '561200', 'MM'],
            ['paiement_commission_logistique_direct', 'tresorerie', 'mobile_money:djomy', '561300', 'MM'],
            ['paiement_commission_logistique_direct', 'tresorerie', 'virement', '521000', 'BQ'],
            ['paiement_commission_logistique_direct', 'tresorerie', 'cheque', '521000', 'BQ'],

            // Régularisation de clôture (provision, reprise automatique à la validation réelle)
            ['regularisation_cloture_fiche', 'charge_commission_proprietaire', null, '622100', 'OD'],
            ['regularisation_cloture_fiche', 'dette_tiers_provisoire_proprietaire', null, '467150', 'OD'],
            ['regularisation_cloture_fiche', 'avance_tiers_proprietaire', null, '467130', 'OD'],
            ['regularisation_cloture_fiche', 'charge_commission_livreur', null, '622200', 'OD'],
            ['regularisation_cloture_fiche', 'dette_tiers_provisoire_livreur', null, '467160', 'OD'],
            ['regularisation_cloture_fiche', 'avance_tiers_livreur', null, '467140', 'OD'],
        ];

        foreach ($lignes as [$evenement, $role, $moyenPaiement, $numeroCompte, $journalCode]) {
            CompteMapping::query()->firstOrCreate(
                [
                    'organization_id' => $organizationId,
                    'evenement' => $evenement,
                    'role' => $role,
                    'moyen_paiement' => $moyenPaiement,
                ],
                [
                    'compte_comptable_id' => $c[$numeroCompte]->id,
                    'journal_comptable_id' => $journalCode ? $j[$journalCode]->id : null,
                    'actif' => true,
                ],
            );
        }
    }
}
