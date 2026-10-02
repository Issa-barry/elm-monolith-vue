import { router, usePage } from '@inertiajs/vue3';
import { ref, watch, type Ref } from 'vue';

const BASE = 'http://localhost';

/** Paramètres de requête d'une URL Inertia (`page.url` = chemin + requête, sans origine). */
export function queryDe(url: string): Record<string, string> {
    return Object.fromEntries(new URL(url, BASE).searchParams);
}

/**
 * Onglet actif d'une fiche, porté par l'URL (`?tab=…`) plutôt que par un simple ref : il survit
 * à tout chargement complet de la page (F5, rechargement forcé par Inertia, lien copié).
 * Lu dans l'URL au chargement (absent ou invalide → onglet par défaut) ; un clic met à jour
 * l'URL par une visite Inertia côté client, sans requête serveur.
 */
export function useUrlTab<T extends string>(
    onglets: readonly T[],
    defaut: T,
    parametre = 'tab',
) {
    const page = usePage();
    const composant = page.component;

    const lire = (url: string): T => {
        const valeur = new URL(url, BASE).searchParams.get(parametre);

        return onglets.find((o) => o === valeur) ?? defaut;
    };

    const onglet = ref(lire(page.url)) as Ref<T>;

    function inscrireDansUrl(): void {
        // Navigation vers une autre page : l'URL n'est plus celle de la fiche.
        if (page.component !== composant) {
            return;
        }

        const url = new URL(page.url, BASE);
        if (url.searchParams.get(parametre) === onglet.value) {
            return;
        }

        url.searchParams.set(parametre, onglet.value);
        router.replace({
            url: url.pathname + url.search + url.hash,
            preserveScroll: true,
            preserveState: true,
        });
    }

    function choisir(cible: T): void {
        onglet.value = cible;
        inscrireDansUrl();
    }

    // Une redirection serveur (ex. après enregistrement d'une équipe) ramène l'URL nue de la
    // fiche alors que le composant est conservé : l'onglet courant y est réinscrit.
    watch(() => page.url, inscrireDansUrl);

    return { onglet, choisir };
}
