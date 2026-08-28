import { expect, test } from '@playwright/test';
import { loginAsElmV2Demo } from '../helpers';
import { configurerBareme, configurerDeclencheurVente, lireDiagnosticCommission } from './helpers';
import {
    configurerPartageEquipe,
    creerCommande,
    demarrerEtValiderChargement,
    encaisserFacture,
} from './vente-workflow';

/**
 * C01 — déclencheur CHARGEMENT_VALIDE : la commission naît dès la validation du
 * chargement, AVANT toute facturation/encaissement. Une fois générée, la
 * facturation puis l'encaissement complet de la même commande ne doivent jamais
 * produire de commission supplémentaire (validation chargement = génération,
 * facturation/encaissement = aucun doublon).
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

test('chargement validé → 4 enveloppes immédiatement, encaissement ultérieur ne double jamais', async ({
    page,
}) => {
    await configurerDeclencheurVente(page, 'chargement_valide');
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: MONTANTS,
        consultantLabel: CONSULTANT_LABEL,
    });
    await configurerPartageEquipe(page, VEHICULE_MATCH, CATEGORIE_NOM, MONTANTS.livreur);

    const commandeId = await creerCommande(page, VEHICULE_MATCH);

    const avantChargement = await lireDiagnosticCommission(page, commandeId);
    expect(avantChargement.enveloppes_count, 'rien avant le chargement').toBe(0);

    await demarrerEtValiderChargement(page);

    const apresChargement = await lireDiagnosticCommission(page, commandeId);
    expect(apresChargement.enveloppes_count, 'chargement validé = génération immédiate').toBe(4);
    const cibleTypes = apresChargement.enveloppes.map((e) => e.cible_type).sort();
    expect(cibleTypes).toEqual(['consultant', 'equipe_livraison', 'proprietaire', 'site'].sort());

    await encaisserFacture(page, VEHICULE_NOM_FACTURE);

    const apresEncaissement = await lireDiagnosticCommission(page, commandeId);
    expect(apresEncaissement.enveloppes_count, 'facturation/encaissement ne doit rien ajouter').toBe(4);
    expect(apresEncaissement.generation_attempts.length, 'une seule tentative, jamais deux').toBe(1);
});
