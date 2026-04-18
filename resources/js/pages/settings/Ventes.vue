<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Cog, ShieldCheck } from 'lucide-vue-next';

interface RoleQuantite {
    name: string;
    label: string;
    can_update_quantite: boolean;
    locked: boolean;
}

interface CommissionOption {
    value: string;
    label: string;
    description: string;
}

const props = defineProps<{
    roles: RoleQuantite[];
    commission_generation_mode: string;
    commission_options: CommissionOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Parametres', href: '/settings/profile' },
    { title: 'Parametrage ventes', href: '/settings/ventes' },
];

const form = useForm({
    commission_generation_mode: props.commission_generation_mode,
    quantity_edit_role_names: props.roles
        .filter((role) => role.can_update_quantite && !role.locked)
        .map((role) => role.name),
});

function roleEnabled(roleName: string): boolean {
    return form.quantity_edit_role_names.includes(roleName);
}

function toggleRole(role: RoleQuantite) {
    if (role.locked) {
        return;
    }

    if (roleEnabled(role.name)) {
        form.quantity_edit_role_names = form.quantity_edit_role_names.filter(
            (name) => name !== role.name,
        );

        return;
    }

    form.quantity_edit_role_names = [
        ...form.quantity_edit_role_names,
        role.name,
    ];
}

function submit() {
    form.put('/settings/ventes', { preserveScroll: true });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Parametrage ventes" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Parametrage ventes"
                    description="Configurez les regles de creation de commandes et de commission."
                />

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <ShieldCheck class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Profils autorises a modifier la quantite
                        </h3>
                    </div>

                    <div class="divide-y">
                        <div
                            v-for="role in roles"
                            :key="role.name"
                            class="flex items-center justify-between gap-4 px-5 py-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-foreground">
                                    {{ role.label }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ role.name }}
                                </p>
                            </div>

                            <button
                                type="button"
                                role="switch"
                                :aria-checked="
                                    role.locked || roleEnabled(role.name)
                                "
                                :disabled="role.locked || form.processing"
                                class="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                :class="
                                    role.locked || roleEnabled(role.name)
                                        ? 'bg-primary'
                                        : 'bg-input'
                                "
                                @click="toggleRole(role)"
                            >
                                <span
                                    class="pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform"
                                    :class="
                                        role.locked || roleEnabled(role.name)
                                            ? 'translate-x-5'
                                            : 'translate-x-0'
                                    "
                                />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Cog class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Generation des commissions
                        </h3>
                    </div>

                    <div class="divide-y">
                        <label
                            v-for="option in commission_options"
                            :key="option.value"
                            class="flex cursor-pointer items-start gap-3 px-5 py-4"
                        >
                            <input
                                v-model="form.commission_generation_mode"
                                type="radio"
                                name="commission_generation_mode"
                                :value="option.value"
                                class="mt-1 h-4 w-4 border-input text-primary focus:ring-ring"
                            />
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">
                                    {{ option.label }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ option.description }}
                                </p>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end">
                    <Button
                        :disabled="form.processing || !form.isDirty"
                        @click="submit"
                    >
                        Enregistrer
                    </Button>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
