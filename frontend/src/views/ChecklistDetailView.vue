<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChecklistsStore } from '@/stores/checklists'
import { useAuthStore } from '@/stores/auth'
import { apiRequest } from '@/lib/api'
import { localizeError, localizeMessage } from '@/lib/localization'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'
import {
  AlertCircle,
  ArrowLeft,
  CheckCircle,
  CheckSquare,
  Clock,
  FileText,
  Grid3x3,
  MessageSquare,
  PlayCircle,
  Square,
  TriangleAlert,
} from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const checklistsStore = useChecklistsStore()
const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()

const checklistId = computed(() => route.params.id)
const projectContextId = computed(() => route.query.projectId || null)
const showItemHistory = ref({})
const historyLoadingByItemId = ref({})
const commentEditorOpenByItemId = ref({})
const commentDraftByItemId = ref({})
const commentBusyByItemId = ref({})
const runModalOpen = ref(false)
const runSubmitBusy = ref(false)
const runModalError = ref('')
const runtimeStateByItemId = ref({})
const pollingItemIds = ref([])
const pollingTimer = ref(null)
const CHECKLIST_RUN_BASE_URL_STORAGE_KEY = 'checklist_item_run_base_url'
const executionContextLoading = ref(false)
const projectContextName = ref('')
const projectContextChecklists = ref([])

const COMMENT_PLACEHOLDER = 'Ajouter une observation, un bug constate ou une information utile pour ce test...'
const STATUS_OPTIONS = ['Not Tested', 'Passed', 'Failed', 'Blocked']
const TECHNICAL_TEXT_PATTERNS = [
  /generee?\s+par\s+l'?agent/i,
  /generateur\s*:/i,
  /fallback/i,
  /decision\s+de\s+reutilisation/i,
  /criteres?\s+d'?acceptation\s+complets?/i,
]

const runForm = reactive({
  itemId: null,
  baseUrl: '',
  environmentName: '',
  notes: '',
  useAuth: true,
  watchMode: true,
  providedInputs: {},
})

const statusIcons = {
  'Not Tested': { icon: Square, color: 'text-slate-500', label: 'Non testé' },
  Passed: { icon: CheckCircle, color: 'text-emerald-600', label: 'Réussi' },
  Failed: { icon: TriangleAlert, color: 'text-rose-600', label: 'Échec' },
  Blocked: { icon: AlertCircle, color: 'text-amber-600', label: 'Bloqué' },
}

const criticalityColors = {
  Low: 'bg-emerald-100 text-emerald-800',
  Medium: 'bg-amber-100 text-amber-800',
  High: 'bg-orange-100 text-orange-800',
  Critical: 'bg-rose-100 text-rose-800',
  Major: 'bg-orange-100 text-orange-800',
  Minor: 'bg-slate-100 text-slate-700',
}

const fallbackItemStatus = { icon: Square, color: 'text-slate-500', label: 'Non testé' }

const checklist = computed(() => checklistsStore.currentChecklist)
const relatedChecklistOptions = computed(() => {
  const unique = new Map()

  for (const item of projectContextChecklists.value) {
    if (item?.id) {
      unique.set(String(item.id), item)
    }
  }

  if (checklist.value?.id && !unique.has(String(checklist.value.id))) {
    unique.set(String(checklist.value.id), checklist.value)
  }

  return Array.from(unique.values())
})

const scenariosStats = computed(() => {
  const items = Array.isArray(checklist.value?.items) ? checklist.value.items : []
  const stats = { total: items.length, passed: 0, failed: 0, blocked: 0, notTested: 0 }

  items.forEach((item) => {
    const status = displayStatus(item.status)
    if (status === 'Passed') stats.passed += 1
    else if (status === 'Failed') stats.failed += 1
    else if (status === 'Blocked') stats.blocked += 1
    else stats.notTested += 1
  })

  return stats
})

const nextRunnableItem = computed(() => {
  const items = Array.isArray(checklist.value?.items) ? checklist.value.items : []
  return items.find((item) => ['Not Tested', 'Failed', 'Blocked'].includes(displayStatus(item.status))) || items[0] || null
})

const currentRunProfile = computed(() => {
  if (!runForm.itemId) return null
  return runtimeStateByItemId.value[runForm.itemId]?.execution_profile || null
})

const publicChecklistDescription = computed(() => splitTechnicalText(checklist.value?.description || '').visible)
const publicAcceptanceCriteria = computed(() => splitTechnicalText(checklist.value?.acceptance_criteria || '').visible)
const acceptanceCriteriaItems = computed(() => {
  const source = publicAcceptanceCriteria.value
  if (!source) return []

  return source
    .split(/\r?\n/)
    .map((entry) => entry.replace(/^[-*\d.)\s]+/, '').trim())
    .filter(Boolean)
})

const checklistGeneratedAt = computed(() => {
  return checklist.value?.generated_at || checklist.value?.created_at || null
})

const checklistReuseScore = computed(() => {
  const candidates = [
    checklist.value?.reuse_score,
    checklist.value?.reuse_summary?.score,
    checklist.value?.score,
  ]
  const resolved = candidates.find((value) => value !== null && value !== undefined && value !== '')
  if (resolved === undefined) return null
  const numeric = Number(resolved)
  return Number.isNaN(numeric) ? String(resolved) : `${numeric} / 100`
})

const checklistSourceLabel = computed(() => {
  if (checklist.value?.source_checklist_name) {
    return `Réutilisée depuis la checklist : ${checklist.value.source_checklist_name}`
  }

  if (projectContextName.value) {
    return `Générée pour le projet : ${projectContextName.value}`
  }

  if (checklist.value?.generated_from) {
    return `Source : ${String(checklist.value.generated_from)}`
  }

  return "Créée dans l'espace d'exécution QA"
})

const checklistStoryDescription = computed(() => {
  const parts = [
    checklist.value?.as_a ? `En tant que ${checklist.value.as_a}` : '',
    checklist.value?.i_want_that ? `je veux ${checklist.value.i_want_that}` : '',
    checklist.value?.so_that ? `afin de ${checklist.value.so_that}` : '',
  ].filter(Boolean)

  if (parts.length) return `${parts.join(', ')}.`
  return publicChecklistDescription.value || ''
})
function displayStatus(status) {
  return ['Passed', 'Failed', 'Blocked'].includes(status) ? status : 'Not Tested'
}

function getItemStatusMeta(status) {
  return statusIcons[displayStatus(status)] || fallbackItemStatus
}

function getItemCriticalityClass(criticality) {
  return criticalityColors[criticality] || 'bg-slate-100 text-slate-700'
}

function getItemPriorityClass(priority) {
  if (priority === 'High') return 'bg-rose-100 text-rose-700'
  if (priority === 'Medium') return 'bg-amber-100 text-amber-700'
  if (priority === 'Low') return 'bg-emerald-100 text-emerald-700'
  return 'bg-slate-100 text-slate-700'
}

