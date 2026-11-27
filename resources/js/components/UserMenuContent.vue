<script setup lang="ts">
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useUserMenuActions } from '@/composables/useUserMenuActions';
import type { User } from '@/types';
import { ArrowRightLeft, LogOut, Settings } from 'lucide-vue-next';

interface Props {
    user: User;
}

const { canAccessClientSpace, openSettings, openClientSpace, handleLogout } =
    useUserMenuActions();

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
