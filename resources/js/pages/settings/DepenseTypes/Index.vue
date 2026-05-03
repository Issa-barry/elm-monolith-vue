<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Power, Trash2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref } from 'vue';

interface DepenseType {
    id: string;
    code: string;
    libelle: string;
    description: string | null;
    requires_vehicle: boolean;
    requires_comment: boolean;
    is_active: boolean;
    sort_order: number;
}

const props = defineProps<{
    types: DepenseType[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Types de dépense', href: '/settings/depense-types' },
];

// ── Dialog ────────────────────────────────────────────────────────────────────

const showDialog = ref(false);
const editingType = ref<DepenseType | null>(null);

const dialogTitle = computed(() =>
    editingType.value ? 'Modifier le type' : 'Nouveau type de dépense',
);

const form = useForm({
    code: '',
    libelle: '',
    description: '',
    requires_vehicle: false,
    requires_comment: false,
    is_active: true,
    sort_order: 0,
});

function openCreate() {
    editingType.value = null;
    form.reset();
    form.is_active = true;
    showDialog.value = true;
}

function openEdit(type: DepenseType) {
    editingType.value = type;
    form.code = type.code;
    form.libelle = type.libelle;
    form.description = type.description ?? '';
    form.requires_vehicle = type.requires_vehicle;
    form.requires_comment = type.requires_comment;
    form.is_active = type.is_active;
    form.sort_order = type.sort_order;
    showDialog.value = true;
}

function handleSubmit() {
    if (editingType.value) {
        form.put(`/settings/depense-types/${editingType.value.id}`, {
            onSuccess: () => {
                showDialog.value = false;
            },
        });
    } else {
        form.post('/settings/depense-types', {
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
            },
        });
    }
}

// ── Actions ───────────────────────────────────────────────────────────────────