function formatChecklistPriority(priority) {
  if (!priority) return 'Non définie'
  const value = String(priority)
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function formatChecklistStatus(status) {
  if (!status) return 'Non défini'
  const labels = {
    backlog: 'Backlog',
    in_progress: 'En cours',
    ready_for_test: 'Prêt pour test',
    completed: 'Terminée',
    pending: 'En attente',
    passed: 'Réussi',
    failed: 'Échec',
    blocked: 'Bloqué',
    draft: 'Brouillon',
    approved: 'Approuvée',
    archived: 'Archivée',
  }
  return labels[String(status)] || String(status).replaceAll('_', ' ')
}

function getChecklistPriorityTagClass(priority) {
  if (priority === 'critical') return 'text-rose-900 border-rose-300 bg-rose-100'
  if (priority === 'high') return 'text-orange-900 border-orange-300 bg-orange-100'
  if (priority === 'medium') return 'text-amber-900 border-amber-300 bg-amber-100'
  if (priority === 'low') return 'text-emerald-900 border-emerald-300 bg-emerald-100'
  return 'text-slate-900 border-slate-300 bg-slate-100'
}

function getScenarioStatusTagClass(status) {
  const display = displayStatus(status)
  if (display === 'Passed') return 'text-emerald-900 border-emerald-300 bg-emerald-100'
  if (display === 'Failed') return 'text-rose-900 border-rose-300 bg-rose-100'
  if (display === 'Blocked') return 'text-amber-900 border-amber-300 bg-amber-100'
  return 'text-slate-900 border-slate-300 bg-slate-100'
}

function executionStateLabel(state) {
  switch (state) {
    case 'queued':
      return 'En file'
    case 'running':
      return 'Exécution en cours'
    case 'passed':
      return 'Exécution Réussie'
    case 'failed':
      return 'Exécution en échec'
    case 'blocked':
      return 'Exécution Bloquée'
    default:
      return 'En attente'
  }
}

function executionStateClass(state) {
  return `run-chip run-chip-${state}`
}

function createRuntimeState(status = 'Not Tested') {
  return {
    execution_state: mapStatusToExecutionState(displayStatus(status)),
    last_run_id: null,
    last_run_status: null,
    last_run_started_at: null,
    last_run_finished_at: null,
    tested_base_url: null,
    last_error_message: null,
    execution_trace: [],
    artifacts: { trace: [], screenshot: [], video: [], all: [], raw_paths: {} },
    execution_profile: null,
    generated_plan: null,
    failure_source: null,
  }
}

function stateForItem(item) {
  return runtimeStateByItemId.value[item.id] || createRuntimeState(item.status)
}

function mapStatusToExecutionState(status) {
  switch (status) {
    case 'Passed':
      return 'passed'
    case 'Failed':
      return 'failed'
    case 'Blocked':
      return 'blocked'
    default:
      return 'idle'
  }
}

function isRunInFlight(item) {
  const state = stateForItem(item).execution_state
  return state === 'queued' || state === 'running'
}

function runButtonLabel(item) {
  const state = stateForItem(item).execution_state
  if (state === 'queued') return 'Mise en file...'
  if (state === 'running') return 'Execution en cours...'
  return 'Lancer le test automatique'
}

function getArtifactUrl(item) {
  return artifactEntries(item).find((entry) => entry.href)?.href || null
}

function normalizeBaseUrl(url) {
  return url.replace(/\/$/, '')
}

function isValidHttpUrl(url) {
  try {
    const parsed = new URL(url)
    return parsed.protocol === 'http:' || parsed.protocol === 'https:'
  } catch {
    return false
  }
}

function readPersistedRunBaseUrl() {
  if (typeof window === 'undefined') return ''
  const persisted = window.localStorage.getItem(CHECKLIST_RUN_BASE_URL_STORAGE_KEY) || ''
  const normalized = normalizeBaseUrl(persisted.trim())
  return isValidHttpUrl(normalized) ? normalized : ''
}

function persistRunBaseUrl(url) {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(CHECKLIST_RUN_BASE_URL_STORAGE_KEY, url)
}

function seedRuntimeStateFromChecklist() {
  const items = Array.isArray(checklist.value?.items) ? checklist.value.items : []
  const next = { ...runtimeStateByItemId.value }

  for (const item of items) {
    if (!next[item.id]) {
      next[item.id] = createRuntimeState(item.status)
    }
  }

  runtimeStateByItemId.value = next
}

async function loadChecklist() {
  try {
    await checklistsStore.fetchChecklist(checklistId.value)
    seedRuntimeStateFromChecklist()
    await preloadItemHistories()
  } catch (error) {
    console.error('Failed to load checklist:', error)
  }
}

async function preloadItemHistories() {
  const items = Array.isArray(checklist.value?.items) ? checklist.value.items : []
  await Promise.allSettled(
    items.map(async (item) => {
      if (Array.isArray(item.history) && item.history.length > 0) return
      await reloadItemHistory(item.id)
    }),
  )
}

async function loadExecutionContext() {
  if (!projectContextId.value) {
    projectContextName.value = ''
    projectContextChecklists.value = []
    return
  }

  try {
    executionContextLoading.value = true
    const [project, stories] = await Promise.all([
      apiRequest(`/projects/${projectContextId.value}`),
      apiRequest(`/projects/${projectContextId.value}/user-stories`),
    ])

    projectContextName.value = project?.name || ''

    const unique = new Map()
    for (const story of Array.isArray(stories) ? stories : []) {
      for (const item of story.checklists || []) {
        if (!unique.has(String(item.id))) {
          unique.set(String(item.id), item)
        }
      }
    }

    projectContextChecklists.value = Array.from(unique.values())
  } catch (error) {
    projectContextName.value = ''
    projectContextChecklists.value = []
    console.error('Failed to load execution context:', error)
  } finally {
    executionContextLoading.value = false
  }
}

async function reloadItemHistory(itemId) {
  historyLoadingByItemId.value = {
    ...historyLoadingByItemId.value,
    [itemId]: true,
  }

  try {
    const history = await checklistsStore.getItemHistory(checklistId.value, itemId)
    const item = checklist.value?.items?.find((entry) => entry.id === itemId)
    if (item) {
      item.history = history
    }
  } catch (error) {
    console.error('Failed to load history:', error)
  } finally {
    historyLoadingByItemId.value = {
      ...historyLoadingByItemId.value,
      [itemId]: false,
    }
  }
}

async function toggleItemHistory(itemId) {
  if (showItemHistory.value[itemId]) {
    showItemHistory.value[itemId] = false
    return
  }

  await reloadItemHistory(itemId)
  showItemHistory.value[itemId] = true
}

async function updateItemStatus(item, newStatus) {
  try {
    await checklistsStore.updateItemStatus(
      checklistId.value,
      item.id,
      newStatus,
      item.qa_comment || null,
    )

    item.status = newStatus
    item.tester = checklist.value?.items?.find((entry) => entry.id === item.id)?.tested_by || item.tester
    runtimeStateByItemId.value = {
      ...runtimeStateByItemId.value,
      [item.id]: {
        ...stateForItem({ id: item.id, status: newStatus }),
        execution_state: mapStatusToExecutionState(newStatus),
      },
    }

    if (showItemHistory.value[item.id]) {
      await reloadItemHistory(item.id)
    }
  } catch (error) {
    toast.error(localizeError(error, 'error_generic', settings.language))
    console.error('Failed to update status:', error)
  }
}

async function refreshExecutionState(itemId) {
  try {
    const data = await checklistsStore.getItemExecution(checklistId.value, itemId)
    runtimeStateByItemId.value = {
      ...runtimeStateByItemId.value,
      [itemId]: {
        execution_state: data.execution_state || 'idle',
        last_run_id: data.last_run_id || null,
        last_run_status: data.last_run_status || null,
        last_run_started_at: data.last_run_started_at || null,
        last_run_finished_at: data.last_run_finished_at || null,
        tested_base_url: data.tested_base_url || null,
        last_error_message: data.last_error_message || null,
        execution_trace: Array.isArray(data.execution_trace) ? data.execution_trace : [],
        artifacts: {
          trace: Array.isArray(data.artifacts?.trace) ? data.artifacts.trace : [],
          screenshot: Array.isArray(data.artifacts?.screenshot) ? data.artifacts.screenshot : [],
          video: Array.isArray(data.artifacts?.video) ? data.artifacts.video : [],
          all: Array.isArray(data.artifacts?.all) ? data.artifacts.all : [],
          raw_paths: data.artifacts?.raw_paths || {},
        },
        execution_profile: data.execution_profile || null,
        generated_plan: data.generated_plan || null,
        failure_source: data.failure_source || null,
      },
    }

    const item = checklist.value?.items?.find((entry) => entry.id === itemId)
    if (item) {
      if (data.status) {
        item.status = data.status
      }
      if (typeof data.qa_comment === 'string') {
        item.qa_comment = data.qa_comment
      }
      if (data.tested_at) {
        item.tested_at = data.tested_at
      }
      if (data.tested_by) {
        item.tester = data.tested_by
      }
    }

    if (!['queued', 'running'].includes(data.execution_state)) {
      removePollingItem(itemId)
      if (showItemHistory.value[itemId]) {
        await reloadItemHistory(itemId)
      }
    }
  } catch {
    // keep polling on transient errors
  }
}

function ensurePollingStarted() {
  if (pollingTimer.value || pollingItemIds.value.length === 0) return
  pollingTimer.value = setInterval(async () => {
    const ids = [...pollingItemIds.value]
    for (const itemId of ids) {
      await refreshExecutionState(itemId)
    }
  }, 3000)
}

function stopPollingIfIdle() {
  if (pollingItemIds.value.length === 0 && pollingTimer.value) {
    clearInterval(pollingTimer.value)
    pollingTimer.value = null
  }
}

function addPollingItem(itemId) {
  if (!pollingItemIds.value.includes(itemId)) {
    pollingItemIds.value = [...pollingItemIds.value, itemId]
  }
  ensurePollingStarted()
}

function removePollingItem(itemId) {
  pollingItemIds.value = pollingItemIds.value.filter((id) => id !== itemId)
  stopPollingIfIdle()
}

function initializeProvidedInputs(profile) {
  const next = {}
  const inputs = Array.isArray(profile?.required_inputs) ? profile.required_inputs : []
  for (const input of inputs) {
    next[input.key] = typeof input.value === 'string' ? input.value : ''
  }
  runForm.providedInputs = next
}

async function openRunModal(item) {
  runForm.itemId = item.id
  await refreshExecutionState(item.id)
  const persistedBaseUrl = readPersistedRunBaseUrl()
  runForm.baseUrl = persistedBaseUrl || 'http://localhost:5173'
  runForm.environmentName = ''
  runForm.notes = ''
  runForm.useAuth = true
  runForm.watchMode = true
  initializeProvidedInputs(runtimeStateByItemId.value[item.id]?.execution_profile || null)
  runModalError.value = ''
  runSubmitBusy.value = false
  runModalOpen.value = true
}

function runNextSuggestedItem() {
  if (nextRunnableItem.value) {
    openRunModal(nextRunnableItem.value)
  }
}

function closeRunModal() {
  if (runSubmitBusy.value) return
  runModalOpen.value = false
}

async function submitRunModal() {
  runModalError.value = ''

  if (!runForm.itemId) {
    runModalError.value = localizeMessage('Aucun cas de test disponible.', settings.language)
    return
  }

  const normalizedBaseUrl = normalizeBaseUrl(runForm.baseUrl.trim())
  if (!isValidHttpUrl(normalizedBaseUrl)) {
    runModalError.value = localizeMessage('Base URL must be a valid http/https URL.', settings.language)
    return
  }

  runSubmitBusy.value = true
  persistRunBaseUrl(normalizedBaseUrl)

  try {
    const response = await checklistsStore.runChecklistItem(checklistId.value, runForm.itemId, {
      base_url: normalizedBaseUrl,
      use_auth: !!runForm.useAuth,
      watch_mode: !!runForm.watchMode,
      environment_name: runForm.environmentName.trim() || null,
      notes: runForm.notes.trim() || null,
      provided_inputs: { ...runForm.providedInputs },
    })

    runtimeStateByItemId.value = {
      ...runtimeStateByItemId.value,
      [runForm.itemId]: {
        ...stateForItem({ id: runForm.itemId, status: 'Not Tested' }),
        execution_state: response.status === 'started' ? 'running' : 'queued',
        last_run_id: response.run_id || null,
        last_run_status: response.status || 'queued',
        last_run_started_at: null,
        last_run_finished_at: null,
        tested_base_url: normalizedBaseUrl,
        last_error_message: null,
        execution_trace: [],
      },
    }

    addPollingItem(runForm.itemId)

    if (showItemHistory.value[runForm.itemId]) {
      await reloadItemHistory(runForm.itemId)
    }

    runModalOpen.value = false
  } catch (error) {
    runModalError.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    runSubmitBusy.value = false
  }
}

function inputTypeForKind(kind) {
  switch (kind) {
    case 'email':
      return 'email'
    case 'password':
      return 'password'
    case 'file':
      return 'text'
    default:
      return 'text'
  }
}

function formatRunStatus(status) {
  switch (status) {
    case 'queued':
      return 'En file'
    case 'started':
    case 'running':
      return 'En cours'
    case 'completed':
      return 'Terminee'
    case 'failed':
      return 'Echec'
    default:
      return 'Inconnue'
  }
}

function selectorLabel(selector) {
  if (!selector) return 'selector inconnu'
  if (selector.by === 'css') return selector.value
  if (selector.by === 'text') return `texte "${selector.text}"`
  if (selector.by === 'label') return `label "${selector.text}"`
  if (selector.by === 'testid') return `testid "${selector.id}"`
  if (selector.by === 'role') return `${selector.role} "${selector.name}"`
  return 'selector inconnu'
}

function summarizePlanStep(step) {
  if (!step) return ''
  switch (step.action) {
    case 'goto':
      return `Ouvrir ${step.url}`
    case 'fill':
      return `Remplir ${selectorLabel(step.selector)}${step.input_key ? ` avec ${step.input_key}` : ''}`
    case 'set_file':
      return `Joindre un fichier dans ${selectorLabel(step.selector)}${step.input_key ? ` via ${step.input_key}` : ''}`
    case 'click':
      return `Cliquer sur ${selectorLabel(step.selector)}`
    case 'press':
      return `Appuyer sur ${step.key} dans ${selectorLabel(step.selector)}`
    case 'wait_for_selector':
      return `Attendre ${selectorLabel(step.selector)} (${step.state})`
    case 'wait_for_url':
      return `Attendre une URL contenant "${step.contains}"`
    case 'screenshot':
      return `Capturer une preuve (${step.name})`
    default:
      return step.action || 'Action'
  }
}

function summarizePlanAssert(assertion) {
  if (!assertion) return ''
  switch (assertion.type) {
    case 'expect_visible':
      return `Verifier que ${selectorLabel(assertion.selector)} est visible`
    case 'expect_hidden':
      return `Verifier que ${selectorLabel(assertion.selector)} est masque`
    case 'expect_text':
      return `Verifier le texte "${assertion.text}" dans ${selectorLabel(assertion.selector)}`
    case 'expect_url_contains':
      return `Verifier que l'URL contient "${assertion.value}"`
    case 'expect_title':
      return `Verifier le titre "${assertion.value}"`
    default:
      return assertion.type || 'Verification'
  }
}

function failureSourceLabel(source) {
  switch (source?.phase) {
    case 'planning':
      return 'Planification'
    case 'preflight':
      return 'Pre-verification'
    case 'step':
      return 'Action'
    case 'assert':
      return 'Verification'
    case 'runtime':
      return 'Execution'
    default:
      return 'Analyse'
  }
}

function artifactEntries(item) {
  const runtime = stateForItem(item)
  const ordered = [
    ...((runtime.artifacts?.trace || []).map((value) => ({ kind: 'Trace', path: value }))),
    ...((runtime.artifacts?.screenshot || []).map((value) => ({ kind: 'Screenshot', path: value }))),
    ...((runtime.artifacts?.video || []).map((value) => ({ kind: 'Video', path: value }))),
  ]

  const deduped = []
  const seen = new Set()
  for (const entry of ordered) {
    if (typeof entry.path !== 'string' || !entry.path.trim() || seen.has(entry.path)) continue
    seen.add(entry.path)
    deduped.push({
      ...entry,
      href: /^https?:\/\//.test(entry.path) ? entry.path : null,
      label: entry.path.split('/').pop() || entry.path,
    })
  }
  return deduped
}

function visibleExecutionInputs(item) {
  const profileInputs = stateForItem(item).execution_profile?.required_inputs
  if (!Array.isArray(profileInputs)) return []
  return profileInputs.filter((input) => typeof input?.value === 'string' && input.value.trim() !== '')
}

function maskExecutionInputValue(input) {
  const value = typeof input?.value === 'string' ? input.value : ''
  if (!value) return 'Non renseigne'
  if (input.kind === 'password' || /password|secret|token|cvv/i.test(input.key || '')) {
    return '••••••••'
  }
  if (input.kind === 'file') {
    return value.split(/[\\/]/).pop() || value
  }
  return value
}

function executionTracePreview(item) {
  const lines = Array.isArray(stateForItem(item).execution_trace) ? stateForItem(item).execution_trace : []
  return lines.slice(-10)
}

function hasExecutionDetails(item) {
  const runtime = stateForItem(item)
  return Boolean(
    runtime.last_run_id ||
    runtime.tested_base_url ||
    runtime.last_error_message ||
    runtime.failure_source ||
    (Array.isArray(runtime.execution_trace) && runtime.execution_trace.length) ||
    artifactEntries(item).length ||
    runtime.generated_plan,
  )
}

function goBack() {
  if (projectContextId.value) {
    router.push({ name: 'project-detail', params: { id: projectContextId.value } })
    return
  }

  router.back()
}

function selectChecklistForExecution(targetChecklistId) {
  if (!targetChecklistId || String(targetChecklistId) === String(checklistId.value)) {
    return
  }

  router.push({
    name: 'checklist-detail',
    params: { id: targetChecklistId },
    query: projectContextId.value ? { projectId: projectContextId.value } : {},
  })
}

function openCommentEditor(item) {
  commentDraftByItemId.value = {
    ...commentDraftByItemId.value,
    [item.id]: item.qa_comment || '',
  }
  commentEditorOpenByItemId.value = {
    ...commentEditorOpenByItemId.value,
    [item.id]: true,
  }
}

function closeCommentEditor(itemId) {
  commentEditorOpenByItemId.value = {
    ...commentEditorOpenByItemId.value,
    [itemId]: false,
  }
}

async function saveItemComment(item) {
  const nextComment = String(commentDraftByItemId.value[item.id] || '').trim()
  if (!nextComment) {
    toast.error('Le commentaire est obligatoire.')
    return
  }

  commentBusyByItemId.value = {
    ...commentBusyByItemId.value,
    [item.id]: true,
  }

  try {
    const response = await checklistsStore.updateItemComment(checklistId.value, item.id, nextComment)
    item.qa_comment = response.comment
    if (response.history) {
      item.history = response.history
    }
    closeCommentEditor(item.id)
    toast.success('Commentaire enregistre avec succes.')
  } catch (error) {
    toast.error(localizeError(error, 'error_generic', settings.language))
  } finally {
    commentBusyByItemId.value = {
      ...commentBusyByItemId.value,
      [item.id]: false,
    }
  }
}

function splitTechnicalText(text) {
  const normalized = String(text || '').trim()
  if (!normalized) {
    return { visible: '', technical: '' }
  }

  const visibleLines = []
  const technicalLines = []

  normalized.split(/\r?\n/).forEach((line) => {
    const trimmed = line.trim()
    if (!trimmed) return

    if (TECHNICAL_TEXT_PATTERNS.some((pattern) => pattern.test(trimmed))) {
      technicalLines.push(trimmed)
    } else {
      visibleLines.push(trimmed)
    }
  })

  return {
    visible: visibleLines.join('\n'),
    technical: technicalLines.join('\n'),
  }
}

function parseItemDescription(description) {
  const raw = String(description || '').trim()
  if (!raw) {
    return { summary: '' }
  }

  return {
    summary: truncateText(raw, 150),
  }
}

function truncateText(text, maxLength = 140) {
  const normalized = String(text || '').replace(/\s+/g, ' ').trim()
  if (normalized.length <= maxLength) return normalized
  return `${normalized.slice(0, maxLength - 1)}...`
}

function itemReference(item) {
  return `TC-${String((item.order ?? 0) + 1).padStart(2, '0')}`
}

function itemStatusLabel(status) {
  return statusIcons[displayStatus(status)]?.label || displayStatus(status)
}

function formatTimestamp(value) {
  if (!value) return ''
  try {
    return new Intl.DateTimeFormat('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(value)).replace(',', ' a')
  } catch {
    return ''
  }
}

function itemLastUpdate(item) {
  const testerName = item.tester?.name || item.tested_by?.name
  const testedAt = formatTimestamp(item.tested_at)

  if (testerName && testedAt) return `${testerName} - ${testedAt}`
  if (testerName) return testerName
  if (testedAt) return testedAt
  return 'Aucune Exécution récente'
}

function historyDetail(change) {
  if (change.change_type === 'comment_added' || change.change_type === 'comment_updated') {
    return change.new_value || ''
  }

  return change.notes || ''
}

function historyActionTitle(change) {
  switch (change.change_type) {
    case 'status_changed':
      return 'Statut modifie'
    case 'comment_added':
      return 'Commentaire ajouté'
    case 'comment_updated':
      return 'Commentaire mis Ã  jour'
    case 'automated_test_started':
      return 'Exécution automatique lancée'
    case 'automated_test_finished':
      return 'Exécution automatique Terminée'
    default:
      return 'Mise a jour'
  }
}

function historyActor(change) {
  return change.changed_by?.name || 'Systeme'
}

function historyStatusPills(change) {
  const pills = []

  if (change.old_value) {
    pills.push({ key: `old-${change.id}`, label: itemStatusLabel(change.old_value), tone: 'muted' })
  }

  if (change.new_value) {
    pills.push({ key: `new-${change.id}`, label: itemStatusLabel(change.new_value), tone: change.change_type === 'status_changed' ? 'active' : 'neutral' })
  }

  return pills
}

function visibleHistory(item) {
  const history = Array.isArray(item.history) ? item.history : []
  return showItemHistory.value[item.id] ? history : history.slice(0, 3)
}

onMounted(() => {
  loadExecutionContext()
  loadChecklist()
})

watch(
  () => route.params.id,
  () => {
    loadChecklist()
  },
)

watch(
  () => route.query.projectId,
  () => {
    loadExecutionContext()
  },
)

onBeforeUnmount(() => {
  if (pollingTimer.value) {
    clearInterval(pollingTimer.value)
    pollingTimer.value = null
  }
})
</script>

<template>
  <section class="page stack">
    <div class="project-execution-hero">
      <div class="project-execution-copy compact">
        <div class="flex items-start gap-3">
          <button @click="goBack" class="story-detail-back compact">
            <ArrowLeft :size="18" />
          </button>
          <div class="min-w-0">
            <p class="project-execution-kicker">Espace d'exécution QA</p>
            <p v-if="projectContextName" class="project-context-label">{{ projectContextName }}</p>
            <h1 class="project-execution-title">{{ checklist?.name || 'Chargement...' }}</h1>
            <p class="project-execution-subtitle compact">
              Exécutez les scénarios, lancez l'agent automatique et suivez les résultats de validation.
            </p>
          </div>
        </div>
      </div>

      <div class="project-execution-side compact">
        <div class="project-execution-app-card compact">
          <span class="project-execution-side-label">Exécution active</span>
          <p class="muted">Le runner, l'orchestrateur et le polling restent actifs pour cette checklist.</p>
        </div>

        <label v-if="projectContextId" class="execution-checklist-selector compact">
          <span class="project-execution-side-label">Checklist en cours</span>
          <select
            :disabled="executionContextLoading || relatedChecklistOptions.length === 0"
            :value="String(checklistId)"
            @change="selectChecklistForExecution($event.target.value)"
          >
            <option
              v-for="option in relatedChecklistOptions"
              :key="option.id"
              :value="String(option.id)"
            >
              {{ option.name || `Checklist #${option.id}` }}
            </option>
          </select>
        </label>

      </div>
    </div>

    <div v-if="checklistsStore.loading" class="flex justify-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border border-brand-500 border-t-transparent"></div>
    </div>

    <div v-else-if="checklist" class="stack">
      <div class="card stack compact-info-card">
        <div class="flex items-center gap-3 pb-3 border-b border-gray-200 mb-4">
          <FileText :size="20" class="text-brand-600" />
          <h2>Informations générales</h2>
        </div>

        <div class="info-stat-grid">
          <div class="info-stat-chip">
            <p class="info-stat-label">Priorité</p>
            <span :class="['tag', getChecklistPriorityTagClass(checklist.priority)]">
              {{ formatChecklistPriority(checklist.priority) }}
            </span>
          </div>

          <div class="info-stat-chip">
            <p class="info-stat-label">Statut</p>
            <span class="tag text-brand-900 border-brand-300 bg-brand-100">
              {{ formatChecklistStatus(checklist.status) }}
            </span>
          </div>

          <div class="info-stat-chip">
            <p class="info-stat-label">scénarios</p>
            <span class="tag text-slate-900 border-slate-300 bg-slate-100">
              {{ scenariosStats.total }} au total
            </span>
          </div>

          <div v-if="checklistReuseScore" class="info-stat-chip">
            <p class="info-stat-label">Score de réutilisation</p>
            <span class="tag text-emerald-900 border-emerald-300 bg-emerald-100">
              {{ checklistReuseScore }}
            </span>
          </div>

          <div v-if="checklistGeneratedAt" class="info-stat-chip">
            <p class="info-stat-label">Date de génération</p>
            <span class="tag text-slate-900 border-slate-300 bg-slate-100">
              {{ formatTimestamp(checklistGeneratedAt) }}
            </span>
          </div>
        </div>

        <div class="info-sections-grid">
          <section class="info-section">
            <p class="info-section-title">Source</p>
            <p class="info-section-content">{{ checklistSourceLabel }}</p>
          </section>

          <section v-if="checklistStoryDescription" class="info-section">
            <p class="info-section-title">Description User Story</p>
            <p class="info-section-content whitespace-pre-wrap">{{ checklistStoryDescription }}</p>
          </section>

          <section v-if="acceptanceCriteriaItems.length" class="info-section">
            <p class="info-section-title">Critères d'acceptation</p>
            <ol class="info-list">
              <li v-for="(criterion, index) in acceptanceCriteriaItems" :key="`${index}-${criterion}`">
                {{ criterion }}
              </li>
            </ol>
          </section>
        </div>
      </div>

      <div v-if="checklist.business_rules && checklist.business_rules.length > 0" class="card stack">
        <div class="flex items-center gap-3 pb-3 border-b border-gray-200 mb-4">
          <Grid3x3 :size="20" class="text-violet-600" />
          <h2>Règles métier</h2>
        </div>

        <ul class="space-y-2">
          <li v-for="(rule, index) in checklist.business_rules" :key="index" class="flex items-start gap-3 text-gray-700">
            <span class="inline-block w-1.5 h-1.5 bg-brand-600 rounded-full mt-2 flex-shrink-0"></span>
            {{ rule }}
          </li>
        </ul>
      </div>

      <div class="card">
        <div class="pb-4 border-b border-gray-200 mb-4">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-3">
              <CheckSquare :size="20" class="text-orange-600" />
              <h2>Test cases exécutables</h2>
              <span class="tag text-brand-900 border-brand-300 bg-brand-100">{{ scenariosStats.total }}</span>
            </div>
            <p class="muted test-case-section-note">Lancez les tests, ajustez le statut final et consultez rapidement l'historique et les observations QA.</p>
          </div>

        </div>

        <div v-if="checklist.items && checklist.items.length > 0" class="space-y-4">
          <article
            v-for="item in checklist.items"
            :key="item.id"
            class="rounded-[24px] border border-slate-200 bg-white shadow-[0_10px_24px_rgba(15,23,42,0.05)] overflow-hidden"
          >
            <div class="p-4 sm:p-5">
              <div class="rounded-[20px] border border-slate-200/90 bg-[linear-gradient(180deg,#ffffff_0%,#f8fbff_100%)] px-4 py-3.5 sm:px-4.5">
                <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:gap-4">
                  <div class="min-w-0 flex-1">
                    <div class="flex min-w-0 flex-wrap items-center gap-2.5">
                      <span :class="executionStateClass(stateForItem(item).execution_state)">{{ executionStateLabel(stateForItem(item).execution_state) }}</span>
                      <span class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.2em] text-slate-500">
                        {{ itemReference(item) }}
                      </span>
                      <h3 class="qa-case-title text-lg font-semibold leading-tight text-slate-950">{{ item.title }}</h3>
                    </div>
                    <p v-if="parseItemDescription(item.description).summary" class="qa-case-description mt-1.5 text-sm leading-5 text-slate-600">
                      {{ parseItemDescription(item.description).summary }}
                    </p>
                  </div>

                  <div class="flex flex-col gap-2.5 xl:min-w-[500px] xl:flex-row xl:items-center xl:justify-end">
                    <span :class="['inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold', getItemCriticalityClass(item.criticality)]">
                      Criticité : {{ item.criticality || 'N/A' }}
                    </span>
                    <label class="qa-card-status-field">
                      <select
                        class="qa-status-select"
                        :disabled="!auth.canTest"
                        :value="displayStatus(item.status)"
                        @change="updateItemStatus(item, $event.target.value)"
                      >
                        <option v-for="status in STATUS_OPTIONS" :key="status" :value="status">
                          {{ statusIcons[status]?.label || status }}
                        </option>
                      </select>
                    </label>
                    <button class="btn btn-primary qa-run-button" :disabled="!auth.canTest || isRunInFlight(item)" @click="openRunModal(item)">
                      <PlayCircle :size="16" />
                      <span>{{ runButtonLabel(item) }}</span>
                    </button>
                  </div>
                </div>
              </div>

              <div class="mt-4 rounded-[20px] border border-slate-200 bg-slate-50/65 overflow-hidden">
                <div class="grid gap-0 lg:grid-cols-[0.45fr_0.55fr]">
                <section class="p-4 sm:p-5 lg:border-r lg:border-slate-200">
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                      <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-100 text-brand-700">
                        <Clock :size="16" />
                      </div>
                      <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Historique</p>
                        <p class="text-sm text-slate-600">Statut, execution automatique et changements recents.</p>
                      </div>
                    </div>
                  </div>

                  <div v-if="historyLoadingByItemId[item.id]" class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-4 text-sm text-slate-500">
                    Chargement de l'historique...
                  </div>

                  <div v-else-if="visibleHistory(item).length" class="mt-4 space-y-3">
                    <div
                      v-for="change in visibleHistory(item)"
                      :key="change.id"
                      class="rounded-2xl border border-slate-200 bg-white p-3.5"
                    >
                      <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                          <p class="text-sm font-semibold text-slate-900">{{ historyActionTitle(change) }}</p>
                          <div v-if="historyStatusPills(change).length" class="mt-2 flex flex-wrap items-center gap-2">
                            <span
                              v-for="pill in historyStatusPills(change)"
                              :key="pill.key"
                              :class="pill.tone === 'active'
                                ? 'inline-flex items-center rounded-full bg-brand-100 px-3 py-1 text-xs font-semibold text-brand-700'
                                : pill.tone === 'neutral'
                                  ? 'inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700'
                                  : 'inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600'"
                            >
                              {{ pill.label }}
                            </span>
                          </div>
                          <p v-if="historyDetail(change)" class="mt-2 whitespace-pre-wrap text-sm leading-5 text-slate-600">{{ historyDetail(change) }}</p>
                        </div>

                        <div class="text-right text-xs text-slate-500">
                          <p class="font-semibold text-slate-700">{{ historyActor(change) }}</p>
                          <p class="mt-1">{{ formatTimestamp(change.created_at) }}</p>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div v-else class="mt-4 rounded-2xl border border-dashed border-slate-200 bg-white px-5 py-6 text-center">
                    <p class="text-sm font-medium text-slate-700">Aucun historique disponible pour ce test case.</p>
                    <p class="mt-2 text-sm text-slate-500">Les changements de statut et d'exécution apparaîtront ici.</p>
                  </div>

                  <button class="btn btn-secondary btn-sm mt-4" @click="toggleItemHistory(item.id)">
                    {{ showItemHistory[item.id] ? 'Réduire l'historique' : 'Afficher tout l'historique' }}
                  </button>
                </section>

                <section class="border-t border-slate-200 p-4 sm:p-5 lg:border-t-0">
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                      <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-100 text-brand-700">
                        <MessageSquare :size="16" />
                      </div>
                      <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Commentaires QA</p>
                        <p class="text-sm text-slate-600">Ajoutez une observation utile sans bloquer l'execution automatique.</p>
                      </div>
                    </div>

                    <button class="btn btn-secondary btn-sm" :disabled="!auth.canTest" @click="openCommentEditor(item)">
                      <MessageSquare :size="14" />
                      <span>{{ item.qa_comment ? 'Modifier le commentaire' : 'Ajouter un commentaire' }}</span>
                    </button>
                  </div>

                  <div v-if="hasExecutionDetails(item)" class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Derniere execution automatique</p>
                        <p class="mt-1 text-sm font-semibold text-slate-900">{{ executionStateLabel(stateForItem(item).execution_state) }}</p>
                      </div>
                      <span v-if="stateForItem(item).last_run_id" class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        Run {{ stateForItem(item).last_run_id }}
                      </span>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                      <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                        <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">URL testee</p>
                        <p class="mt-2 break-all text-sm text-slate-700">{{ stateForItem(item).tested_base_url || 'Non disponible' }}</p>
                      </div>
                      <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                        <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">Statut du run</p>
                        <p class="mt-2 text-sm text-slate-700">{{ formatRunStatus(stateForItem(item).last_run_status) }}</p>
                        <p v-if="stateForItem(item).last_run_started_at" class="mt-1 text-xs text-slate-500">Debut : {{ formatTimestamp(stateForItem(item).last_run_started_at) }}</p>
                        <p v-if="stateForItem(item).last_run_finished_at" class="mt-1 text-xs text-slate-500">Fin : {{ formatTimestamp(stateForItem(item).last_run_finished_at) }}</p>
                      </div>
                    </div>

                    <div v-if="visibleExecutionInputs(item).length">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Champs fournis au run</p>
                      <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <div v-for="input in visibleExecutionInputs(item)" :key="input.key" class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                          <p class="text-xs font-semibold text-slate-700">{{ input.label }}</p>
                          <p class="mt-1 break-all text-sm text-slate-600">{{ maskExecutionInputValue(input) }}</p>
                        </div>
                      </div>
                    </div>

                    <div v-if="stateForItem(item).generated_plan?.steps?.length">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Actions executees par l'agent</p>
                      <ol class="mt-2 space-y-2 text-sm text-slate-700">
                        <li v-for="(step, index) in stateForItem(item).generated_plan.steps" :key="`${item.id}-step-${index}`" class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                          {{ index + 1 }}. {{ summarizePlanStep(step) }}
                        </li>
                      </ol>
                    </div>

                    <div v-if="stateForItem(item).generated_plan?.asserts?.length">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Verifications attendues</p>
                      <ul class="mt-2 space-y-2 text-sm text-slate-700">
                        <li v-for="(assertion, index) in stateForItem(item).generated_plan.asserts" :key="`${item.id}-assert-${index}`" class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                          {{ summarizePlanAssert(assertion) }}
                        </li>
                      </ul>
                    </div>

                    <div v-if="stateForItem(item).failure_source || stateForItem(item).last_error_message" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3">
                      <p class="text-xs font-bold uppercase tracking-wider text-rose-700">Cause probable</p>
                      <p class="mt-2 text-sm font-semibold text-rose-900">
                        {{ failureSourceLabel(stateForItem(item).failure_source) }}
                        <span v-if="stateForItem(item).failure_source?.reference">- {{ stateForItem(item).failure_source.reference }}</span>
                      </p>
                      <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-rose-800">
                        {{ stateForItem(item).last_error_message || stateForItem(item).failure_source?.message }}
                      </p>
                    </div>

                    <div v-if="executionTracePreview(item).length">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Trace d'execution</p>
                      <div class="mt-2 rounded-2xl border border-slate-200 bg-slate-950 px-3 py-3 text-xs text-slate-100">
                        <p v-for="(line, index) in executionTracePreview(item)" :key="`${item.id}-trace-${index}`" class="break-words leading-5">
                          {{ line }}
                        </p>
                      </div>
                    </div>

                    <div v-if="artifactEntries(item).length">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Artefacts disponibles</p>
                      <div class="mt-2 space-y-2">
                        <div v-for="entry in artifactEntries(item)" :key="`${item.id}-${entry.kind}-${entry.path}`" class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                          <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ entry.kind }}</p>
                          <a
                            v-if="entry.href"
                            :href="entry.href"
                            target="_blank"
                            rel="noreferrer"
                            class="mt-1 inline-flex break-all text-sm font-semibold text-brand-700 hover:text-brand-800"
                          >
                            {{ entry.label }}
                          </a>
                          <p v-else class="mt-1 break-all font-mono text-xs text-slate-700">{{ entry.path }}</p>
                        </div>
                      </div>
                      <p v-if="!getArtifactUrl(item)" class="text-xs text-slate-500">
                        Les artefacts sont stockes dans le workspace du runner. Aucun lien HTTP securise n'est expose pour ce run.
                      </p>
                    </div>
                  </div>

                  <div v-if="commentEditorOpenByItemId[item.id]" class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 space-y-3">
                    <textarea
                      v-model="commentDraftByItemId[item.id]"
                      rows="4"
                      class="w-full"
                      :placeholder="COMMENT_PLACEHOLDER"
                      :disabled="commentBusyByItemId[item.id]"
                    />
                    <div class="flex flex-wrap gap-2">
                      <button class="btn btn-primary btn-sm" :disabled="commentBusyByItemId[item.id]" @click="saveItemComment(item)">
                        {{ commentBusyByItemId[item.id] ? 'Enregistrement...' : 'Enregistrer' }}
                      </button>
                      <button class="btn btn-secondary btn-sm" :disabled="commentBusyByItemId[item.id]" @click="closeCommentEditor(item.id)">
                        Annuler
                      </button>
                    </div>
                  </div>

                  <div v-else-if="item.qa_comment" class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                      <p class="text-sm font-semibold text-slate-900">{{ item.tester?.name || item.tested_by?.name || 'Observation QA' }}</p>
                      <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        {{ item.tested_at ? formatTimestamp(item.tested_at) : 'Commentaire enregistré' }}
                      </span>
                    </div>
                    <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ item.qa_comment }}</p>
                  </div>

                  <div v-else class="mt-4 rounded-2xl border border-dashed border-slate-200 bg-white px-5 py-6 text-center">
                    <p class="text-sm font-semibold text-slate-700">Aucun commentaire enregistré pour ce test case.</p>
                    <p class="mt-2 text-sm text-slate-500">Soyez le premier Ã  ajouter une observation.</p>
                  </div>

                  <div v-if="getArtifactUrl(item)" class="mt-4 rounded-2xl border border-brand-200 bg-brand-50/80 p-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-brand-700">Artefacts d execution</p>
                    <a
                      :href="getArtifactUrl(item)"
                      target="_blank"
                      rel="noreferrer"
                      class="mt-2 inline-flex items-center text-sm font-semibold text-brand-700 hover:text-brand-800"
                    >
                      Consulter la derniere preuve d execution
                    </a>
                  </div>
                </section>
                </div>
              </div>
            </div>
          </article>
        </div>

        <div v-else class="text-center py-12">
          <AlertCircle :size="32" class="mx-auto text-slate-400 mb-3" />
          <p class="text-slate-600 muted">Aucun scenario n'a encore ete ajoute.</p>
        </div>
      </div>
    </div>

    <div v-else class="card text-center py-12">
      <p class="muted">Checklist introuvable.</p>
    </div>

    <div v-if="runModalOpen" class="run-modal-overlay">
      <div class="card stack run-modal-card">
        <div class="section-header section-header-tight">
          <h3>Lancer un item de checklist</h3>
        </div>

        <p class="muted modal-intro">Renseignez les informations de l'environnement cible pour cette execution.</p>
        <p v-if="runModalError" class="error">{{ runModalError }}</p>

        <form class="stack" @submit.prevent="submitRunModal">
          <div class="field">
            <label>URL de base</label>
            <input v-model="runForm.baseUrl" placeholder="https://example.com" :disabled="runSubmitBusy" required />
          </div>

          <div v-if="currentRunProfile" class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-4">
            <div>
              <p class="text-sm font-bold uppercase tracking-wider text-slate-700">Plan generated by the agent</p>
              <p class="text-sm text-slate-700">{{ currentRunProfile.intent_summary }}</p>
            </div>

            <div v-if="currentRunProfile.preconditions?.length">
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Preconditions</p>
              <ul class="mt-2 space-y-1 text-sm text-slate-700">
                <li v-for="precondition in currentRunProfile.preconditions" :key="precondition">- {{ precondition }}</li>
              </ul>
            </div>

            <div v-if="currentRunProfile.required_inputs?.length" class="space-y-3">
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Required inputs</p>
              <div v-for="input in currentRunProfile.required_inputs" :key="input.key" class="field">
                <label>{{ input.label }}<span v-if="input.required"> *</span></label>
                <textarea
                  v-if="input.kind === 'textarea'"
                  v-model="runForm.providedInputs[input.key]"
                  rows="3"
                  :disabled="runSubmitBusy"
                  :placeholder="input.description || input.label"
                />
                <input
                  v-else
                  v-model="runForm.providedInputs[input.key]"
                  :type="inputTypeForKind(input.kind)"
                  :disabled="runSubmitBusy"
                  :placeholder="input.kind === 'file' ? 'C:\\path\\to\\file.ext' : (input.description || input.label)"
                />
                <p v-if="input.description" class="muted text-xs mt-1">{{ input.description }}</p>
              </div>
            </div>

            <div v-if="currentRunProfile.last_generated_plan?.steps?.length">
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Actions prevues</p>
              <ol class="mt-2 space-y-2 text-sm text-slate-700">
                <li v-for="(step, index) in currentRunProfile.last_generated_plan.steps" :key="`modal-step-${index}`" class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                  {{ index + 1 }}. {{ summarizePlanStep(step) }}
                </li>
              </ol>
            </div>

            <div v-if="currentRunProfile.last_generated_plan?.asserts?.length">
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Verifications prevues</p>
              <ul class="mt-2 space-y-2 text-sm text-slate-700">
                <li v-for="(assertion, index) in currentRunProfile.last_generated_plan.asserts" :key="`modal-assert-${index}`" class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                  {{ summarizePlanAssert(assertion) }}
                </li>
              </ul>
            </div>
          </div>

          <div class="grid">
            <div class="field">
              <label>Nom de l'environnement</label>
              <input v-model="runForm.environmentName" placeholder="staging" :disabled="runSubmitBusy" />
            </div>

            <div class="field field-end-controls">
              <label class="checkbox-label">
                <input type="checkbox" v-model="runForm.useAuth" :disabled="runSubmitBusy" />
                <span>Utiliser un parcours authentifie</span>
              </label>
              <p class="muted checkbox-help">Decochez cette option pour executer le test sans authentification.</p>

              <label class="checkbox-label checkbox-label-spaced">
                <input type="checkbox" v-model="runForm.watchMode" :disabled="runSubmitBusy" />
                <span>Mode observe</span>
              </label>
              <p class="muted checkbox-help">Laissez active pour suivre l'execution dans un navigateur externe.</p>
            </div>
          </div>

          <div class="field">
            <label>Notes ou contraintes</label>
            <textarea v-model="runForm.notes" rows="3" :disabled="runSubmitBusy" />
          </div>

          <div class="actions">
            <button class="btn btn-primary" type="submit" :disabled="runSubmitBusy">
              {{ runSubmitBusy ? 'Mise en file...' : 'Lancer maintenant' }}
            </button>
            <button class="btn btn-secondary" type="button" :disabled="runSubmitBusy" @click="closeRunModal">
              Annuler
            </button>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>

