import { expect, test } from '@playwright/test';
import { loginAsElmV2Demo } from '../helpers';
import {
    autoriserSuppressionEncaissement,
    configurerBareme,
    configurerDeclencheurVente,
    lireDiagnosticCommission,
    supprimerEncaissement,
} from './helpers';
import {
    configurerPartageEquipe,
    creerCommande,
    demarrerEtValiderChargement,
    encaisserFacture,
} from './vente-workflow';

/**
 * C09 — Non-régression (incident du 2026-08-26, module commissions V2) : en mode
 * FACTURE_ENCAISSEE, une commission générée à l'entrée en PAYEE restait vivante et payable
 * après suppression de l'encaissement qui l'avait fait naître.
 *
 * Comportement actuel (règle de référence, couverte côté Feature par
 * CommissionTriggerVenteTest::test_facture_encaissee_suppression_de_lencaissement_declencheur_devrait_invalider_la_commission) :
 * la suppression reste autorisée (permission `ventes.annuler_exceptionnel`), la facture repasse
 * sous PAYEE et CommissionTriggerService::onFactureVenteEncaissementRetire() passe en ANNULEE
 * toutes les enveloppes/parts non encore payées — conservées pour la traçabilité, plus payables.
 */

test.setTimeout(180_000);

const CATEGORIE_NOM = "Sachets d'eau V2 Demo";
const VEHICULE_MATCH = /V2-DEMO-01/i;
const VEHICULE_NOM_FACTURE = /véhicule v2 demo/i;
const CONSULTANT_LABEL = 'Consultant V2 Demo';
const MONTANTS = { proprietaire: 600, livreur: 300, site: 200, consultant: 50 };

test.beforeEach(async ({ page }) => {
    await loginAsElmV2Demo(page);
});

test('suppression de l\'encaissement déclencheur → les commissions non payées passent en annulée', async ({
    page,
}) => {
    await configurerDeclencheurVente(page, 'facture_encaissee');
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: MONTANTS,
        consultantLabel: CONSULTANT_LABEL,
    });
    await configurerPartageEquipe(page, VEHICULE_MATCH, CATEGORIE_NOM, MONTANTS.livreur);

    const commandeId = await creerCommande(page, VEHICULE_MATCH);
    await demarrerEtValiderChargement(page);
    await encaisserFacture(page, VEHICULE_NOM_FACTURE);

    const apresEncaissement = await lireDiagnosticCommission(page, commandeId);
    expect(apresEncaissement.facture?.statut, 'précondition : facture payée').toBe('payee');
    expect(apresEncaissement.enveloppes_count, 'précondition : commission générée').toBe(4);
    expect(apresEncaissement.facture?.encaissements.length).toBe(1);

    const encaissementId = apresEncaissement.facture!.encaissements[0].id;
    await autoriserSuppressionEncaissement(page, true);
    try {
        await supprimerEncaissement(page, encaissementId);
    } finally {
        await autoriserSuppressionEncaissement(page, false);
    }

    const apresSuppression = await lireDiagnosticCommission(page, commandeId);
    expect(apresSuppression.facture?.encaissements.length).toBe(0);
    expect(
        apresSuppression.facture?.statut,
        'la facture doit redescendre sous PAYEE une fois son seul encaissement supprimé',
    ).not.toBe('payee');

    // Plus de fait générateur = plus de commission payable : les 4 enveloppes restent listées
    // (traçabilité) mais toutes ANNULEE, parts comprises — aucune ne reste active.
    expect(apresSuppression.enveloppes_count).toBe(4);
    for (const enveloppe of apresSuppression.enveloppes) {
        expect(enveloppe.statut, `enveloppe ${enveloppe.cible_type}`).toBe('annulee');
        for (const part of enveloppe.parts) {
            expect(part.statut, `part ${part.beneficiaire_type}`).toBe('annulee');
        }
    }
});
