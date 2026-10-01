<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useForm } from '@inertiajs/vue3';
import { Save } from 'lucide-vue-next';

const props = defineProps<{
    agentId: string;
}>();

// Même requête que l'onglet Mot de passe de users.edit ; `depuis_fiche` ramène sur la fiche.
const form = useForm({
    password: '',
    password_confirmation: '',
    depuis_fiche: true,
});

function enregistrer(): void {
    form.put(`/backoffice/users/${props.agentId}/password`, {
        preserveScroll: true,
        onSuccess: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <form
        class="rounded-xl border bg-card p-5 sm:p-6"
        autocomplete="off"
        data-testid="agent-mot-de-passe-panel"
        @submit.prevent="enregistrer"
    >
        <h2
            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
        >
            Nouveau mot de passe
        </h2>
        <p class="mt-1 text-xs text-muted-foreground">
            8 caractères minimum, avec au moins une lettre et un chiffre.
        </p>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium">
                    Mot de passe
                    <span class="text-destructive">*</span>
                </label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="{ 'border-destructive': form.errors.password }"
                />
                <p
                    v-if="form.errors.password"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.password }}
                </p>
            </div>
            <div>
                <label
                    for="password_confirmation"
                    class="mb-1.5 block text-sm font-medium"
                >
                    Confirmer
                    <span class="text-destructive">*</span>
                </label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>
        </div>

        <div class="mt-5 flex justify-end">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" class="mr-2 h-4 w-4" />
                <Save v-else class="mr-2 h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>
    </form>
</template>