<style>
.compact-info-card {
  gap: 1rem;
}

.info-stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 0.75rem;
}

.info-stat-chip {
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 1rem;
  background: #f8fafc;
  padding: 0.85rem 0.95rem;
}

.info-stat-label {
  margin-bottom: 0.45rem;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #64748b;
}

.info-sections-grid {
  display: grid;
  gap: 0.9rem;
}

.info-section {
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 1rem;
  background: #ffffff;
  padding: 0.95rem 1rem;
}

.info-section-title {
  margin-bottom: 0.4rem;
  font-size: 0.76rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #64748b;
}

.info-section-content {
  font-size: 0.95rem;
  line-height: 1.55;
  color: #334155;
}

.info-list {
  margin: 0;
  padding-left: 1.15rem;
  color: #334155;
}

.info-list li + li {
  margin-top: 0.45rem;
}

.qa-card-status-field {
  min-width: min(100%, 180px);
}

.qa-status-select {
  width: 100%;
  border: 1px solid rgba(148, 163, 184, 0.45);
  border-radius: 999px;
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  color: #0f172a;
  padding: 0.68rem 2.4rem 0.68rem 0.95rem;
  font-size: 0.88rem;
  font-weight: 600;
  line-height: 1.2;
  min-height: 2.7rem;
}

