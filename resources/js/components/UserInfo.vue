<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { AppPageProps, AppRole, User } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    user: User;
    showEmail?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

const { getInitials } = useInitials();
const page = usePage<AppPageProps>();

const ROLE_LABELS: Record<AppRole, string> = {
    super_admin: 'Super administrateur',
    admin_entreprise: 'Administrateur entreprise',
    manager: 'Manager',
    commerciale: 'Commercial',
    comptable: 'Comptable',
    client: 'Client',
};

const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);

const roleLabel = computed(() => {
    const firstRole = page.props.auth.roles?.[0];
    return firstRole ? (ROLE_LABELS[firstRole] ?? firstRole) : 'Aucun role';
});

const subtitle = computed(() => {
    if (props.showEmail) {
        return props.user.email || roleLabel.value;
    }

    return roleLabel.value;
});
</script>

<template>
    <Avatar class="h-8 w-8 overflow-hidden rounded-lg">
        <AvatarImage
            v-if="showAvatar"
            :src="user.avatar!"
            :alt="`${user.prenom} ${user.nom}`"
        />
        <AvatarFallback class="rounded-lg text-black dark:text-white">
            {{ getInitials(`${user.prenom} ${user.nom}`) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium"
            >{{ user.prenom }} {{ user.nom }}</span
        >
        <span class="truncate text-xs text-muted-foreground">{{
            subtitle
        }}</span>
    </div>
</template>
