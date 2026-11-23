<script setup lang="ts">
// Paramètres → RH → Motifs d'absence (docs/rh-absences.md, permission rh-referentiels.gerer).
// Un motif ne se supprime pas : désactivé, il n'est plus proposé à la saisie et les absences
// existantes le gardent.
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
    CalendarOff,
    Loader2,
    MoreHorizontal,
    Pencil,
    Plus,
    PowerOff,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

interface MotifAbsence {
    id: string;
    libelle: string;
    is_active: boolean;
    absences_count: number;
}

const props = defineProps<{
    motifs: MotifAbsence[];
}>();

useFlashToast();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Motifs d’absence', href: '/backoffice/motifs-absence' },
];

function absencesLabel(count: number): string {
    return `${count} absence${count > 1 ? 's' : ''}`;
}

function basculer(motif: MotifAbsence) {
    router.patch(
        `/backoffice/motifs-absence/${motif.id}/toggle`,
        {},
        { preserveScroll: true },
    );
}

const showDialog = ref(false);
const motifModifie = ref<MotifAbsence | null>(null);

const dialogTitle = computed(() =>
    motifModifie.value ? 'Modifier le motif' : 'Créer un motif',
);

const form = useForm({ libelle: '' });

function ouvrirCreation() {
    motifModifie.value = null;
    form.reset();
    form.clearErrors();
    showDialog.value = true;
}

function ouvrirModification(motif: MotifAbsence) {
    motifModifie.value = motif;
    form.libelle = motif.libelle;
    form.clearErrors();
    showDialog.value = true;
}

function enregistrer() {
    if (form.processing || !form.libelle.trim()) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showDialog.value = false;
            form.reset();
        },
    };
    if (motifModifie.value) {
        form.put(
            `/backoffice/motifs-absence/${motifModifie.value.id}`,
            options,
        );
    } else {
        form.post('/backoffice/motifs-absence', options);
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems" :hide-mobile-header="true">
        <Head title="Motifs d’absence" />

        <SettingsLayout>
            <div class="space-y-6">
                <div class="flex items-center justify-between gap-4">
                    <HeadingSmall
                        title="Motifs d’absence"
                        description="Motifs proposés à la saisie d’une absence (RH → Absences). Un motif désactivé n’est plus proposé ; les absences existantes le conservent."
                    />
                    <Button size="sm" @click="ouvrirCreation">
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau motif
                    </Button>
                </div>

                <div
                    v-if="props.motifs.length === 0"
                    class="flex flex-col items-center gap-4 rounded-xl border border-dashed bg-card px-6 py-16 text-center"
                >
                    <CalendarOff class="h-10 w-10 text-muted-foreground" />
                    <p class="font-medium">Aucun motif pour le moment</p>
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
                                        Motif
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left font-semibold"
                                    >
                                        Utilisation
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
                                    v-for="motif in props.motifs"
                                    :key="motif.id"
                                    class="hover:bg-muted/20"
                                >
                                    <td class="px-6 py-4 font-medium">
                                        {{ motif.libelle }}
                                    </td>
                                    <td class="px-6 py-4 text-muted-foreground">
                                        {{
                                            absencesLabel(motif.absences_count)
                                        }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <StatusDot
                                            :status="
                                                motif.is_active
                                                    ? 'actif'
                                                    : 'inactif'
                                            "
                                            :label="
                                                motif.is_active
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
                                                    aria-label="Actions"
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
                                                    >Motif</DropdownMenuLabel
                                                >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    @click="
                                                        ouvrirModification(
                                                            motif,
                                                        )
                                                    "
                                                >
                                                    <Pencil
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Modifier
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    @click="basculer(motif)"
                                                >
                                                    <PowerOff
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    {{
                                                        motif.is_active
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
        :closable="!form.processing"
        @hide="form.clearErrors()"
    >
        <div class="space-y-1.5">
            <Label for="motif-libelle"
                >Libellé <span class="text-destructive">*</span></Label
            >
            <InputText
                id="motif-libelle"
                v-model="form.libelle"
                class="w-full"
                :class="form.errors.libelle ? 'p-invalid' : ''"
                placeholder="Ex : Formation"
                maxlength="100"
                autofocus
                @keyup.enter="enregistrer"
            />
            <p v-if="form.errors.libelle" class="text-xs text-destructive">
                {{ form.errors.libelle }}
            </p>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="showDialog = false"
                    >Annuler</Button
                >
                <Button
                    :disabled="form.processing || !form.libelle.trim()"
                    @click="enregistrer"
                >
                    <Loader2
                        v-if="form.processing"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    {{ motifModifie ? 'Enregistrer' : 'Créer' }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
