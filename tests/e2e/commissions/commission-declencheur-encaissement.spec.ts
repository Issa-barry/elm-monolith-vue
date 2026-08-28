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
 * C02 — déclencheur FACTURE_ENCAISSEE : la commande Vente V2 ne doit générer AUCUNE
 * commission avant l'encaissement complet de la facture, et exactement les 4 enveloppes
 * attendues (une par cible active) une fois payée — jamais avant, jamais deux fois.
 *
 * Parcours réel uniquement (paramétrage UI → commande → chargement → facture →
 * encaissement) : aucun appel direct à CommissionEnveloppeGenerator.
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

test('facture encaissée intégralement → exactement 4 enveloppes générées une seule fois, jamais avant', async ({
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

    const avantEncaissement = await lireDiagnosticCommission(page, commandeId);
    expect(
        avantEncaissement.enveloppes_count,
        'aucune enveloppe ne doit exister avant que la facture soit payée',
    ).toBe(0);
    expect(avantEncaissement.generation_attempts.length).toBe(0);

    await encaisserFacture(page, VEHICULE_NOM_FACTURE);

    const apresEncaissement = await lireDiagnosticCommission(page, commandeId);
    expect(apresEncaissement.enveloppes_count, 'une enveloppe par cible active (4)').toBe(4);

    const cibleTypes = apresEncaissement.enveloppes.map((e) => e.cible_type).sort();
    expect(cibleTypes).toEqual(['consultant', 'equipe_livraison', 'proprietaire', 'site'].sort());

    const totalParMontant = new Map(
        apresEncaissement.enveloppes.map((e) => [e.cible_type, e.montant_total]),
    );
    // 1 unité vendue dans ce scénario (quantité par défaut du parcours de démo) : le
    // montant total de chaque enveloppe doit égaler exactement le barème configuré.
    expect(totalParMontant.get('proprietaire')).toBe(MONTANTS.proprietaire);
    expect(totalParMontant.get('equipe_livraison')).toBe(MONTANTS.livreur);
    expect(totalParMontant.get('site')).toBe(MONTANTS.site);
    expect(totalParMontant.get('consultant')).toBe(MONTANTS.consultant);

    for (const enveloppe of apresEncaissement.enveloppes) {
        const sommeParts = enveloppe.parts.reduce((sum, p) => sum + p.montant_brut, 0);
        expect(
            sommeParts,
            `somme des parts == montant_total pour l'enveloppe ${enveloppe.cible_type}`,
        ).toBeCloseTo(enveloppe.montant_total, 2);
    }

    // Refresh de la page (pas un nouvel encaissement) : aucune génération supplémentaire.
    await page.reload();
    const apresRefresh = await lireDiagnosticCommission(page, commandeId);
    expect(apresRefresh.enveloppes_count).toBe(4);
    expect(apresRefresh.generation_attempts.length).toBe(1);
});
