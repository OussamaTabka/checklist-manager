<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { analyzePage } from '@/lib/pageAnalysis'

const auth = useAuthStore()

const url = ref('')
const loading = ref(false)
const errorMessage = ref('')
const result = ref(null)

const prettyJson = computed(() => {
  if (!result.value) return ''
  return JSON.stringify(result.value, null, 2)
})

function normalizeUrl(value) {
  const trimmed = String(value || '').trim()
  if (!trimmed) {
    throw new Error('Please enter a URL.')
  }

  let parsed
  try {
    parsed = new URL(trimmed)
  } catch {
    throw new Error('Invalid URL.')
  }

  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    throw new Error('URL must start with http:// or https://')
  }

  return parsed.toString()
}

async function onAnalyze() {
  loading.value = true
  errorMessage.value = ''
  result.value = null

  try {
    const normalized = normalizeUrl(url.value)
    result.value = await analyzePage(normalized, auth.token)
  } catch (error) {
    errorMessage.value = error?.message || 'Analysis failed.'
  } finally {
    loading.value = false
  }
}

function onClear() {
  url.value = ''
  errorMessage.value = ''
  result.value = null
}
</script>

<template>
  <section class="card stack">
    <div class="section-header" style="margin-bottom: 0">
      <h3>Page Analysis</h3>
      <div class="actions">
        <button class="btn btn-secondary btn-sm" type="button" @click="onClear" :disabled="loading">
          Clear
        </button>
        <button class="btn btn-primary btn-sm" type="button" @click="onAnalyze" :disabled="loading">
          Analyze
        </button>
      </div>
    </div>

    <div class="field">
      <label for="page-analysis-url">URL</label>
      <input
        id="page-analysis-url"
        v-model.trim="url"
        type="url"
        inputmode="url"
        autocomplete="url"
        placeholder="https://example.com"
        :disabled="loading"
        @keydown.enter.prevent="onAnalyze"
      />
    </div>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
    <p v-else-if="loading" class="muted">Analyzing…</p>

    <div v-if="result" class="stack" style="gap: 0.75rem">
      <h4 style="margin: 0">Result JSON</h4>
      <pre class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs overflow-auto"><code>{{ prettyJson }}</code></pre>
    </div>
  </section>
</template>
