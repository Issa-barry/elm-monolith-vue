<script setup lang="ts">
/**
 * Saisie d'un code à 6 chiffres, une case par chiffre — extrait de Invitations/Accept.vue (étape
 * OTP de l'onboarding par invitation) pour être réutilisé ailleurs (Install/Wizard.vue, étape
 * Super Admin) sans dupliquer la logique de saisie/collage/navigation clavier. Purement UI :
 * n'appelle aucune API lui-même, se contente d'exposer le code assemblé via v-model.
 */
import { nextTick, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        length?: number;
        disabled?: boolean;
        autofocus?: boolean;
    }>(),
    {
        length: 6,
        disabled: false,
        autofocus: true,
    },
);

const emit = defineEmits<{
    'update:modelValue': [string];
}>();

const digits = ref<string[]>(
    Array.from({ length: props.length }, (_, i) => props.modelValue[i] ?? ''),
);
const inputs = ref<(HTMLInputElement | null)[]>([]);

// Réhydratation externe (ex: après un renvoi de code qui vide le v-model côté parent).
watch(
    () => props.modelValue,
    (value) => {
        const next = Array.from({ length: props.length }, (_, i) => value[i] ?? '');
        if (next.join('') !== digits.value.join('')) {
            digits.value = next;
        }
    },
);

function emitCode(): void {
    emit('update:modelValue', digits.value.join(''));
}

function handleInput(index: number, e: Event): void {
    const input = e.target as HTMLInputElement;
    const raw = input.value.replace(/\D/g, '');

    if (raw.length > 1) {
        // Autofill / collage rapide démarrant sur cette case.
        raw.split('').forEach((d, i) => {
            if (index + i < digits.value.length) {
                digits.value[index + i] = d;
            }
        });
        const lastIndex = Math.min(index + raw.length - 1, digits.value.length - 1);
        input.value = digits.value[index];
        inputs.value[lastIndex]?.focus();
        emitCode();
        return;
    }

    digits.value[index] = raw;
    input.value = raw;
    if (raw && index < digits.value.length - 1) {
        inputs.value[index + 1]?.focus();
    }
    emitCode();
}

function handleKeydown(index: number, e: KeyboardEvent): void {
    if (e.key === 'Backspace' && !digits.value[index] && index > 0) {
        inputs.value[index - 1]?.focus();
    }
}

function handlePaste(e: ClipboardEvent): void {
    const pasted = e.clipboardData?.getData('text').replace(/\D/g, '') ?? '';
    if (!pasted) return;
    e.preventDefault();

    const values = pasted.slice(0, digits.value.length).split('');
    values.forEach((d, i) => {
        digits.value[i] = d;
    });
    inputs.value[Math.min(values.length, digits.value.length - 1)]?.focus();
    emitCode();
}

function clearAndFocus(): void {
    digits.value = digits.value.map(() => '');
    emitCode();
    nextTick(() => inputs.value[0]?.focus());
}

defineExpose({ clearAndFocus });
</script>

<template>
    <div class="flex justify-center gap-2">
        <input
            v-for="(digit, index) in digits"
            :key="index"
            :ref="(el) => (inputs[index] = el as HTMLInputElement)"
            :value="digit"
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="1"
            :disabled="disabled"
            :autofocus="autofocus && index === 0"
            :autocomplete="index === 0 ? 'one-time-code' : 'off'"
            class="h-14 w-12 rounded-md border border-input bg-transparent text-center text-xl font-semibold shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
            @input="handleInput(index, $event)"
            @keydown="handleKeydown(index, $event)"
            @paste="handlePaste"
        />
    </div>
</template>
