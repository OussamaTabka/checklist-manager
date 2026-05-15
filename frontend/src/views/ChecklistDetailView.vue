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
  BookOpen,
  CheckCircle,
  CheckSquare,
  ChevronDown,
  ChevronUp,
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
const expandedItems = ref({})
const showItemHistory = ref({})
const historyLoadingByItemId = ref({})
const commentEditorOpenByItemId = ref({})
const commentDraftByItemId = ref({})
const commentBusyByItemId = ref({})
const technicalDetailsOpen = ref(false)
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
  'Not Tested': { icon: Square, color: 'text-slate-500', label: 'Non teste' },
  Passed: { icon: CheckCircle, color: 'text-emerald-600', label: 'Reussi' },
  Failed: { icon: TriangleAlert, color: 'text-rose-600', label: 'Echoue' },
  Blocked: { icon: AlertCircle, color: 'text-amber-600', label: 'Bloque' },
}

const criticalityColors = {
  Low: 'bg-emerald-100 text-emerald-800',
  Medium: 'bg-amber-100 text-amber-800',
  High: 'bg-orange-100 text-orange-800',
  Critical: 'bg-rose-100 text-rose-800',
  Major: 'bg-orange-100 text-orange-800',
  Minor: 'bg-slate-100 text-slate-700',
}

