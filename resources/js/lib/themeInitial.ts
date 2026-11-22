import type { ThemeActive, ThemeSharedProps } from '@/types/theme';

/**
 * Thème actif de la page initiale, ou `undefined` si le prop `theme` est absent.
 *
 * Le serveur le partage sur chaque page, y compris la page d'erreur (bootstrap/app.php), mais
 * il peut manquer si sa résolution échoue pendant une erreur 500. Le démarrage d'app.ts et de
 * ssr.ts ne doit jamais en dépendre : sans thème, ils prennent le preset par défaut
 * (loadPrimeVuePreset) au lieu de planter sur une page blanche.
 */
export function themeActifInitial(
    props: Record<string, unknown> | undefined,
): ThemeActive | undefined {
    const theme = props?.theme as Partial<ThemeSharedProps> | null | undefined;

    return theme?.active ?? undefined;
}
