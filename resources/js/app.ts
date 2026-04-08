import 'primeicons/primeicons.css';
import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initializeTheme } from './composables/useAppearance';
import {
    applyAppThemeColors,
    applyStoredPrimeVueColors,
    getPrimeVueThemePreset,
    getStoredPrimeVueTheme,
    resolvePrimeVueThemeFromEnv,
} from './lib/primevue-theme';

// Quand la session expire, Inertia suit la redirection vers /login avec la
// même méthode HTTP (PUT/PATCH/DELETE) ce qui génère un 405. On force un
// rechargement complet pour que l'utilisateur puisse se reconnecter.
router.on('invalid', (event) => {
    const status = (event.detail.response as Response).status;
    if (status === 419 || status === 401) {
        event.preventDefault();
        window.location.reload();
    }
});

const appName = import.meta.env.VITE_APP_NAME || 'Eau-la-maman';
const initialPrimeVueTheme =
    getStoredPrimeVueTheme() ?? resolvePrimeVueThemeFromEnv();
const { preset: primeVuePreset } = getPrimeVueThemePreset(initialPrimeVueTheme);

// Apply light/dark class before the app mounts to avoid a flash of wrong theme.
initializeTheme();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(PrimeVue, {
                theme: {
                    preset: primeVuePreset,
                    options: {
                        darkModeSelector: '.dark',
                    },
                },
                locale: {
                    accept: 'Oui',
                    reject: 'Non',
                    cancel: 'Annuler',
                    close: 'Fermer',
                    apply: 'Appliquer',
                    clear: 'Effacer',
                    choose: 'Choisir',
                    upload: 'Téléverser',
                    noFileChosen: 'Aucun fichier choisi',
                    noResultsFound: 'Aucun résultat',
                    search: 'Rechercher',
                    searchMessage: '{0} résultat(s) disponible(s)',
                    selectionMessage: '{0} élément(s) sélectionné(s)',
                    emptySelectionMessage: 'Aucun élément sélectionné',
                    emptySearchMessage: 'Aucun résultat',
                    emptyMessage: 'Aucune option disponible',
                    fileSizeTypes: ['o', 'Ko', 'Mo', 'Go', 'To'],
                    dayNames: [
                        'Dimanche',
                        'Lundi',
                        'Mardi',
                        'Mercredi',
                        'Jeudi',
                        'Vendredi',
                        'Samedi',
                    ],
                    dayNamesShort: [
                        'Dim',
                        'Lun',
                        'Mar',
                        'Mer',
                        'Jeu',
                        'Ven',
                        'Sam',
                    ],
                    dayNamesMin: ['Di', 'Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa'],
                    monthNames: [
                        'Janvier',
                        'Février',
                        'Mars',
                        'Avril',
                        'Mai',
                        'Juin',
                        'Juillet',
                        'Août',
                        'Septembre',
                        'Octobre',
                        'Novembre',
                        'Décembre',
                    ],
                    monthNamesShort: [
                        'Jan',
                        'Fév',
                        'Mar',
                        'Avr',
                        'Mai',
                        'Juin',
                        'Juil',
                        'Aoû',
                        'Sep',
                        'Oct',
                        'Nov',
                        'Déc',
                    ],
                    today: "Aujourd'hui",
                    weekHeader: 'Sem',
                    firstDayOfWeek: 1,
                    showMonthAfterYear: false,
                    dateFormat: 'dd/mm/yy',
                    weak: 'Faible',
                    medium: 'Moyen',
                    strong: 'Fort',
                    passwordPrompt: 'Entrez un mot de passe',
                    pending: 'En attente',
                    chooseYear: "Choisir l'année",
                    chooseMonth: 'Choisir le mois',
                    chooseDate: 'Choisir la date',
                    prevDecade: 'Décennie précédente',
                    nextDecade: 'Décennie suivante',
                    prevYear: 'Année précédente',
                    nextYear: 'Année suivante',
                    prevMonth: 'Mois précédent',
                    nextMonth: 'Mois suivant',
                    prevHour: 'Heure précédente',
                    nextHour: 'Heure suivante',
                    prevMinute: 'Minute précédente',
                    nextMinute: 'Minute suivante',
                    prevSecond: 'Seconde précédente',
                    nextSecond: 'Seconde suivante',
                    am: 'AM',
                    pm: 'PM',
                    completed: 'Terminé',
                    aria: {
                        trueLabel: 'Vrai',
                        falseLabel: 'Faux',
                        nullLabel: 'Non sélectionné',
                        star: '1 étoile',
                        stars: '{star} étoiles',
                        selectAll: 'Tout sélectionner',
                        unselectAll: 'Tout désélectionner',
                        close: 'Fermer',
                        previous: 'Précédent',
                        next: 'Suivant',
                        navigation: 'Navigation',
                        scrollTop: 'Défiler vers le haut',
                        moveTop: 'Déplacer vers le haut',
                        moveUp: 'Déplacer vers le haut',
                        moveDown: 'Déplacer vers le bas',
                        moveBottom: 'Déplacer vers le bas',
                        moveToTarget: 'Déplacer vers la cible',
                        moveToSource: 'Déplacer vers la source',
                        moveAllToTarget: 'Tout déplacer vers la cible',
                        moveAllToSource: 'Tout déplacer vers la source',
                        pageLabel: 'Page {page}',
                        firstPageLabel: 'Première page',
                        lastPageLabel: 'Dernière page',
                        nextPageLabel: 'Page suivante',
                        prevPageLabel: 'Page précédente',
                        rowsPerPageLabel: 'Lignes par page',
                        jumpToPageDropdownLabel: 'Aller à la page',
                        jumpToPageInputLabel: 'Aller à la page',
                        selectRow: 'Ligne sélectionnée',
                        unselectRow: 'Ligne désélectionnée',
                        expandRow: 'Ligne développée',
                        collapseRow: 'Ligne réduite',
                        showFilterMenu: 'Afficher le menu de filtre',
                        hideFilterMenu: 'Masquer le menu de filtre',
                        filterOperator: 'Opérateur de filtre',
                        filterConstraint: 'Contrainte de filtre',
                        editRow: 'Modifier la ligne',
                        saveEdit: 'Enregistrer la modification',
                        cancelEdit: 'Annuler la modification',
                        listView: 'Vue liste',
                        gridView: 'Vue grille',
                        zoomImage: "Zoomer sur l'image",
                        zoomIn: 'Zoomer',
                        zoomOut: 'Dézoomer',
                        rotateRight: 'Pivoter vers la droite',
                        rotateLeft: 'Pivoter vers la gauche',
                    },
                },
            });

        const { primary, surface } =
            applyStoredPrimeVueColors(initialPrimeVueTheme);
        applyAppThemeColors(
            primary,
            surface,
            document.documentElement.classList.contains('dark'),
        );

        app.use(ConfirmationService).use(ToastService).mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