.qa-status-select:focus {
  outline: none;
  border-color: rgba(37, 99, 235, 0.5);
  box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.14);
}

.qa-case-title {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.qa-case-description {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.qa-run-button {
  min-height: 2.7rem;
  justify-content: center;
  padding-inline: 1rem;
  min-width: 220px;
  white-space: nowrap;
}

.run-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  background: rgba(15, 23, 42, 0.38);
  backdrop-filter: blur(6px);
}

.run-modal-card {
  width: min(100%, 780px);
  max-height: calc(100vh - 3rem);
  overflow: auto;
}

.project-execution-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(300px, 0.78fr);
  gap: 1rem;
  align-items: start;
}

.project-execution-copy {
  min-width: 0;
}

.project-execution-copy.compact {
  padding: 0.1rem 0;
}

.project-execution-title {
  display: -webkit-box;
  margin-top: 0.15rem;
  max-width: 26ch;
  font-size: clamp(1.25rem, 2vw, 1.7rem);
  font-weight: 700;
  line-height: 1.1;
  color: #0f172a;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.project-execution-side {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.9rem;
  min-width: 0;
}

.project-execution-side.compact {
  gap: 0.65rem;
}

.project-execution-app-card {
  width: 100%;
}

.project-execution-app-card.compact {
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 1rem;
  background: #ffffff;
  padding: 0.9rem 1rem;
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
}

