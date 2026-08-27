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
 * C03 — déclencheur FACTURE_ENCAISSEE, encaissements partiels successifs. Le moteur ne doit
 * générer ni à 25 %, ni à 60 %, mais uniquement quand le dernier encaissement fait
 * effectivement passer la facture à PAYEE. Commande de démo = 1 unité à 2 000 GNF
 * (cf. ElmV2DemoCatalogSeeder) — encaissée en 3 fois : 500 + 700 + 800 = 2 000.
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

test('trois encaissements partiels : 0 commission avant le dernier, génération exacte ensuite', async ({
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

    await encaisserFacture(page, VEHICULE_NOM_FACTURE, 500);
    let diag = await lireDiagnosticCommission(page, commandeId);
    expect(diag.enveloppes_count, 'après 500/2000 GNF : rien').toBe(0);
    expect(diag.facture?.encaissements.length).toBe(1);

    await encaisserFacture(page, VEHICULE_NOM_FACTURE, 700);
    diag = await lireDiagnosticCommission(page, commandeId);
    expect(diag.enveloppes_count, 'après 1200/2000 GNF : toujours rien').toBe(0);
    expect(diag.facture?.encaissements.length).toBe(2);

    await encaisserFacture(page, VEHICULE_NOM_FACTURE, 800);
    diag = await lireDiagnosticCommission(page, commandeId);
    expect(diag.enveloppes_count, 'après 2000/2000 GNF : génération exacte').toBe(4);
    expect(diag.facture?.statut).toBe('payee');
    expect(diag.generation_attempts.length, 'une seule tentative malgré 3 encaissements').toBe(1);

    const totalGeneré = diag.enveloppes.reduce((sum, e) => sum + e.montant_total, 0);
    expect(totalGeneré).toBe(MONTANTS.proprietaire + MONTANTS.livreur + MONTANTS.site + MONTANTS.consultant);
});
