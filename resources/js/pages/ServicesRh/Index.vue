<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    MoreHorizontal,
    Pencil,
    Plus,
    PowerOff,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

interface ServiceRh {
    id: string;
    libelle: string;
    code: string | null;
    is_active: boolean;
    employes_count: number;
}

const props = defineProps<{
    services: ServiceRh[];
}>();

useFlashToast();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Services RH', href: '/backoffice/services-rh' },
];

function employesLabel(count: number): string {
    return `${count} ${count > 1 ? 'employés' : 'employé'}`;
}

function toggle(service: ServiceRh) {
    router.patch(
        `/backoffice/services-rh/${service.id}/toggle`,
        {},
        { preserveScroll: true },
    );
}

const showDialog = ref(false);
const editingService = ref<ServiceRh | null>(null);

const dialogTitle = computed(() =>
    editingService.value ? 'Modifier le service' : 'Créer un service',
);

const form = useForm({
    libelle: '',
});

function openCreate() {
    editingService.value = null;
    form.reset();
    form.clearErrors();
    showDialog.value = true;
}

function openEdit(service: ServiceRh) {
    editingService.value = service;
    form.libelle = service.libelle;
    form.clearErrors();
    showDialog.value = true;
}

function handleSubmit() {
    if (editingService.value) {
        form.put(`/backoffice/services-rh/${editingService.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showDialog.value = false;
            },
        });
    } else {
        form.post('/backoffice/services-rh', {
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
        <Head title="Services RH" />

        <SettingsLayout>
            <div class="space-y-6">
                <div class="flex items-center justify-between gap-4">
                    <HeadingSmall
                        title="Services RH"
                        description="Les services ou départements de votre organisation (ex : Logistique, Commercial)."
                    />
                    <Button size="sm" @click="openCreate">
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau service
                    </Button>
                </div>

                <div
                    v-if="props.services.length === 0"
                    class="flex flex-col items-center gap-4 rounded-xl border border-dashed bg-card px-6 py-16 text-center"
                >
                    <Building2 class="h-10 w-10 text-muted-foreground" />
                    <div>
                        <p class="font-medium">Aucun service pour le moment</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Créez les services de votre organisation pour y
                            rattacher vos employés.
                        </p>
                    </div>
                    <Button size="sm" @click="openCreate">
                        <Plus class="mr-2 h-4 w-4" />
                        Créer un service
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
                                        Service
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
                                    v-for="service in props.services"
                                    :key="service.id"
                                    class="hover:bg-muted/20"
                                >
                                    <td class="px-6 py-4">
                                        <p
                                            class="flex items-center gap-2 font-medium"
                                        >
                                            {{ service.libelle }}
                                            <span
                                                v-if="service.code"
                                                class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] tracking-wide text-muted-foreground uppercase"
                                            >
                                                {{ service.code }}
                                            </span>
                                        </p>
                                    </td>
                                    <td class="px-6 py-4 text-muted-foreground">
                                        {{
                                            employesLabel(
                                                service.employes_count,
                                            )
                                        }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <StatusDot
                                            :status="
                                                service.is_active
                                                    ? 'actif'
                                                    : 'inactif'
                                            "
                                            :label="
                                                service.is_active
                                                    ? 'Actif'
                                                    : 'Inactif'
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
                                                    >Service</DropdownMenuLabel
                                                >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    @click="openEdit(service)"
                                                >
                                                    <Pencil
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Modifier
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    @click="toggle(service)"
                                                >
                                                    <PowerOff
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    {{
                                                        service.is_active
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
                placeholder="Ex : Logistique"
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
                    {{ editingService ? 'Enregistrer' : 'Créer' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
