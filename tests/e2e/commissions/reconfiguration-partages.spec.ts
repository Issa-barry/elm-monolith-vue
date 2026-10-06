import { expect, test, type Page } from '@playwright/test';
import { lireDiagnosticCommission, montantPattern } from './helpers';

/**
 * Changement de barème Livreur sur plusieurs équipes (ADR 0006, lots 1 et 2) — parcours complet :
 * barème 800 → 1 000 dans Paramètres → Commissions, détection des équipes concernées, grille
 * d'édition groupée (saisie clavier, total en temps réel, application par rôle, proposition
 * proportionnelle), enregistrement groupé, publication atomique, puis régularisation d'une
 * commission PARTIELLE par « Relancer la génération » (cible Livreur seule, sans doublon).
 *
 * Contexte préparé par l'endpoint e2e-only /e2e/fixtures/partages-livreur, dans une ORGANISATION DÉDIÉE
 * (Testing\CommissionE2eFixturesController) : type de véhicule et catégorie dédiés, barème Livreur
 * en exception sur ce seul type → aucune autre équipe de la base E2E n'est concernée.
 */

interface Fixture {
    categorie: { id: string; nom: string };
    type_vehicule: { id: string; nom: string };
    vehicules: Record<'V1' | 'V2' | 'V3' | 'V4', { id: string; nom: string }>;
    produit_id: string;
    commande_partielle_id: string;
}

async function xsrf(page: Page): Promise<string> {
    const cookie = (await page.context().cookies()).find(
        (c) => c.name === 'XSRF-TOKEN',
    );
    if (!cookie) throw new Error('Cookie XSRF-TOKEN introuvable.');
    return decodeURIComponent(cookie.value);
}

async function preparerFixture(page: Page): Promise<Fixture> {
    const response = await page.request.post('/e2e/fixtures/partages-livreur', {
        headers: {
            'X-XSRF-TOKEN': await xsrf(page),
            Accept: 'application/json',
        },
    });
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()) as Fixture;
}

async function apercuBloquant(
    page: Page,
    vehiculeId: string,
    produitId: string,
): Promise<boolean> {
    const response = await page.request.get(
        `/backoffice/ventes/check-partage-commission?vehicule_id=${vehiculeId}&produit_ids[]=${produitId}`,
        { headers: { Accept: 'application/json' } },
    );
    expect(response.ok()).toBeTruthy();
    return ((await response.json()) as { bloquant: boolean }).bloquant;
}

const cellule = (
    page: Page,
    vehicule: string,
    categorie: string,
    index: number,
) =>
    page
        .getByTestId(`reconfiguration-ligne-${vehicule}-${categorie}-${index}`)
        .locator('input[data-cellule]');

const total = (page: Page, vehicule: string, categorie: string) =>
    page.getByTestId(`reconfiguration-total-${vehicule}-${categorie}`);

