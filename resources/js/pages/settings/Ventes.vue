<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Lock, PackageCheck, ShieldCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface RoleQuantite {
    name: string;
    label: string;
    can_update_quantite: boolean;
    can_update_prix_unitaire: boolean;
    locked: boolean;
}

const props = defineProps<{
    roles: RoleQuantite[];
    autoriser_saisie_dessous_qte_max: boolean;
    controle_impayes_actif: boolean;
    seuil_impayes_max: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Parametres', href: '/settings/profile' },
    { title: 'Parametrage ventes', href: '/settings/ventes' },
];

const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as Record<string, string>)?.success ?? null,
);

const form = useForm({
    quantity_edit_role_names: props.roles
        .filter((role) => role.can_update_quantite && !role.locked)
        .map((role) => role.name),
    price_edit_role_names: props.roles
        .filter((role) => role.can_update_prix_unitaire && !role.locked)
        .map((role) => role.name),
    autoriser_saisie_dessous_qte_max: props.autoriser_saisie_dessous_qte_max,
    controle_impayes_actif: props.controle_impayes_actif,
    seuil_impayes_max: props.seuil_impayes_max,
});

type EditableRoleField = 'quantity_edit_role_names' | 'price_edit_role_names';

function roleEnabled(roleName: string, field: EditableRoleField): boolean {
    return form[field].includes(roleName);
}

function toggleRole(role: RoleQuantite, field: EditableRoleField) {
    if (role.locked) {
        return;
    }

    if (roleEnabled(role.name, field)) {
        form[field] = form[field].filter((name) => name !== role.name);

        return;
    }

    form[field] = [...form[field], role.name];
}

function submit() {
    form.put('/settings/ventes', { preserveScroll: true });
}

// ── Formatage seuil impayés ───────────────────────────────────────────────────
function formatSeuil(val: number): string {
    return val > 0 ? new Intl.NumberFormat('fr-FR').format(val) : '';
}

const seuilDisplay = ref(formatSeuil(props.seuil_impayes_max));

watch(
    () => form.seuil_impayes_max,
    (val) => {
        if (document.activeElement?.id !== 'seuil-impayes-input') {
            seuilDisplay.value = formatSeuil(val);
        }
    },
);

function onSeuilInput(e: Event) {
    const raw = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    form.seuil_impayes_max = raw ? parseInt(raw, 10) : 0;
}

function onSeuilFocus() {
    seuilDisplay.value =
        form.seuil_impayes_max > 0 ? String(form.seuil_impayes_max) : '';
}

function onSeuilBlur() {
    seuilDisplay.value = formatSeuil(form.seuil_impayes_max);
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
                                    role.locked ||
                                    roleEnabled(
                                        role.name,
                                        'quantity_edit_role_names',
                                    )
                                "
                                :disabled="role.locked || form.processing"
                                class="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                :class="
                                    role.locked ||
                                    roleEnabled(
                                        role.name,
                                        'quantity_edit_role_names',
                                    )
                                        ? 'bg-primary'
                                        : 'bg-input'
                                "
                                @click="
                                    toggleRole(role, 'quantity_edit_role_names')
                                "
                            >
                                <span
                                    class="pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform"
                                    :class="
                                        role.locked ||
                                        roleEnabled(
                                            role.name,
                                            'quantity_edit_role_names',
                                        )
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
                        <Lock class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Profils autorises a modifier le prix unitaire
                        </h3>
                    </div>

                    <div class="divide-y">
                        <div
                            v-for="role in roles"
                            :key="`price-${role.name}`"
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
                                    role.locked ||
                                    roleEnabled(
                                        role.name,
                                        'price_edit_role_names',
                                    )
                                "
                                :disabled="role.locked || form.processing"
                                class="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                :class="
                                    role.locked ||
                                    roleEnabled(
                                        role.name,
                                        'price_edit_role_names',
                                    )
                                        ? 'bg-primary'
                                        : 'bg-input'
                                "
                                @click="
                                    toggleRole(role, 'price_edit_role_names')
                                "
                            >
                                <span
                                    class="pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform"
                                    :class="
                                        role.locked ||
                                        roleEnabled(
                                            role.name,
                                            'price_edit_role_names',
                                        )
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
                        <PackageCheck class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Quantite de chargement
                        </h3>
                    </div>

                    <div
                        class="flex items-center justify-between gap-4 px-5 py-4"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-foreground">
                                Autoriser la saisie en dessous de la quantite
                                maximale
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Si desactive, chaque commande doit remplir
                                exactement la capacite du vehicule.
                            </p>
                        </div>

                        <button
                            type="button"
                            role="switch"
                            :aria-checked="
                                form.autoriser_saisie_dessous_qte_max
                            "
                            :disabled="form.processing"
                            class="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            :class="
                                form.autoriser_saisie_dessous_qte_max
                                    ? 'bg-primary'
                                    : 'bg-input'
                            "
                            @click="
                                form.autoriser_saisie_dessous_qte_max =
                                    !form.autoriser_saisie_dessous_qte_max
                            "
                        >
                            <span
                                class="pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform"
                                :class="
                                    form.autoriser_saisie_dessous_qte_max
                                        ? 'translate-x-5'
                                        : 'translate-x-0'
                                "
                            />
                        </button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <AlertTriangle class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Controle des impayes
                        </h3>
                    </div>

                    <div
                        class="flex items-center justify-between gap-4 px-5 py-4"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-foreground">
                                Activer le blocage sur seuil d'impayes
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Si active, la creation de commande est interdite
                                lorsque la dette du client ou du vehicule depasse
                                le seuil ci-dessous.
                            </p>
                        </div>

                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.controle_impayes_actif"
                            :disabled="form.processing"
                            class="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            :class="
                                form.controle_impayes_actif
                                    ? 'bg-primary'
                                    : 'bg-input'
                            "
                            @click="
                                form.controle_impayes_actif =
                                    !form.controle_impayes_actif
                            "
                        >
                            <span
                                class="pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform"
                                :class="
                                    form.controle_impayes_actif
                                        ? 'translate-x-5'
                                        : 'translate-x-0'
                                "
                            />
                        </button>
                    </div>

                    <div class="border-t px-5 py-4">
                        <div class="flex items-center gap-4">
                            <div class="min-w-0 flex-1">
                                <p
                                    class="text-sm font-medium"
                                    :class="
                                        form.controle_impayes_actif
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    Seuil maximum de dette autorise (GNF)
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    La commande est bloquee si la dette depasse
                                    ce montant. 0 = aucune dette toleree.
                                </p>
                            </div>
                            <div class="relative">
                                <input
                                    id="seuil-impayes-input"
                                    type="text"
                                    inputmode="numeric"
                                    :value="seuilDisplay"
                                    placeholder="0"
                                    :disabled="
                                        !form.controle_impayes_actif ||
                                        form.processing
                                    "
                                    class="w-52 rounded-md border bg-background py-2 pl-3 pr-14 text-right text-lg font-bold tabular-nums shadow-sm focus:outline-none focus:ring-2 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    @input="onSeuilInput"
                                    @focus="onSeuilFocus"
                                    @blur="onSeuilBlur"
                                />
                                <span
                                    class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-medium text-muted-foreground"
                                    >GNF</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="flashSuccess"
                    class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                >
                    {{ flashSuccess }}
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
