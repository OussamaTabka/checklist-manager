<script setup>
import { computed, ref } from 'vue'
import { Upload } from 'lucide-vue-next'
import { analyzeImportedUserStories } from '@/lib/projectUserStories'
import { localizeError, localizeMessage } from '@/lib/localization'

const STORAGE_KEY = 'user_stories_v1'

const selectedFile = ref(null)
const previewRows = ref([])
const validationRows = ref([])
const importSummary = ref(null)
const pageError = ref('')
const loading = ref(false)

const hasPreview = computed(() => previewRows.value.length > 0)

function clearState() {
  previewRows.value = []
  validationRows.value = []
  importSummary.value = null
  pageError.value = ''
}

function onFileChange(event) {
  clearState()
  selectedFile.value = event.target.files?.[0] || null
}

function loadStories() {
  const raw = localStorage.getItem(STORAGE_KEY)

  if (!raw) {
    return []
  }

  try {
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function saveStories(stories) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(stories))
}

async function validateFile() {
  if (!selectedFile.value) {
    pageError.value = 'Veuillez selectionner un fichier.'
    return
  }

  clearState()
  loading.value = true

  try {
    const analysis = await analyzeImportedUserStories(selectedFile.value)
    importSummary.value = analysis
    previewRows.value = analysis.validStories.slice(0, 20)
    validationRows.value = [
      ...analysis.validStories.map((story, index) => ({
        rowNumber: index + 1,
        status: 'ok',
        errors: [],
        story,
      })),
      ...analysis.errors.map((error) => ({
        rowNumber: error.row,
        status: 'error',
        errors: [error.message],
        story: null,
      })),
    ].sort((left, right) => left.rowNumber - right.rowNumber)
  } catch (error) {
    pageError.value = localizeError(error, 'user_story_file_read_failed')
  } finally {
    loading.value = false
  }
}

function runImport() {
  if (!validationRows.value.length) {
    pageError.value = localizeMessage('Validez le fichier avant import.')
    return
  }

  const currentStories = loadStories()
  let maxId = currentStories.reduce((max, item) => Math.max(max, Number(item.id) || 0), 0)

  const created = []
  const rejected = []

  validationRows.value.forEach((row) => {
    if (row.status === 'error' || !row.story) {
      rejected.push({ rowNumber: row.rowNumber, errors: row.errors })
      return
    }

    maxId += 1
    created.push({ ...row.story, id: maxId })
  })

  saveStories([...currentStories, ...created])

  importSummary.value = {
    total: validationRows.value.length,
    created: created.length,
    ignored: rejected.length,
    errors: rejected.length,
    details: rejected,
  }
}
</script>

<template>
  <section class="page stack">
    <div class="section-header">
      <div>
        <h1>Import User Stories</h1>
        <p class="muted">Import CSV, XLSX ou JSON avec previsualisation, validation et rapport d erreurs.</p>
      </div>
    </div>

    <div class="card stack">
      <div class="field">
        <label>Fichier (CSV, XLSX, JSON)</label>
        <input type="file" accept=".csv,.xlsx,.json" @change="onFileChange" />
      </div>

      <p v-if="pageError" class="error">{{ pageError }}</p>

      <div class="actions">
        <button class="btn btn-secondary" :disabled="loading" @click="validateFile">
          <Upload :size="16" />
          {{ loading ? 'Validation...' : 'Valider le fichier' }}
        </button>
        <button class="btn btn-primary" :disabled="!validationRows.length" @click="runImport">
          Lancer import
        </button>
      </div>
    </div>

    <div class="card" v-if="hasPreview">
      <h3>Preview (20 premieres lignes max)</h3>
      <div class="table-wrap table-gap-top">
        <table>
          <thead>
            <tr>
              <th>Ligne</th>
              <th>Référence</th>
              <th>Titre</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in previewRows" :key="`${row.story_id || row.title}-${index}`">
              <td>{{ index + 1 }}</td>
              <td>{{ row.story_id || '-' }}</td>
              <td>{{ row.title }}</td>
              <td>{{ row.description }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" v-if="validationRows.length">
      <h3>Validation</h3>
      <div class="table-wrap table-gap-top">
        <table>
          <thead>
            <tr>
              <th>Ligne</th>
              <th>Statut</th>
              <th>Detail</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in validationRows" :key="`validation-${row.rowNumber}`">
              <td>{{ row.rowNumber }}</td>
              <td>
                <span class="tag" :class="row.status === 'ok' ? 'active' : 'inactive'">
                  {{ row.status === 'ok' ? 'OK' : 'Erreur' }}
                </span>
              </td>
              <td>
                <span v-if="row.status === 'ok'" class="muted">Valide</span>
                <ul v-else class="list-no-margin">
                  <li v-for="error in row.errors" :key="error">{{ error }}</li>
                </ul>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" v-if="importSummary">
      <h3>Resume import</h3>
      <div class="stats-grid table-gap-top">
        <article class="stat-card">
          <p class="stat-label">Total</p>
          <p class="stat-value">{{ importSummary.total }}</p>
        </article>
        <article class="stat-card">
          <p class="stat-label">Creees</p>
          <p class="stat-value">{{ importSummary.created }}</p>
        </article>
        <article class="stat-card">
          <p class="stat-label">Ignorees</p>
          <p class="stat-value">{{ importSummary.ignored }}</p>
        </article>
        <article class="stat-card">
          <p class="stat-label">Erreurs</p>
          <p class="stat-value">{{ importSummary.errors }}</p>
        </article>
      </div>

      <div v-if="importSummary.details.length" class="table-wrap table-gap-top">
        <table>
          <thead>
            <tr>
              <th>Ligne</th>
              <th>Erreurs</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="detail in importSummary.details" :key="`detail-${detail.rowNumber}`">
              <td>{{ detail.rowNumber }}</td>
              <td>{{ detail.errors.join(' | ') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>
