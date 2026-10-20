<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit, update } from '@/routes/organisation';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Upload, X } from 'lucide-vue-next';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';

interface Props {
    organisation: {
        name: string;
        slug: string;
        code: string;
        siret: string | null;
        logo_url: string | null;
        pied_page_documents: string | null;
    };
    login_url: string;
}

const props = defineProps<Props>();
const toast = useToast();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: "Informations de l'organisation", href: edit().url },
];

const form = useForm({
    _method: 'put',
    name: props.organisation.name,
    code: props.organisation.code,
    siret: props.organisation.siret ?? '',
    logo: null as File | null,
    remove_logo: false,
    pied_page_documents: props.organisation.pied_page_documents ?? '',
});

const linkCopied = ref(false);

async function copyLoginUrl() {
    await navigator.clipboard.writeText(props.login_url);
    linkCopied.value = true;
    setTimeout(() => (linkCopied.value = false), 2000);
}

const logoPreview = ref<string | null>(props.organisation.logo_url);
const fileInput = ref<HTMLInputElement | null>(null);

function onLogoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (file) {
        form.logo = file;
        form.remove_logo = false;
        logoPreview.value = URL.createObjectURL(file);
    }
}

function removeLogo() {
    form.logo = null;
    form.remove_logo = true;
    logoPreview.value = null;
    if (fileInput.value) fileInput.value.value = '';
}

function submit() {
    form.post(update().url, {
        preserveScroll: true,
        // Toast à chaque enregistrement : le flash serveur, identique d'une fois sur l'autre, ne
        // déclencherait un toast qu'au premier (cf. useFlashToast).
        onSuccess: () => {
            form.remove_logo = false;
            form.logo = null;
            toast.add({
                group: 'top',
                severity: 'success',
                summary: 'Organisation mise à jour',
                detail: 'Les modifications ont été enregistrées.',
                life: 4000,
            });
        },
        onError: () => {
            toast.add({
                group: 'top',
                severity: 'error',
                summary: 'Modifications non enregistrées',
                detail: 'Corrigez les champs signalés.',
                life: 5000,
            });
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Informations de l'organisation" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Informations de l'organisation"
                    description="Identité affichée dans l'application (nom, logo)"
                />

                <form class="space-y-6" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name"
                            >Nom <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="name"
                            v-model="form.name"
                            class="mt-1 block w-full max-w-md"
                            required
                            placeholder="Nom de l'organisation"
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="siret">SIRET</Label>
                        <Input
                            id="siret"
                            v-model="form.siret"
                            class="mt-1 block w-full max-w-md"
                            placeholder="SIRET (optionnel)"
                        />
                        <InputError class="mt-2" :message="form.errors.siret" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Slug</Label>
                        <p
                            class="flex h-9 w-full max-w-md items-center rounded-md border border-input bg-muted/40 px-3 font-mono text-sm text-muted-foreground"
                        >
                            {{ organisation.slug }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Identifiant technique, non modifiable.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="code"
                            >Code organisation
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            id="code"
                            v-model="form.code"
                            class="mt-1 block w-full max-w-md font-mono uppercase"
                            required
                            maxlength="20"
                            placeholder="EX: FDO"
                        />
                        <InputError class="mt-2" :message="form.errors.code" />
                        <p class="text-xs text-muted-foreground">
                            Généré automatiquement à la création, modifiable
                            ici. Identifie l'organisation sur la page de
                            connexion avant même la saisie du téléphone (lien
                            ci-dessous).
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label>Lien de connexion dédié</Label>
                        <div class="flex max-w-md gap-2">
                            <p
                                class="flex h-9 flex-1 items-center overflow-x-auto rounded-md border border-input bg-muted/40 px-3 font-mono text-sm whitespace-nowrap text-muted-foreground"
                            >
                                {{ login_url }}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                title="Cliquez pour copier le lien de connexion"
                                @click="copyLoginUrl"
                            >
                                <Check v-if="linkCopied" class="h-4 w-4" />
                                <i
                                    v-else
                                    class="pi pi-clipboard"
                                    style="font-size: 1rem"
                                />
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            À partager aux utilisateurs de cette organisation —
                            affiche directement son logo et son nom sur l'écran
                            de connexion.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label>Logo</Label>
                        <div class="flex items-start gap-6">
                            <div class="shrink-0">
                                <div
                                    class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border bg-muted/30"
                                >
                                    <img
                                        v-if="logoPreview"
                                        :src="logoPreview"
                                        alt="Logo"
                                        class="h-full w-full object-contain"
                                    />
                                    <span
                                        v-else
                                        class="text-2xl text-muted-foreground/40"
                                        >🏢</span
                                    >
                                </div>
                            </div>
                            <div class="flex flex-col gap-3">
                                <input
                                    ref="fileInput"
                                    type="file"
                                    accept="image/jpg,image/jpeg,image/png,image/webp"
                                    class="hidden"
                                    @change="onLogoChange"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="fileInput?.click()"
                                >
                                    <Upload class="mr-2 h-4 w-4" />
                                    {{
                                        logoPreview
                                            ? 'Changer'
                                            : 'Ajouter un logo'
                                    }}
                                </Button>
                                <Button
                                    v-if="logoPreview"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive hover:text-destructive"
                                    @click="removeLogo"
                                >
                                    <X class="mr-2 h-4 w-4" /> Supprimer
                                </Button>
                                <p class="text-xs text-muted-foreground">
                                    JPG, PNG ou WebP — max 3 Mo
                                </p>
                                <InputError :message="form.errors.logo" />
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="pied_page_documents"
                            >Pied de page des factures et bons</Label
                        >
                        <textarea
                            id="pied_page_documents"
                            v-model="form.pied_page_documents"
                            rows="6"
                            class="mt-1 block w-full max-w-xl rounded-md border border-input bg-background px-3 py-2 text-sm focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder="Raison sociale&#10;Adresse&#10;N° RCCM — NIF&#10;Téléphones&#10;Email"
                        />
                        <InputError
                            class="mt-2"
                            :message="form.errors.pied_page_documents"
                        />
                        <p class="text-xs text-muted-foreground">
                            Affiché centré en bas de chaque page des factures et
                            bons PDF, une ligne du PDF par ligne saisie :
                            appuyez sur Entrée entre chaque information (6
                            lignes de 150 caractères au plus). Laisser vide pour
                            ne pas l'afficher.
                        </p>
                    </div>

                    <div class="flex items-center gap-4">
                        <Button :disabled="form.processing">Enregistrer</Button>
                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p
                                v-show="form.recentlySuccessful"
                                class="text-sm text-neutral-600"
                            >
                                Enregistré.
                            </p>
                        </Transition>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
