<script setup>
const props = defineProps({
  tabs: {
    type: Array,
    default: () => [],
  },
  modelValue: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['update:modelValue'])

function selectTab(tabId) {
  emit('update:modelValue', tabId)
}

function isListTab(tab) {
  return Array.isArray(tab.items)
}
</script>

<template>
  <div class="rounded-3xl border border-slate-200 bg-white shadow-sm">
    <div class="flex flex-wrap gap-2 border-b border-slate-200 p-4">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition"
        :class="modelValue === tab.id
          ? 'border-slate-900 bg-slate-900 text-white'
          : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-slate-300 hover:bg-white'"
        @click="selectTab(tab.id)"
      >
        <span>{{ tab.label }}</span>
        <span
          v-if="typeof tab.badge !== 'undefined'"
          class="rounded-full px-2 py-0.5 text-xs"
          :class="modelValue === tab.id ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-700'"
        >
          {{ tab.badge }}
        </span>
      </button>
    </div>

    <div class="p-5">
      <template v-for="tab in tabs" :key="tab.id">
        <div v-if="modelValue === tab.id" class="space-y-4">
          <div v-if="!isListTab(tab)" class="rounded-2xl bg-slate-50 p-4 text-sm leading-7 text-slate-700 whitespace-pre-wrap">
            {{ tab.content || tab.emptyText }}
          </div>

          <div v-else-if="tab.items.length" class="grid gap-3">
            <div
              v-for="(item, index) in tab.items"
              :key="`${tab.id}-${index}`"
              class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700"
            >
              {{ item }}
            </div>
          </div>

          <div v-else class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">
            {{ tab.emptyText }}
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
