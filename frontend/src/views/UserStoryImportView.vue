<script setup>
import { computed, ref } from 'vue'
import { Upload } from 'lucide-vue-next'

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

function parseCsvLine(line) {
  const result = []
  let current = ''
  let inQuotes = false

  for (let i = 0; i < line.length; i += 1) {
    const char = line[i]
    const next = line[i + 1]

    if (char === '"' && inQuotes && next === '"') {
      current += '"'
      i += 1
      continue
    }

    if (char === '"') {
      inQuotes = !inQuotes
      continue
    }

    if (char === ',' && !inQuotes) {
      result.push(current)
      current = ''
      continue
    }

    current += char
  }

  result.push(current)
  return result.map((item) => item.trim())
}

function parseJsonArrayField(value, fieldName, rowNumber) {
  if (!value || String(value).trim() === '') {
    return []
  }

  try {
    const parsed = JSON.parse(value)
    if (!Array.isArray(parsed)) {
      throw new Error('not_array')
    }
    return parsed
  } catch {
    throw new Error(`Ligne ${rowNumber}: ${fieldName} doit etre un tableau JSON valide.`)
  }
}

function normalizeStory(raw, rowNumber) {
  return {
    rowNumber,
    type: raw.type || 'User Story',
    en_tant_que: String(raw.en_tant_que || '').trim(),
    je_veux_que: String(raw.je_veux_que || '').trim(),
    afin_de: String(raw.afin_de || '').trim(),
    business_rules: Array.isArray(raw.business_rules)
      ? raw.business_rules
      : parseJsonArrayField(raw.business_rules_json, 'business_rules_json', rowNumber),
    acceptance_criteria: Array.isArray(raw.acceptance_criteria)
      ? raw.acceptance_criteria
      : parseJsonArrayField(raw.acceptance_criteria_json, 'acceptance_criteria_json', rowNumber),
    scenarios: Array.isArray(raw.scenarios)
      ? raw.scenarios
      : parseJsonArrayField(raw.scenarios_json, 'scenarios_json', rowNumber),
    effort_points: Number(raw.effort_points),
    valeur: Number(raw.valeur),
  }
}

function validateStory(story) {
  const errors = []

  if (!['User Story', 'Bug', 'Tech'].includes(story.type)) {
    errors.push('Type invalide.')
  }

  if (story.en_tant_que.length < 3 || story.en_tant_que.length > 150) {
    errors.push('Champ En tant que invalide (3-150).')
  }

  if (story.je_veux_que.length < 5 || story.je_veux_que.length > 300) {
    errors.push('Champ Je veux que invalide (5-300).')
  }

  if (story.afin_de.length < 5 || story.afin_de.length > 300) {
    errors.push('Champ Afin de invalide (5-300).')
  }

  if (!Array.isArray(story.acceptance_criteria) || story.acceptance_criteria.length < 1) {
    errors.push('Au moins un critere est requis.')
  }

  if (!Array.isArray(story.scenarios) || story.scenarios.length < 1) {
    errors.push('Au moins un scenario est requis.')
  } else {
    story.scenarios.forEach((scenario, index) => {
      const valid =
        typeof scenario?.name === 'string' && scenario.name.trim().length >= 3 &&
        typeof scenario?.given === 'string' && scenario.given.trim().length >= 5 &&
        typeof scenario?.when === 'string' && scenario.when.trim().length >= 5 &&
        typeof scenario?.then === 'string' && scenario.then.trim().length >= 5

      if (!valid) {
        errors.push(`Scenario ${index + 1} invalide: name/given/when/then requis.`)
      }
    })
  }

  if (!Number.isInteger(story.effort_points) || story.effort_points < 1 || story.effort_points > 100) {
    errors.push('effort_points doit etre un entier entre 1 et 100.')
  }

  if (!Number.isInteger(story.valeur) || story.valeur < 1 || story.valeur > 1000) {
    errors.push('valeur doit etre un entier entre 1 et 1000.')
  }

  return errors
}

async function parseByExtension(file) {
  const extension = file.name.split('.').pop()?.toLowerCase()

  if (!extension || !['csv', 'xlsx', 'json'].includes(extension)) {
    throw new Error('Format non supporte. Utilisez CSV, XLSX ou JSON.')
  }

  if (extension === 'json') {
    const text = await file.text()
    const data = JSON.parse(text)

    if (!Array.isArray(data)) {
      throw new Error('Le JSON doit contenir un tableau d objets.')
    }

    return data.map((item, index) => ({ ...item, __row: index + 1 }))
  }

  if (extension === 'csv') {
    const text = await file.text()
    const lines = text
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean)

    if (lines.length < 2) {
      throw new Error('Le CSV est vide ou incomplet.')
    }

    const headers = parseCsvLine(lines[0])
    return lines.slice(1).map((line, index) => {
      const columns = parseCsvLine(line)
      const row = headers.reduce((acc, header, colIndex) => {
        acc[header] = columns[colIndex] ?? ''
        return acc
      }, {})
      row.__row = index + 2
      return row
    })
  }

  // XLSX support via dynamic import to avoid hard dependency at bootstrap time.
  try {
    const xlsx = await import('xlsx')
    const arrayBuffer = await file.arrayBuffer()
    const workbook = xlsx.read(arrayBuffer, { type: 'array' })
    const firstSheetName = workbook.SheetNames[0]
    const firstSheet = workbook.Sheets[firstSheetName]
    const rows = xlsx.utils.sheet_to_json(firstSheet, { defval: '' })
    return rows.map((row, index) => ({ ...row, __row: index + 2 }))
  } catch {
    throw new Error('Lecture XLSX indisponible. Installez la dependance xlsx dans le frontend.')
  }
}

async function validateFile() {
  if (!selectedFile.value) {
    pageError.value = 'Veuillez selectionner un fichier.'
    return
  }

  clearState()
  loading.value = true

  try {
    const rawRows = await parseByExtension(selectedFile.value)

    previewRows.value = rawRows.slice(0, 20)
    validationRows.value = rawRows.map((row) => {
      try {
        const normalized = normalizeStory(row, row.__row || 0)
        const errors = validateStory(normalized)
        return { rowNumber: normalized.rowNumber, status: errors.length ? 'error' : 'ok', errors, story: normalized }
      } catch (error) {
        return {
          rowNumber: row.__row || 0,
          status: 'error',
          errors: [error.message || 'Erreur de parsing.'],
          story: null,
        }
      }
    })
  } catch (error) {
    pageError.value = error.message || 'Erreur de lecture du fichier.'
  } finally {
    loading.value = false
  }
}

function runImport() {
  if (!validationRows.value.length) {
    pageError.value = 'Validez le fichier avant import.'
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
              <th>Type</th>
              <th>En tant que</th>
              <th>Je veux que</th>
              <th>Afin de</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in previewRows" :key="row.__row">
              <td>{{ row.__row }}</td>
              <td>{{ row.type || 'User Story' }}</td>
              <td>{{ row.en_tant_que }}</td>
              <td>{{ row.je_veux_que }}</td>
              <td>{{ row.afin_de }}</td>
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