const fallbackItemStatus = { icon: Square, color: 'text-slate-500', label: 'Non teste' }

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
const technicalDetailsSections = computed(() => {
  const sections = []
  const description = splitTechnicalText(checklist.value?.description || '')
  const acceptanceCriteria = splitTechnicalText(checklist.value?.acceptance_criteria || '')

  if (description.technical) {
    sections.push({ title: 'Description technique', content: description.technical })
  }

  if (acceptanceCriteria.technical) {
    sections.push({ title: 'Criteres techniques', content: acceptanceCriteria.technical })
  }

  return sections
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
  if (!priority) return 'Non definie'
  const value = String(priority)
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function formatChecklistStatus(status) {
  if (!status) return 'Non defini'
  const labels = {
    backlog: 'Backlog',
    in_progress: 'En cours',
    ready_for_test: 'Pret pour test',
    completed: 'Terminee',
    pending: 'En attente',
    passed: 'Reussi',
    failed: 'Echoue',
    blocked: 'Bloque',
    draft: 'Brouillon',
    approved: 'Approuvee',
    archived: 'Archivee',
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
      return 'Execution en cours'
    case 'passed':
      return 'Execution reussie'
    case 'failed':
      return 'Execution echouee'
    case 'blocked':
      return 'Execution bloquee'
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
    last_error_message: null,
    execution_trace: [],
    artifacts: { trace: [], screenshot: [], video: [] },
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
  const runtime = stateForItem(item)
  const candidates = [
    ...(Array.isArray(runtime.artifacts?.trace) ? runtime.artifacts.trace : []),
    ...(Array.isArray(runtime.artifacts?.screenshot) ? runtime.artifacts.screenshot : []),
    ...(Array.isArray(runtime.artifacts?.video) ? runtime.artifacts.video : []),
  ]
  return candidates.find((value) => typeof value === 'string' && /^https?:\/\//.test(value)) || null
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
  } catch (error) {
    console.error('Failed to load checklist:', error)
  }
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
        last_error_message: data.last_error_message || null,
        execution_trace: Array.isArray(data.execution_trace) ? data.execution_trace : [],
        artifacts: {
          trace: Array.isArray(data.artifacts?.trace) ? data.artifacts.trace : [],
          screenshot: Array.isArray(data.artifacts?.screenshot) ? data.artifacts.screenshot : [],
          video: Array.isArray(data.artifacts?.video) ? data.artifacts.video : [],
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
    default:
      return 'text'
  }
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

function toggleItem(itemId) {
  expandedItems.value[itemId] = !expandedItems.value[itemId]
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
    return { summary: '', details: '', expected: '' }
  }

  const match = raw.match(/(?:Resultat attendu|Resultat attendu|Expected result)\s*:\s*([\s\S]+)/i)
  const details = match ? raw.slice(0, match.index).trim() : raw
  const expected = match ? match[1].trim() : ''
  const summarySource = details || raw

  return {
    summary: truncateText(summarySource, 150),
    details,
    expected,
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
    return new Date(value).toLocaleString()
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
  return 'Aucune execution recente'
}

function itemExpectedObservations(item) {
  const profile = stateForItem(item).execution_profile
  const expectedFromProfile = Array.isArray(profile?.expected_observations) ? profile.expected_observations : []
  if (expectedFromProfile.length) {
    return expectedFromProfile
  }

  const expectedText = parseItemDescription(item.description).expected
  return expectedText ? [expectedText] : []
}

function historySummary(change) {
  const actor = change.changed_by?.name || 'Utilisateur inconnu'
  const oldStatus = itemStatusLabel(change.old_value)
  const newStatus = itemStatusLabel(change.new_value)

  switch (change.change_type) {
    case 'status_changed':
      return `${actor} a change le statut de ${oldStatus} a ${newStatus}.`
    case 'comment_added':
      return `${actor} a ajoute un commentaire.`
    case 'comment_updated':
      return `${actor} a modifie le commentaire.`
    case 'automated_test_started':
      return `${actor} a lance le test automatique.`
    case 'automated_test_finished':
      return `${actor} a termine le test automatique. Resultat : ${newStatus}.`
    default:
      if (change.old_value || change.new_value) {
        return `${actor} a mis a jour ${change.field_name} de ${change.old_value || '-'} a ${change.new_value || '-'}.`
      }
      return `${actor} a mis a jour ${change.field_name}.`
  }
}

function historyDetail(change) {
  if (change.change_type === 'comment_added' || change.change_type === 'comment_updated') {
    return change.new_value || ''
  }

  return change.notes || ''
}

function statusButtonClass(item, status) {
  return displayStatus(item.status) === status ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm'
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
      <div class="project-execution-copy">
        <div class="flex items-center gap-4">
          <button @click="goBack" class="story-detail-back">
            <ArrowLeft :size="20" />
          </button>
          <div>
            <p class="project-execution-kicker">Espace d'execution QA</p>
            <p v-if="projectContextName" class="project-context-label">{{ projectContextName }}</p>
            <h1>{{ checklist?.name || 'Chargement...' }}</h1>
            <p class="project-execution-subtitle">
              Executez chaque test case, lancez l'agent automatique, ajustez le statut final et gardez une trace claire de chaque changement.
            </p>
          </div>
        </div>
      </div>

      <div class="project-execution-side">
        <div class="project-execution-app-card">
          <span class="project-execution-side-label">Execution</span>
          <p class="muted">Le runner, l'orchestrateur et le polling existants restent actifs. Cette vue se concentre uniquement sur le travail du testeur.</p>
        </div>

        <label v-if="projectContextId" class="execution-checklist-selector">
          <span class="project-execution-side-label">Selectionner la checklist a tester</span>
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

        <div class="project-execution-hero-actions">
          <button class="btn btn-secondary" :disabled="!auth.canTest || !nextRunnableItem" @click="runNextSuggestedItem">
            <PlayCircle :size="16" />
            <span>Tester le prochain scenario</span>
          </button>
        </div>
      </div>
    </div>

    <div v-if="checklistsStore.loading" class="flex justify-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border border-blue-500 border-t-transparent"></div>
    </div>

    <div v-else-if="checklist" class="stack">
      <div class="story-detail-metrics">
        <article class="story-detail-metric">
          <span>Total</span>
          <strong>{{ scenariosStats.total }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Reussis</span>
          <strong>{{ scenariosStats.passed }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Echoues</span>
          <strong>{{ scenariosStats.failed }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Bloques</span>
          <strong>{{ scenariosStats.blocked }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Non testes</span>
          <strong>{{ scenariosStats.notTested }}</strong>
        </article>
      </div>

      <div class="card stack">
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200 mb-4">
          <FileText :size="20" class="text-blue-600" />
          <h2>Informations generales</h2>
        </div>

        <div class="grid grid-cols-[1fr_1fr_1fr] gap-4">
          <div>
            <p class="muted mb-1">Priorite</p>
            <span :class="['tag', getChecklistPriorityTagClass(checklist.priority)]">
              {{ formatChecklistPriority(checklist.priority) }}
            </span>
          </div>

          <div>
            <p class="muted mb-1">Statut</p>
            <span class="tag text-blue-900 border-blue-300 bg-blue-100">
              {{ formatChecklistStatus(checklist.status) }}
            </span>
          </div>

          <div>
            <p class="muted mb-1">Scenarios</p>
            <span class="tag text-slate-900 border-slate-300 bg-slate-100">
              {{ scenariosStats.total }} au total
            </span>
          </div>
        </div>

        <div v-if="publicChecklistDescription" class="mt-4">
          <p class="muted mb-2">Description</p>
          <p class="text-gray-700 whitespace-pre-wrap">{{ publicChecklistDescription }}</p>
        </div>

        <div v-if="technicalDetailsSections.length" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
          <button class="w-full flex items-center justify-between text-left" @click="technicalDetailsOpen = !technicalDetailsOpen">
            <span class="font-semibold text-slate-900">Details techniques</span>
            <component :is="technicalDetailsOpen ? ChevronUp : ChevronDown" :size="18" class="text-slate-500" />
          </button>

          <div v-if="technicalDetailsOpen" class="mt-4 space-y-3">
            <div v-for="section in technicalDetailsSections" :key="section.title">
              <p class="text-xs font-bold uppercase tracking-wider text-slate-600">{{ section.title }}</p>
              <pre class="mt-2 whitespace-pre-wrap rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700">{{ section.content }}</pre>
            </div>
          </div>
        </div>
      </div>

      <div v-if="checklist.as_a || checklist.i_want_that || checklist.so_that || publicAcceptanceCriteria" class="card stack">
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200 mb-4">
          <BookOpen :size="20" class="text-emerald-600" />
          <h2>User story associee</h2>
        </div>

        <div class="space-y-3">
          <div v-if="checklist.as_a">
            <p class="muted mb-1">En tant que</p>
            <p class="text-gray-700">{{ checklist.as_a }}</p>
          </div>
          <div v-if="checklist.i_want_that">
            <p class="muted mb-1">Je veux</p>
            <p class="text-gray-700 whitespace-pre-wrap">{{ checklist.i_want_that }}</p>
          </div>
          <div v-if="checklist.so_that">
            <p class="muted mb-1">Afin de</p>
            <p class="text-gray-700 whitespace-pre-wrap">{{ checklist.so_that }}</p>
          </div>
          <div v-if="publicAcceptanceCriteria" class="mt-4 pt-4 border-t border-gray-200">
            <p class="muted mb-2">Criteres d'acceptation</p>
            <div class="bg-gray-50 border border-gray-200 p-3 rounded whitespace-pre-wrap text-sm text-gray-700">
              {{ publicAcceptanceCriteria }}
            </div>
          </div>
        </div>
      </div>

      <div v-if="checklist.business_rules && checklist.business_rules.length > 0" class="card stack">
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200 mb-4">
          <Grid3x3 :size="20" class="text-violet-600" />
          <h2>Regles metier</h2>
        </div>

        <ul class="space-y-2">
          <li v-for="(rule, index) in checklist.business_rules" :key="index" class="flex items-start gap-3 text-gray-700">
            <span class="inline-block w-1.5 h-1.5 bg-blue-600 rounded-full mt-2 flex-shrink-0"></span>
            {{ rule }}
          </li>
        </ul>
      </div>

      <div class="card">
        <div class="pb-6 border-b border-gray-200 mb-6">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div class="flex items-center gap-3">
              <CheckSquare :size="20" class="text-orange-600" />
              <h2>Test cases executables</h2>
              <span class="tag text-blue-900 border-blue-300 bg-blue-100">{{ scenariosStats.total }}</span>
            </div>
            <p class="muted">Chaque card garde l'execution automatique, le statut manuel, le commentaire QA et l'historique.</p>
          </div>

          <div class="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-3 text-sm">
            <div class="bg-white border border-gray-200 rounded p-2 text-center">
              <p class="muted text-xs mb-1">Total</p>
              <p class="font-bold text-slate-900">{{ scenariosStats.total }}</p>
            </div>
            <div class="bg-emerald-50 border border-emerald-200 rounded p-2 text-center">
              <p class="text-emerald-700 text-xs font-medium mb-1">Reussis</p>
              <p class="font-bold text-emerald-900">{{ scenariosStats.passed }}</p>
            </div>
            <div class="bg-rose-50 border border-rose-200 rounded p-2 text-center">
              <p class="text-rose-700 text-xs font-medium mb-1">Echoues</p>
              <p class="font-bold text-rose-900">{{ scenariosStats.failed }}</p>
            </div>
            <div class="bg-amber-50 border border-amber-200 rounded p-2 text-center">
              <p class="text-amber-700 text-xs font-medium mb-1">Bloques</p>
              <p class="font-bold text-amber-900">{{ scenariosStats.blocked }}</p>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded p-2 text-center">
              <p class="muted text-xs mb-1">Non testes</p>
              <p class="font-bold text-slate-900">{{ scenariosStats.notTested }}</p>
            </div>
          </div>
        </div>

        <div v-if="checklist.items && checklist.items.length > 0" class="space-y-4">
          <article
            v-for="item in checklist.items"
            :key="item.id"
            class="rounded-2xl border border-slate-200 bg-slate-50/70 overflow-hidden"
          >
            <div class="p-5">
              <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-2 mb-3">
                    <span class="item-case-badge">{{ itemReference(item) }}</span>
                    <span :class="['tag text-xs', getItemCriticalityClass(item.criticality)]">{{ item.criticality || 'N/A' }}</span>
                    <span :class="['tag text-xs', getItemPriorityClass(item.priority)]">{{ item.priority || 'N/A' }}</span>
                    <span :class="['tag text-xs', getScenarioStatusTagClass(item.status)]">{{ itemStatusLabel(item.status) }}</span>
                    <span :class="executionStateClass(stateForItem(item).execution_state)">{{ executionStateLabel(stateForItem(item).execution_state) }}</span>
                  </div>

                  <h3 class="text-lg font-semibold text-slate-950">{{ item.title }}</h3>
                  <p v-if="parseItemDescription(item.description).summary" class="mt-2 text-sm text-slate-600">
                    {{ parseItemDescription(item.description).summary }}
                  </p>

                  <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="rounded-xl border border-white/80 bg-white p-3">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Dernier commentaire</p>
                      <p class="mt-1 text-sm text-slate-700">
                        {{ item.qa_comment || 'Aucun commentaire pour le moment.' }}
                      </p>
                    </div>
                    <div class="rounded-xl border border-white/80 bg-white p-3">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Derniere mise a jour</p>
                      <p class="mt-1 text-sm text-slate-700">{{ itemLastUpdate(item) }}</p>
                    </div>
                  </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                  <button class="btn btn-primary btn-sm" :disabled="!auth.canTest || isRunInFlight(item)" @click="openRunModal(item)">
                    <PlayCircle :size="14" />
                    <span>{{ runButtonLabel(item) }}</span>
                  </button>
                  <button class="btn btn-secondary btn-sm" @click="toggleItem(item.id)">
                    <span>{{ expandedItems[item.id] ? 'Masquer les details' : 'Details' }}</span>
                    <component :is="expandedItems[item.id] ? ChevronUp : ChevronDown" :size="14" />
                  </button>
                </div>
              </div>
            </div>

            <div v-if="expandedItems[item.id]" class="border-t border-slate-200 bg-white px-5 py-5 space-y-5">
              <div class="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                <div class="space-y-4">
                  <section class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Description complete</p>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-slate-700">
                      {{ parseItemDescription(item.description).details || item.description || 'Aucune description detaillee.' }}
                    </p>
                  </section>

                  <section v-if="itemExpectedObservations(item).length" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-emerald-700">Resultat attendu</p>
                    <ul class="mt-2 space-y-2 text-sm text-emerald-900">
                      <li v-for="(observation, index) in itemExpectedObservations(item)" :key="`${item.id}-expected-${index}`">
                        {{ observation }}
                      </li>
                    </ul>
                  </section>

                  <section class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Execution automatique</p>
                        <p class="mt-1 text-sm text-slate-600">Le testeur peut toujours corriger manuellement le statut apres le retour de l'agent.</p>
                      </div>
                      <div class="flex flex-wrap items-center gap-2">
                        <button class="btn btn-primary btn-sm" :disabled="!auth.canTest || isRunInFlight(item)" @click="openRunModal(item)">
                          <PlayCircle :size="14" />
                          <span>{{ runButtonLabel(item) }}</span>
                        </button>
                        <a
                          v-if="getArtifactUrl(item)"
                          :href="getArtifactUrl(item)"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="btn btn-secondary btn-sm"
                        >
                          Consulter les artefacts
                        </a>
                      </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                      <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Etat agent</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                          <span :class="executionStateClass(stateForItem(item).execution_state)">{{ executionStateLabel(stateForItem(item).execution_state) }}</span>
                          <span v-if="stateForItem(item).last_run_id" class="text-xs text-slate-500">Run {{ stateForItem(item).last_run_id }}</span>
                        </div>
                      </div>
                      <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Statut final actuel</p>
                        <p class="mt-2 text-sm font-semibold text-slate-900">{{ itemStatusLabel(item.status) }}</p>
                      </div>
                    </div>

                    <p v-if="stateForItem(item).last_error_message" class="error mt-4">
                      {{ stateForItem(item).last_error_message }}
                    </p>

                    <details v-if="stateForItem(item).execution_trace?.length" class="trace-details mt-4">
                      <summary class="muted trace-summary">Trace d'execution ({{ stateForItem(item).execution_trace.length }})</summary>
                      <ol class="trace-list">
                        <li v-for="(line, index) in stateForItem(item).execution_trace" :key="`${item.id}-trace-${index}`" class="trace-item">
                          {{ line }}
                        </li>
                      </ol>
                    </details>

                    <div v-if="stateForItem(item).failure_source" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                      <strong>Failure source:</strong> {{ stateForItem(item).failure_source.phase }} / {{ stateForItem(item).failure_source.reference }}<br />
                      {{ stateForItem(item).failure_source.message }}
                    </div>
                  </section>
                </div>

                <div class="space-y-4">
                  <section class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Mettre a jour le statut</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                      <button
                        v-for="status in STATUS_OPTIONS"
                        :key="status"
                        :class="statusButtonClass(item, status)"
                        :disabled="!auth.canTest"
                        @click="updateItemStatus(item, status)"
                      >
                        {{ statusIcons[status]?.label || status }}
                      </button>
                    </div>

                    <p v-if="item.tested_at" class="mt-3 text-xs text-slate-500">
                      <Clock :size="14" class="inline mr-1" />
                      Dernier test : {{ formatTimestamp(item.tested_at) }}
                    </p>
                  </section>

                  <section class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between gap-3">
                      <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Commentaire QA</p>
                        <p class="mt-1 text-sm text-slate-600">Ajoutez une observation utile sans bloquer l'execution automatique.</p>
                      </div>
                      <button class="btn btn-secondary btn-sm" :disabled="!auth.canTest" @click="openCommentEditor(item)">
                        <MessageSquare :size="14" />
                        <span>{{ item.qa_comment ? 'Modifier le commentaire' : 'Ajouter un commentaire' }}</span>
                      </button>
                    </div>

                    <div v-if="commentEditorOpenByItemId[item.id]" class="mt-4 space-y-3">
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

                    <div v-else class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                      {{ item.qa_comment || 'Aucun commentaire enregistre pour ce test case.' }}
                    </div>
                  </section>
                </div>
              </div>

              <section class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                  <div class="flex items-center gap-2">
                    <Clock :size="16" class="text-blue-600" />
                    <div>
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Historique</p>
                      <p class="text-sm text-slate-600">Statut, commentaire et execution automatique par test case.</p>
                    </div>
                  </div>

                  <button class="btn btn-secondary btn-sm" @click="toggleItemHistory(item.id)">
                    {{ showItemHistory[item.id] ? 'Masquer l historique' : 'Afficher l historique' }}
                  </button>
                </div>

                <div v-if="historyLoadingByItemId[item.id]" class="mt-4 text-sm text-slate-500">
                  Chargement de l'historique...
                </div>

                <div v-else-if="showItemHistory[item.id]" class="mt-4 space-y-3">
                  <div v-if="item.history?.length" class="space-y-3">
                    <div
                      v-for="change in item.history"
                      :key="change.id"
                      class="rounded-xl border border-slate-200 bg-slate-50 p-4"
                    >
                      <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="text-sm font-semibold text-slate-900">{{ historySummary(change) }}</p>
                        <span class="text-xs text-slate-500">{{ formatTimestamp(change.created_at) }}</span>
                      </div>
                      <p v-if="historyDetail(change)" class="mt-2 text-sm text-slate-600 whitespace-pre-wrap">{{ historyDetail(change) }}</p>
                    </div>
                  </div>
                  <p v-else class="text-sm text-slate-500">Aucun historique disponible pour ce test case.</p>
                </div>
              </section>
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
.project-context-label {
  margin: 0 0 0.35rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: #475569;
}

.execution-checklist-selector {
  display: grid;
  gap: 0.45rem;
  margin-bottom: 0.9rem;
}

.execution-checklist-selector select {
  width: 100%;
  border: 1px solid rgba(148, 163, 184, 0.3);
  border-radius: 0.85rem;
  background: #ffffff;
  color: #0f172a;
  padding: 0.7rem 0.85rem;
}
</style>
