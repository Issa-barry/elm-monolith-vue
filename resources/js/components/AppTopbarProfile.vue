<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/composables/useInitials';
import { usePermissions } from '@/composables/usePermissions';
import { useUserMenuActions } from '@/composables/useUserMenuActions';
import type { AppPageProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { ArrowRightLeft, LogOut, Settings, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Profil de la barre supérieure, à droite de la cloche (présentation inspirée du template
 * Apollo). Seul point d'accès au compte : la barre latérale ne sert qu'à la navigation.
 * Photo du compte si elle existe, sinon la photo par défaut du template Apollo ; initiales si
 * l'image ne se charge pas.
 */
const AVATAR_PAR_DEFAUT = '/images/avatar/apollo-avatar.png';

const page = usePage<AppPageProps>();
const { getInitials } = useInitials();
const { roleLabel: labelForRole } = usePermissions();
const {
    canReadParametres,
    canAccessClientSpace,
    openProfile,
    openSettings,
    openClientSpace,
    handleLogout,
} = useUserMenuActions();

const user = computed(() => page.props.auth.user);

const nomComplet = computed(() =>
    `${user.value?.prenom ?? ''} ${user.value?.nom ?? ''}`.trim(),
);

const photo = computed(() => user.value?.avatar || AVATAR_PAR_DEFAUT);

const initiales = computed(() => getInitials(nomComplet.value));

const role = computed(() => {
    const premierRole = page.props.auth.roles?.[0];
    return premierRole ? labelForRole(premierRole) : null;
});

const site = computed(() => page.props.auth.default_site?.label ?? null);
</script>

<template>
    <DropdownMenu v-if="user">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="ml-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-2 ring-transparent ring-offset-2 ring-offset-background transition-shadow outline-none hover:ring-primary/30 focus-visible:ring-primary/60 data-[state=open]:ring-primary/50"
                data-testid="topbar-profile"
            >
                <Avatar class="h-9 w-9">
                    <AvatarImage :src="photo" :alt="nomComplet" />
                    <AvatarFallback
                        class="bg-primary text-sm font-semibold text-primary-foreground"
                    >
                        {{ initiales }}
                    </AvatarFallback>
                </Avatar>
                <span class="sr-only">Ouvrir le menu du profil</span>
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            align="end"
            :side-offset="10"
            class="w-72 rounded-xl p-0 shadow-lg"
        >
            <!-- En-tête : photo, identité, rôle et site -->
            <DropdownMenuLabel class="p-0 font-normal">
                <div
                    class="flex items-center gap-3 px-4 py-4"
                    data-testid="topbar-profile-entete"
                >
                    <Avatar class="h-12 w-12 shadow-sm">
                        <AvatarImage :src="photo" :alt="nomComplet" />
                        <AvatarFallback
                            class="bg-primary text-base font-semibold text-primary-foreground"
                        >
                            {{ initiales }}
                        </AvatarFallback>
                    </Avatar>
                    <div class="grid min-w-0 flex-1 gap-0.5 leading-tight">
                        <span
                            class="truncate text-sm font-semibold text-foreground"
                            data-testid="topbar-profile-nom"
                            >{{ nomComplet }}</span
                        >
                        <span
                            v-if="user?.email"
                            class="truncate text-xs text-muted-foreground"
                            >{{ user.email }}</span
                        >
                        <div
                            v-if="role || site"
                            class="mt-1 flex min-w-0 items-center gap-1.5 text-[11px] text-muted-foreground"
                        >
                            <span
                                v-if="role"
                                class="shrink-0 rounded-md border px-1.5 py-0.5 font-medium text-foreground/80"
                                >{{ role }}</span
                            >
                            <span v-if="site" class="truncate">{{ site }}</span>
                        </div>
                    </div>
                </div>
            </DropdownMenuLabel>

            <DropdownMenuSeparator class="m-0" />

            <DropdownMenuGroup class="p-1.5">
                <DropdownMenuItem
                    class="cursor-pointer gap-2.5 rounded-md px-2.5 py-2"
                    @click="openProfile"
                >
                    <UserRound />
                    Mon profil
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="canReadParametres"
                    class="cursor-pointer gap-2.5 rounded-md px-2.5 py-2"
                    @click="openSettings"
                >
                    <Settings />
                    Paramètres
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="canAccessClientSpace"
                    class="cursor-pointer gap-2.5 rounded-md px-2.5 py-2"
                    @click="openClientSpace"
                >
                    <ArrowRightLeft />
                    Espace client
                </DropdownMenuItem>
            </DropdownMenuGroup>

            <DropdownMenuSeparator class="m-0" />

            <div class="p-1.5">
                <DropdownMenuItem
                    class="cursor-pointer gap-2.5 rounded-md px-2.5 py-2"
                    data-testid="topbar-profile-deconnexion"
                    @click="handleLogout"
                >
                    <LogOut />
                    Déconnexion
                </DropdownMenuItem>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
