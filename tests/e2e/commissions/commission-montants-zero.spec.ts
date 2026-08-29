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
 * C05 — Matrice bénéficiaires × montants zéro. Le point strict demandé : vérifier non
 * seulement que le bénéficiaire attendu reçoit sa commission, mais qu'AUCUN bénéficiaire
 * non paramétré (ou paramétré à 0) n'en reçoit une par erreur — c'est le trou habituel des
 * E2E commissions (vérifier "600 GNF propriétaire" sans jamais vérifier "0 chez les 3 autres").
 *
 * Scénarios A-D : un seul bénéficiaire coché à la fois. Scénario E : les 4 actifs
 * simultanément, exactement une enveloppe par cible, jamais deux fois la même.
 */

test.setTimeout(240_000);

const CATEGORIE_NOM = "Sachets d'eau V2 Demo";
const VEHICULE_MATCH = /V2-DEMO-01/i;
const VEHICULE_NOM_FACTURE = /véhicule v2 demo/i;
const CONSULTANT_LABEL = 'Consultant V2 Demo';

test.beforeEach(async ({ page }) => {
    await loginAsElmV2Demo(page);
    await configurerDeclencheurVente(page, 'facture_encaissee');
});

async function genererEtLireDiagnostic(page: import('@playwright/test').Page) {
    const commandeId = await creerCommande(page, VEHICULE_MATCH);
    await demarrerEtValiderChargement(page);
    await encaisserFacture(page, VEHICULE_NOM_FACTURE);
    return lireDiagnosticCommission(page, commandeId);
}

test('Scénario A — Propriétaire seul : aucune commission livreur/site/consultant', async ({ page }) => {
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: { proprietaire: 600, livreur: null, site: null, consultant: null },
    });

    const diag = await genererEtLireDiagnostic(page);
    expect(diag.enveloppes_count).toBe(1);
    expect(diag.enveloppes.map((e) => e.cible_type)).toEqual(['proprietaire']);
    expect(diag.enveloppes[0].montant_total).toBe(600);
});

test('Scénario B — Livreur seul : aucune commission propriétaire/site/consultant', async ({ page }) => {
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: { proprietaire: null, livreur: 300, site: null, consultant: null },
    });
    await configurerPartageEquipe(page, VEHICULE_MATCH, CATEGORIE_NOM, 300);

    const diag = await genererEtLireDiagnostic(page);
    expect(diag.enveloppes_count).toBe(1);
    expect(diag.enveloppes.map((e) => e.cible_type)).toEqual(['equipe_livraison']);
    expect(diag.enveloppes[0].montant_total).toBe(300);
});

test('Scénario C — Site seul : aucune commission propriétaire/livreur/consultant', async ({ page }) => {
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: { proprietaire: null, livreur: null, site: 200, consultant: null },
    });

    const diag = await genererEtLireDiagnostic(page);
    expect(diag.enveloppes_count).toBe(1);
    expect(diag.enveloppes.map((e) => e.cible_type)).toEqual(['site']);
    expect(diag.enveloppes[0].montant_total).toBe(200);
});

test('Scénario D — Consultant seul : aucune commission propriétaire/livreur/site', async ({ page }) => {
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: { proprietaire: null, livreur: null, site: null, consultant: 50 },
        consultantLabel: CONSULTANT_LABEL,
    });

    const diag = await genererEtLireDiagnostic(page);
    expect(diag.enveloppes_count).toBe(1);
    expect(diag.enveloppes.map((e) => e.cible_type)).toEqual(['consultant']);
    expect(diag.enveloppes[0].montant_total).toBe(50);
});

test('Scénario E — Site à 0 explicitement, les 3 autres positifs : exactement 3 enveloppes, jamais Site', async ({
    page,
}) => {
    // Barème à 0 : "configuré, rien à distribuer" — distinct d'une cible décochée. Dans les
    // deux cas, aucun montant positif ne doit apparaître pour Site (cf. décision AMOA vérifiée
    // par l'audit backend : CommissionEnveloppeGenerator ne crée pas d'enveloppe pour un
    // montant total nul).
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: { proprietaire: 600, livreur: 300, site: 0, consultant: 50 },
        consultantLabel: CONSULTANT_LABEL,
    });
    await configurerPartageEquipe(page, VEHICULE_MATCH, CATEGORIE_NOM, 300);

    const diag = await genererEtLireDiagnostic(page);
    const cibleTypes = diag.enveloppes.map((e) => e.cible_type).sort();
    expect(cibleTypes, 'jamais de Site quand son barème est à 0').toEqual(
        ['consultant', 'equipe_livraison', 'proprietaire'].sort(),
    );
    expect(diag.enveloppes.some((e) => e.cible_type === 'site')).toBe(false);
});

test('Scénario F — les 4 bénéficiaires actifs simultanément : exactement 4 enveloppes, une par cible', async ({
    page,
}) => {
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: { proprietaire: 600, livreur: 300, site: 200, consultant: 50 },
        consultantLabel: CONSULTANT_LABEL,
    });
    await configurerPartageEquipe(page, VEHICULE_MATCH, CATEGORIE_NOM, 300);

    const diag = await genererEtLireDiagnostic(page);
    expect(diag.enveloppes_count).toBe(4);
    const cibleTypes = diag.enveloppes.map((e) => e.cible_type).sort();
    expect(cibleTypes).toEqual(['consultant', 'equipe_livraison', 'proprietaire', 'site'].sort());
    // Une seule enveloppe par cible, jamais deux.
    expect(new Set(cibleTypes).size).toBe(cibleTypes.length);
});
