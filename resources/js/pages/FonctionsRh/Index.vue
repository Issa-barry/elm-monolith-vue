<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Briefcase,
    MoreHorizontal,
    Pencil,
    Plus,
    PowerOff,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

interface FonctionRh {
    id: string;
    libelle: string;
    code: string | null;
    is_active: boolean;
    employes_count: number;
}

const props = defineProps<{
    fonctions: FonctionRh[];
}>();

useFlashToast();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Fonctions RH', href: '/backoffice/fonctions-rh' },
];

function employesLabel(count: number): string {
    return `${count} ${count > 1 ? 'employés' : 'employé'}`;
}

function toggle(fonction: FonctionRh) {
    router.patch(
        `/backoffice/fonctions-rh/${fonction.id}/toggle`,
        {},
        { preserveScroll: true },
    );
}

// ── Dialog Create / Edit — jamais de navigation vers une autre page, mirroring
// Produits/Categories/Index.vue (même raisonnement : petit référentiel organisationnel, création
// à la volée depuis n'importe quel formulaire qui en a besoin). ───────────────────────────────

const showDialog = ref(false);
const editingFonction = ref<FonctionRh | null>(null);

const dialogTitle = computed(() =>
    editingFonction.value ? 'Modifier la fonction' : 'Créer une fonction RH',
);

const form = useForm({
    libelle: '',
});

function openCreate() {
    editingFonction.value = null;
    form.reset();
    form.clearErrors();
    showDialog.value = true;
}

function openEdit(fonction: FonctionRh) {
    editingFonction.value = fonction;
    form.libelle = fonction.libelle;
    form.clearErrors();
    showDialog.value = true;
}

function handleSubmit() {
    if (editingFonction.value) {
        form.put(`/backoffice/fonctions-rh/${editingFonction.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showDialog.value = false;
            },
        });
    } else {
        form.post('/backoffice/fonctions-rh', {
            preserveScroll: true,
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
            },
        });
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems" :hide-mobile-header="true">
        <Head title="Fonctions RH" />

        <SettingsLayout>
            <div class="space-y-6">
                <div class="flex items-center justify-between gap-4">
                    <HeadingSmall
                        title="Fonctions RH"
                        description="Le métier de vos employés — indépendant de leur profil d'accès."
                    />
                    <Button size="sm" @click="openCreate">
                        <Plus class="mr-2 h-4 w-4" />
                        Nouvelle fonction
                    </Button>
                </div>

                <div
                    v-if="props.fonctions.length === 0"
                    class="flex flex-col items-center gap-4 rounded-xl border border-dashed bg-card px-6 py-16 text-center"
                >
                    <Briefcase class="h-10 w-10 text-muted-foreground" />
                    <div>
                        <p class="font-medium">
                            Aucune fonction RH pour le moment
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Chaque organisation définit ses propres fonctions
                            (ex : « Gérant de dépôt », « Comptable »...) —
                            commencez par en créer une.
                        </p>
                    </div>
                    <Button size="sm" @click="openCreate">
                        <Plus class="mr-2 h-4 w-4" />
                        Créer une fonction
                    </Button>
                </div>

                <div v-else class="overflow-hidden rounded-xl border bg-card">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b bg-muted/20 text-xs tracking-wide text-muted-foreground uppercase"
                                >
                                    <th
                                        class="px-6 py-3 text-left font-semibold"
                                    >
                                        Fonction
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left font-semibold"
                                    >
                                        Employés
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left font-semibold"
                                    >
                                        Statut
                                    </th>
                                    <th class="w-12 px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="fonction in props.fonctions"
                                    :key="fonction.id"
                                    class="hover:bg-muted/20"
                                >
                                    <td class="px-6 py-4">
                                        <p
                                            class="flex items-center gap-2 font-medium"
                                        >
                                            {{ fonction.libelle }}
                                            <span
                                                v-if="fonction.code"
                                                class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] tracking-wide text-muted-foreground uppercase"
                                            >
                                                {{ fonction.code }}
                                            </span>
                                        </p>
                                    </td>
                                    <td
                                        class="px-6 py-4 text-muted-foreground"
                                    >
                                        {{
                                            employesLabel(
                                                fonction.employes_count,
                                            )
                                        }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <StatusDot
                                            :status="
                                                fonction.is_active
                                                    ? 'actif'
                                                    : 'inactif'
                                            "
                                            :label="
                                                fonction.is_active
                                                    ? 'Active'
                                                    : 'Inactive'
                                            "
                                        />
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    class="h-8 w-8"
                                                >
                                                    <MoreHorizontal
                                                        class="h-4 w-4"
                                                    />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                class="w-56"
                                            >
                                                <DropdownMenuLabel
                                                    >Fonction</DropdownMenuLabel
                                                >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    @click="openEdit(fonction)"
                                                >
                                                    <Pencil
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Modifier
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    @click="toggle(fonction)"
                                                >
                                                    <PowerOff
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    {{
                                                        fonction.is_active
                                                            ? 'Désactiver'
                                                            : 'Activer'
                                                    }}
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>

    <!-- Dialog Create / Edit -->
    <Dialog
        v-model:visible="showDialog"
        modal
        :header="dialogTitle"
        :style="{ width: 'min(26rem, 95vw)' }"
        :draggable="false"
        @hide="form.clearErrors()"
    >
        <div class="space-y-1.5">
            <Label for="libelle"
                >Libellé <span class="text-destructive">*</span></Label
            >
            <InputText
                id="libelle"
                v-model="form.libelle"
                class="w-full"
                :class="form.errors.libelle ? 'p-invalid' : ''"
                placeholder="Ex : Gérant de dépôt"
                autofocus
                @keyup.enter="handleSubmit"
            />
            <p v-if="form.errors.libelle" class="text-xs text-destructive">
                {{ form.errors.libelle }}
            </p>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="outline" @click="showDialog = false"
                    >Annuler</Button
                >
                <Button
                    :disabled="form.processing || !form.libelle.trim()"
                    @click="handleSubmit"
                >
                    {{ editingFonction ? 'Enregistrer' : 'Créer' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