.project-execution-app-card .muted {
  margin-top: 0.3rem;
  line-height: 1.45;
}

.project-context-label {
  margin: 0 0 0.35rem;
  font-size: 0.78rem;
  font-weight: 700;
  color: #475569;
}

.project-execution-kicker {
  margin: 0 0 0.25rem;
  font-size: 0.74rem;
  font-weight: 800;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: #2563eb;
}

.project-execution-subtitle.compact {
  margin-top: 0.45rem;
  max-width: 62ch;
  font-size: 0.95rem;
  line-height: 1.5;
  color: #475569;
}

.execution-checklist-selector {
  display: grid;
  gap: 0.45rem;
  margin-bottom: 0.9rem;
}

.execution-checklist-selector.compact {
  margin-bottom: 0;
}

.execution-checklist-selector select {
  width: 100%;
  border: 1px solid rgba(148, 163, 184, 0.3);
  border-radius: 0.85rem;
  background: #ffffff;
  color: #0f172a;
  padding: 0.7rem 0.85rem;
}

.test-case-section-note {
  max-width: 58ch;
  text-align: left;
}

@media (max-width: 1100px) {
  .project-execution-hero {
    grid-template-columns: 1fr;
  }

  .project-execution-title {
    max-width: none;
  }
}

@media (max-width: 768px) {
  .qa-card-status-field {
    min-width: 100%;
  }

  .qa-run-button {
    width: 100%;
    min-width: 0;
  }

  .info-stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
