import { expect, test } from '@playwright/test';
import { loginAsElmV2Demo } from '../helpers';
import {
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
 * C09 — RÉGRESSION CONFIRMÉE (audit du 2026-08-26, module commissions V2) :
 *
 * En mode FACTURE_ENCAISSEE, une commission générée à l'entrée en PAYEE reste
 * vivante et payable même après suppression de l'encaissement qui l'avait fait
 * naître. Preuve : app/Http/Controllers/EncaissementVenteController.php::destroy()
 * autorise la suppression tant que la facture n'est pas ANNULEE, sans vérifier
 * l'existence d'une commission déjà générée ; App\Models\FactureVente::recalculStatut()
 * ne déclenche CommissionTriggerService::onFactureVenteEncaissee() que sur la
 * transition ENTRANTE (!etaitPayee && statut===PAYEE), jamais sur la sortie ; et
 * App\Models\EncaissementVente::deleted() contre-passe l'écriture comptable de
 * l'encaissement mais ne touche jamais CommissionEnveloppe/CommissionEnveloppePart.
 *
 * Ce test exprime le comportement métier CORRECT attendu (la commission ne doit
 * plus rester payable comme si son fait générateur existait encore) — il échoue
 * intentionnellement contre le code actuel. Ne pas "corriger" l'assertion pour le
 * faire passer : corriger le moteur, ou documenter la dette si le produit décide
 * d'assumer ce risque.
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

test('suppression de l\'encaissement déclencheur → la commission ne doit plus rester payable (régression confirmée)', async ({
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
    await supprimerEncaissement(page, encaissementId);

    const apresSuppression = await lireDiagnosticCommission(page, commandeId);
    expect(
        apresSuppression.facture?.statut,
        'la facture doit redescendre sous PAYEE une fois son seul encaissement supprimé',
    ).not.toBe('payee');

    // Comportement CORRECT attendu : plus de fait générateur (facture non payée) =
    // plus de commission payable. Échoue aujourd'hui (régression confirmée par l'audit) :
    // les 4 enveloppes/parts restent intactes et payables malgré la suppression.
    expect(
        apresSuppression.enveloppes_count,
        'RÉGRESSION ATTENDUE : la commission devrait être invalidée/annulée quand son fait ' +
            'générateur (facture payée) disparaît — elle reste actuellement générée et payable.',
    ).toBe(0);
});
