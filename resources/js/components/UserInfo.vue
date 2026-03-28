<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';
import { computed } from 'vue';

interface Props {
    user: User;
    showEmail?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

const { getInitials } = useInitials();

function formatPhone(phone: string | null): string {
    if (!phone) return '';
    const match = phone.match(/^(\+\d{1,3})(\d+)$/);
    if (!match) return phone;
    const [, prefix, local] = match;
    const groups = local.match(/.{1,3}/g) ?? [local];
    return `${prefix} ${groups.join(' ')}`;
}

// Compute whether we should show the avatar image
const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);
</script>

<template>
    <Avatar class="h-8 w-8 overflow-hidden rounded-lg">
        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="`${user.prenom} ${user.nom}`" />
        <AvatarFallback class="rounded-lg text-black dark:text-white">
            {{ getInitials(`${user.prenom} ${user.nom}`) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium">{{ user.prenom }} {{ user.nom }}</span>
        <span class="truncate text-xs text-muted-foreground">{{
            user.telephone ? formatPhone(user.telephone) : (user.email ?? '—')
        }}</span>
    </div>
</template>
