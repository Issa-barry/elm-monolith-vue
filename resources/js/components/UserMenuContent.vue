<script setup lang="ts">
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { usePermissions } from '@/composables/usePermissions';
import { logout } from '@/routes';
import { dashboard as clientDashboard } from '@/routes/client';
import { edit as editParametres } from '@/routes/parametres';
import { edit as editProfile } from '@/routes/profile';
import type { User } from '@/types';
import { router } from '@inertiajs/vue3';
import { ArrowRightLeft, LogOut, Settings } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    user: User;
}

const { can, hasAnyRole } = usePermissions();

const settingsHref = computed(() =>
    can('parametres.read') ? editParametres().url : editProfile().url,
);

// Un compte staff peut aussi porter un rôle client/proprietaire/livreur (ex: un
// admin qui possède lui-même un véhicule) — cf. EnsureIsStaffAccount côté backend,
// qui autorise ce cumul plutôt que de forcer un second compte.
const canAccessClientSpace = computed(() =>
    hasAnyRole(['client', 'proprietaire', 'livreur']),
);

const openSettings = () => {
    router.flushAll();
    router.visit(settingsHref.value);
};

const openClientSpace = () => {
    router.flushAll();
    router.visit(clientDashboard().url);
};

const handleLogout = () => {
    router.flushAll();
    router.post(logout().url);
};

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem class="cursor-pointer" @click="openSettings">
            <Settings class="mr-2 h-4 w-4" />
            Parametres
        </DropdownMenuItem>
        <DropdownMenuItem
            v-if="canAccessClientSpace"
            class="cursor-pointer"
            @click="openClientSpace"
        >
            <ArrowRightLeft class="mr-2 h-4 w-4" />
            Espace client
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem
        class="cursor-pointer"
        data-test="logout-button"
        @click="handleLogout"
    >
        <LogOut class="mr-2 h-4 w-4" />
        Deconnexion
    </DropdownMenuItem>
</template>