test('barème Livreur 800 → 1 000 : reconfiguration groupée, publication, relance d’une commission partielle', async ({
    page,
}) => {
    test.setTimeout(180_000);
    // Organisation dédiée créée par la fixture, qui y connecte la session : on repart d'une
    // session vierge (le storageState par défaut connecte l'administrateur « elm »).
    await page.context().clearCookies();
    await page.goto('/login');
    const f = await preparerFixture(page);
    const cat = f.categorie.nom;
    const { V1, V2, V3, V4 } = f.vehicules;

    // ── Avant : la commande sur V4 est PARTIELLE (Livreur manquant) ─────────────────
    const avant = await lireDiagnosticCommission(page, f.commande_partielle_id);
    expect(avant.generation_attempts.at(-1)?.statut).toBe('partiel');
    expect(avant.enveloppes.map((e) => e.cible_type)).toEqual(['proprietaire']);
    const proprietaireAvant = avant.enveloppes[0];

    // Lot 1 : une nouvelle commande sur V4 (partage 700 ≠ 800) est refusée, V1 acceptée.
    expect(await apercuBloquant(page, V4.id, f.produit_id)).toBe(true);
    expect(await apercuBloquant(page, V1.id, f.produit_id)).toBe(false);

    // ── Paramètres → Commissions : exception Livreur 800 → 1 000 ───────────────────
    await page.goto('/settings/commissions');
    const ligne = page
        .locator('[data-testid^="commission-row-"]')
        .filter({ hasText: cat });
    await ligne.getByRole('button', { name: /modifier/i }).click();
    const dialog = page.getByRole('dialog', { name: /^modifier/i });
    await dialog
        .locator(
            `[aria-label="Montant exceptionnel pour ${f.type_vehicule.nom} — Livreur"]`,
        )
        .fill('1000');
    await page.getByTestId('commission-dialog-save').click();
    await expect(dialog).toBeHidden();

    await page.getByTestId('commission-save').click();
    const impact = page.getByTestId('commission-impact');
    await expect(impact).toContainText('3 partage(s) Livreur sur 3 équipe(s)');
    await expect(
        page.getByTestId('commission-impact-automatiques'),
    ).toContainText('1 équipe(s) n’ont qu’un seul livreur actif');
    // V3 n'a qu'un livreur : sa part suivra le barème automatiquement (jamais dans la grille).
    await expect(page.getByTestId('commission-confirm-save')).toHaveText(
        /préparer la reconfiguration/i,
    );
    await page.getByTestId('commission-confirm-save').click();

    // ── Grille de reconfiguration ───────────────────────────────────────────────────
    await page.waitForURL(/\/settings\/commissions\/brouillons\//);
    await expect(page.getByTestId('reconfiguration-resume')).toContainText('3');
    await expect(
        page.getByTestId(`reconfiguration-statut-${V3.nom}-${cat}`),
    ).toHaveCount(0);
    // Rien n'est appliqué avant publication : V1 reste acceptée au barème 800.
    expect(await apercuBloquant(page, V1.id, f.produit_id)).toBe(false);

    // V1 : saisie au clavier comme dans un tableur, écart affiché en temps réel.
    await cellule(page, V1.nom, cat, 0).click();
    await page.keyboard.type('600');
    await page.keyboard.press('Enter');
    await expect(cellule(page, V1.nom, cat, 1)).toBeFocused();
    await page.keyboard.type('300');
    await expect(total(page, V1.nom, cat)).toContainText(
        new RegExp(`${montantPattern(900)}\\s*/\\s*${montantPattern(1000)}`),
    );
    await expect(
        page.getByText(
            `Manque ${new Intl.NumberFormat('fr-FR').format(100)} GNF`,
            { exact: false },
        ),
    ).toBeVisible();
    await cellule(page, V1.nom, cat, 1).fill('400');
    await expect(total(page, V1.nom, cat)).toContainText(
        new RegExp(`${montantPattern(1000)}\\s*/\\s*${montantPattern(1000)}`),
    );

    // V2 : sélection + application par rôle.
    await page
        .getByRole('checkbox', { name: `Sélectionner ${V2.nom} ${cat}` })
        .check();
    await page.getByTestId('reconfiguration-role-chauffeur').fill('500');
    await page.getByTestId('reconfiguration-role-convoyeur').fill('500');
    await page.getByTestId('reconfiguration-appliquer-roles').click();
    await expect(total(page, V2.nom, cat)).toContainText(
        new RegExp(montantPattern(1000)),
    );
    await page
        .getByRole('checkbox', { name: `Sélectionner ${V2.nom} ${cat}` })
        .uncheck();

    // V4 : proposition proportionnelle (500/200 → 715/285, reliquat au chauffeur).
    await page
        .getByRole('checkbox', { name: `Sélectionner ${V4.nom} ${cat}` })
        .check();
    await page.getByTestId('reconfiguration-proposition').click();
    await expect(cellule(page, V4.nom, cat, 0)).toHaveValue('715');
    await expect(cellule(page, V4.nom, cat, 1)).toHaveValue('285');

    // Enregistrement groupé (3 équipes), puis tout est conforme.
    await expect(page.getByTestId('reconfiguration-enregistrer')).toContainText(
        '(3)',
    );
    await page.getByTestId('reconfiguration-enregistrer').click();
    for (const v of [V1, V2, V4]) {
        await expect(
            page.getByTestId(`reconfiguration-statut-${v.nom}-${cat}`),
        ).toContainText('Conforme');
    }

    // ── Publication atomique ────────────────────────────────────────────────────────
    await page.getByTestId('reconfiguration-publier').click();
    await page.getByTestId('reconfiguration-confirmer-publication').click();
    await page.waitForURL(/\/settings\/commissions\?processus=vente/);

    for (const v of [V1, V2, V3, V4]) {
        expect(await apercuBloquant(page, v.id, f.produit_id)).toBe(false);
    }

    // V3 (un seul livreur, équipe à is_active=false) : sa part est réellement passée à 1 000 —
    // vérifiée sur sa fiche véhicule, pas seulement annoncée.
    await page.goto(`/backoffice/vehicules/${V3.id}?tab=equipe`);
    await expect(
        page.locator('tr', { hasText: 'Chauffeur V3' }).first(),
    ).toContainText(new RegExp(`${montantPattern(1000)}\\s*GNF`));

    // ── Régularisation de la commission partielle ───────────────────────────────────
    await page.goto(`/backoffice/ventes/${f.commande_partielle_id}`);
    await page.getByRole('button', { name: 'Relancer la génération' }).click();
    await expect(page.getByText('Relance en cours…')).toBeHidden();

    const apres = await lireDiagnosticCommission(page, f.commande_partielle_id);
    expect(apres.generation_attempts.at(-1)?.statut).toBe('succes');
    expect(apres.enveloppes.map((e) => e.cible_type).sort()).toEqual([
        'equipe_livraison',
        'proprietaire',
    ]);
    const proprietaireApres = apres.enveloppes.find(
        (e) => e.cible_type === 'proprietaire',
    );
    expect(proprietaireApres?.id).toBe(proprietaireAvant.id);
    expect(proprietaireApres?.montant_total).toBe(
        proprietaireAvant.montant_total,
    );
    const livreur = apres.enveloppes.find(
        (e) => e.cible_type === 'equipe_livraison',
    );
    expect(livreur?.earned_at).toBe(proprietaireAvant.earned_at);
    expect(livreur?.parts.length).toBe(2);
});