function toggle(type: DepenseType) {
    router.patch(
        `/settings/depense-types/${type.id}/toggle`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function destroy(type: DepenseType) {
    if (!confirm(`Supprimer le type « ${type.libelle} » ?`)) return;
    router.delete(`/settings/depense-types/${type.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Types de dépense" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout>
            <div class="space-y-6">
                <div class="flex items-start justify-between gap-4">
                    <HeadingSmall
                        title="Types de dépense"
                        description="Catégories utilisées pour classer les dépenses opérationnelles."
                    />
                    <Button size="sm" @click="openCreate">
                        <Plus class="mr-1.5 h-3.5 w-3.5" />
                        Nouveau type
                    </Button>
                </div>

                <!-- Table -->
                <div class="rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                >
                                    Libellé / Code
                                </th>
                                <th
                                    class="px-4 py-2.5 text-center font-medium text-muted-foreground"
                                >
                                    Véhicule
                                </th>
                                <th
                                    class="px-4 py-2.5 text-center font-medium text-muted-foreground"
                                >
                                    Commentaire
                                </th>
                                <th
                                    class="px-4 py-2.5 text-center font-medium text-muted-foreground"
                                >
                                    Statut
                                </th>
                                <th
                                    class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="type in types"
                                :key="type.id"
                                class="border-b transition-colors last:border-b-0 hover:bg-muted/30"
                                :class="{ 'opacity-50': !type.is_active }"
                            >
                                <td class="px-4 py-3">
                                    <div class="font-medium">
                                        {{ type.libelle }}
                                    </div>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ type.code }}
                                    </div>
                                    <div
                                        v-if="type.description"
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ type.description }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        v-if="type.requires_vehicle"
                                        class="text-xs font-medium text-amber-600"
                                    >
                                        Requis
                                    </span>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        v-if="type.requires_comment"
                                        class="text-xs font-medium text-amber-600"
                                    >
                                        Requis
                                    </span>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <Badge
                                        :variant="
                                            type.is_active
                                                ? 'default'
                                                : 'secondary'
                                        "
                                    >
                                        {{
                                            type.is_active ? 'Actif' : 'Inactif'
                                        }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-0.5">
                                        <button
                                            type="button"
                                            :title="
                                                type.is_active
                                                    ? 'Désactiver'
                                                    : 'Activer'
                                            "
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                            @click="toggle(type)"
                                        >
                                            <Power class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            title="Modifier"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                            @click="openEdit(type)"
                                        >
                                            <Pencil class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            title="Supprimer"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                            @click="destroy(type)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="types.length === 0">
                                <td
                                    colspan="5"
                                    class="px-4 py-10 text-center text-sm text-muted-foreground"
                                >
                                    Aucun type de dépense. Créez-en un.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>

    <!-- Dialog create / edit -->
    <Dialog
        v-model:visible="showDialog"
        modal
        :header="dialogTitle"
        :style="{ width: 'min(520px, 95vw)' }"
        :dismissable-mask="true"
    >
        <form class="space-y-4 pt-2 pb-1" @submit.prevent="handleSubmit">
            <!-- Code + Libellé -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <Label
                        for="dt-code"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Code <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="dt-code"
                        v-model="form.code"
                        placeholder="ex: carburant"
                        :class="{ 'border-destructive': form.errors.code }"
                        :disabled="!!editingType"
                    />
                    <p
                        v-if="form.errors.code"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.code }}
                    </p>
                    <p
                        v-if="!editingType"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Non modifiable après création.
                    </p>
                </div>
                <div>
                    <Label
                        for="dt-libelle"
                        class="mb-1.5 block text-xs font-medium"
                    >
                        Libellé <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="dt-libelle"
                        v-model="form.libelle"
                        placeholder="ex: Carburant"
                        :class="{ 'border-destructive': form.errors.libelle }"
                    />
                    <p
                        v-if="form.errors.libelle"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.libelle }}
                    </p>
                </div>
            </div>

            <!-- Description -->
            <div>
                <Label
                    for="dt-description"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Description
                </Label>
                <textarea
                    id="dt-description"
                    v-model="form.description"
                    placeholder="Description optionnelle…"
                    rows="2"
                    class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                />
            </div>

            <!-- Options -->
            <div class="space-y-2.5">
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="dt-requires-vehicle"
                        :model-value="form.requires_vehicle"
                        @update:model-value="
                            form.requires_vehicle = $event === true
                        "
                    />
                    <div>
                        <Label
                            for="dt-requires-vehicle"
                            class="cursor-pointer text-sm font-medium"
                        >
                            Véhicule obligatoire
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            Le champ véhicule sera requis sur les dépenses de ce
                            type.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="dt-requires-comment"
                        :model-value="form.requires_comment"
                        @update:model-value="
                            form.requires_comment = $event === true
                        "
                    />
                    <div>
                        <Label
                            for="dt-requires-comment"
                            class="cursor-pointer text-sm font-medium"
                        >
                            Commentaire obligatoire
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            Un commentaire devra être saisi pour ce type.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="dt-is-active"
                        :model-value="form.is_active"
                        @update:model-value="form.is_active = $event === true"
                    />
                    <div>
                        <Label
                            for="dt-is-active"
                            class="cursor-pointer text-sm font-medium"
                        >
                            Actif
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            Un type inactif ne peut pas être utilisé sur une
                            nouvelle dépense.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Sort order -->
            <div class="w-28">
                <Label for="dt-sort" class="mb-1.5 block text-xs font-medium">
                    Ordre d'affichage
                </Label>
                <Input
                    id="dt-sort"
                    v-model.number="form.sort_order"
                    type="number"
                    min="0"
                    max="9999"
                />
            </div>

            <div class="flex justify-between pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="showDialog = false"
                >
                    Annuler
                </Button>
                <Button type="submit" size="sm" :disabled="form.processing">
                    {{
                        form.processing
                            ? 'Enregistrement…'
                            : editingType
                              ? 'Enregistrer'
                              : 'Créer'
                    }}
                </Button>
            </div>
        </form>
    </Dialog>
</template>
