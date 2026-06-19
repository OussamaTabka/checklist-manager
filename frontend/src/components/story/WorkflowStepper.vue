<script setup>
defineProps({
  steps: {
    type: Array,
    default: () => [],
  },
  currentStep: {
    type: Number,
    default: 1,
  },
})
</script>

<template>
  <ol class="flex flex-nowrap items-center gap-2">
    <li
      v-for="step in steps"
      :key="step.id"
      class="flex items-center gap-2 rounded-full border px-3 py-2 text-sm transition"
      :class="step.id <= currentStep
        ? 'border-sky-200 bg-sky-50 text-sky-900'
        : 'border-slate-200 bg-white text-slate-500'"
    >
      <span
        class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold"
        :class="step.id <= currentStep ? 'bg-sky-600 text-white' : 'bg-slate-100 text-slate-500'"
      >
        {{ step.id }}
      </span>
      <span class="font-medium whitespace-nowrap">{{ step.label }}</span>
    </li>
  </ol>
</template>
