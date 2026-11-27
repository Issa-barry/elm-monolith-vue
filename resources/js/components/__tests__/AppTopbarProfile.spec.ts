import AppTopbarProfile from '@/components/AppTopbarProfile.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const etat = vi.hoisted(() => ({
    auth: {} as Record<string, unknown>,
}));

const router = vi.hoisted(() => ({
    flushAll: vi.fn(),
    visit: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: etat.auth } }),
    router,
}));
vi.mock('@/routes', () => ({
    logout: () => ({ url: '/logout', method: 'post' }),
}));
vi.mock('@/routes/client', () => ({
    dashboard: () => ({ url: '/client', method: 'get' }),
}));
vi.mock('@/routes/parametres', () => ({
    edit: () => ({ url: '/settings/parametres', method: 'get' }),
}));
vi.mock('@/routes/profile', () => ({
    edit: () => ({ url: '/settings/profile', method: 'get' }),
}));

// Les primitives reka-ui (portail, ouverture au pointeur) sont remplacées par des conteneurs
// simples : le test porte sur le contenu du menu, pas sur la mécanique d'ouverture.
vi.mock('@/components/ui/dropdown-menu', async () => {
    const { defineComponent, h } = await import('vue');
    const conteneur = (tag = 'div') =>
        defineComponent({
            inheritAttrs: false,
            setup:
                (_, { slots, attrs }) =>
                () =>
                    h(tag, attrs, slots.default?.()),
        });

    return {
        DropdownMenu: conteneur(),
        DropdownMenuTrigger: conteneur(),
        DropdownMenuContent: conteneur(),
        DropdownMenuGroup: conteneur(),
        DropdownMenuLabel: conteneur(),
        DropdownMenuSeparator: conteneur('hr'),
        DropdownMenuItem: conteneur('button'),
    };
});

function monter(
    options: {
        avatar?: string;
        permissions?: Record<string, boolean>;
        roles?: string[];
    } = {},
) {
    etat.auth = {
        user: {
            id: 1,
            prenom: 'Awa',
            nom: 'Camara',
            email: 'awa.camara@example.test',
            avatar: options.avatar,
        },
        roles: options.roles ?? ['admin_entreprise'],
        role_labels: { admin_entreprise: 'Administrateur' },
        permissions: options.permissions ?? {},
        default_site: { label: 'Usine de Matoto' },
    };

    return mount(AppTopbarProfile);
}

const libelles = (wrapper: ReturnType<typeof monter>) =>
    wrapper.findAll('button').map((b) => b.text().trim());

describe('AppTopbarProfile — profil de la barre supérieure', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it("affiche l'identité, l'e-mail, le rôle et le site de l'utilisateur", () => {
        const texte = monter().text();

        expect(texte).toContain('Awa Camara');
        expect(texte).toContain('awa.camara@example.test');
        expect(texte).toContain('Administrateur');
        expect(texte).toContain('Usine de Matoto');
    });

    it("utilise la photo du compte quand elle existe, sinon celle d'Apollo", () => {
        const sansPhoto = monter();
        const avecPhoto = monter({ avatar: '/storage/avatars/awa.png' });

        const sources = (w: ReturnType<typeof monter>) =>
            w
                .findAllComponents({ name: 'AvatarImage' })
                .map((c) => c.props('src'));

        expect(sources(sansPhoto)).toContain(
            '/images/avatar/apollo-avatar.png',
        );
        expect(sources(avecPhoto)).toContain('/storage/avatars/awa.png');
        expect(sources(avecPhoto)).not.toContain(
            '/images/avatar/apollo-avatar.png',
        );
    });

    it('masque Paramètres et Espace client sans permission ni rôle externe', () => {
        const actions = libelles(monter());

        expect(actions).toContain('Mon profil');
        expect(actions).toContain('Déconnexion');
        expect(actions).not.toContain('Paramètres');
        expect(actions).not.toContain('Espace client');
    });

    it('affiche Paramètres avec parametres.read et Espace client avec un rôle externe', () => {
        const actions = libelles(
            monter({
                permissions: { 'parametres.read': true },
                roles: ['admin_entreprise', 'proprietaire'],
            }),
        );

        expect(actions).toContain('Paramètres');
        expect(actions).toContain('Espace client');
    });

    it('ouvre le profil et déconnecte via les routes existantes', async () => {
        const wrapper = monter();
        const bouton = (libelle: string) =>
            wrapper.findAll('button').find((b) => b.text().trim() === libelle)!;

        await bouton('Mon profil').trigger('click');
        expect(router.visit).toHaveBeenCalledWith('/settings/profile');

        await bouton('Déconnexion').trigger('click');
        expect(router.post).toHaveBeenCalledWith('/logout');
    });
});
