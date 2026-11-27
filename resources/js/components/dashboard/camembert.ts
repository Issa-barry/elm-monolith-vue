import { partEnPourcentage, totalParts } from '@/lib/pourcentages';

/**
 * Camemberts des tableaux de bord (Stat-Commissions, Stat-Dépenses) : pourcentage écrit au centre de
 * chaque part assez large — Chart.js n'a pas d'étiquettes natives. En dessous de 5 % du total, la
 * part est trop étroite pour y écrire : le pourcentage reste dans la légende et l'infobulle.
 */
export const PART_MIN_ETIQUETTE = 0.05;

interface GraphiqueCamembert {
    ctx: CanvasRenderingContext2D;
    getDatasetMeta: (i: number) => {
        data: {
            tooltipPosition: (final: boolean) => { x: number; y: number };
        }[];
    };
    getDataVisibility: (i: number) => boolean;
}

/**
 * Plugin Chart.js qui écrit le pourcentage de chaque part (une décimale, lib/pourcentages).
 * `parts` et `couleurs` sont lus à chaque dessin : le plugin suit les données et le thème courants.
 */
export function pluginPourcentagesSurParts(
    id: string,
    parts: () => { montant: number }[],
    couleurs: () => string[],
) {
    return {
        id,
        afterDatasetsDraw(chart: GraphiqueCamembert) {
            const valeurs = parts();
            const total = totalParts(valeurs);
            if (total <= 0) {
                return;
            }
            const { ctx } = chart;
            ctx.save();
            ctx.font = '700 13px Poppins, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            chart.getDatasetMeta(0).data.forEach((arc, i) => {
                const montant = valeurs[i]?.montant ?? 0;
                if (
                    !chart.getDataVisibility(i) ||
                    montant / total < PART_MIN_ETIQUETTE
                ) {
                    return;
                }
                const { x, y } = arc.tooltipPosition(true);
                ctx.fillStyle = couleurs()[i] ?? '#ffffff';
                ctx.fillText(partEnPourcentage(montant, total), x, y);
            });
            ctx.restore();
        },
    };
}

/**
 * Couleur du pourcentage écrit sur chaque part : blanc sur les nuances foncées, `couleurClaire`
 * (primaire 900) sur les autres, pour rester lisible.
 */
export function couleursEtiquettes(
    nombre: number,
    nuances: string[],
    nuancesFoncees: string[],
    couleurClaire: string,
): string[] {
    return Array.from({ length: nombre }, (_, i) =>
        nuancesFoncees.includes(nuances[i % nuances.length])
            ? '#ffffff'
            : couleurClaire,
    );
}
