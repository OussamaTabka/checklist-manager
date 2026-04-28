<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChecklistsStore } from '@/stores/checklists'
import { useAuthStore } from '@/stores/auth'
import { translateCurrentPhrase } from '@/lib/runtimeTranslations'
import { AlertCircle, ArrowLeft, BookOpen, CheckCircle, CheckSquare, Clock, Edit, FileText, Grid3x3, PlayCircle, Square, Trash2, TriangleAlert } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const checklistsStore = useChecklistsStore()
const auth = useAuthStore()

const checklistId = route.params.id
const expandedItems = ref({})
const showItemHistory = ref({})
const runModalOpen = ref(false)
const runSubmitBusy = ref(false)
const runModalError = ref('')
const runtimeStateByItemId = ref({})
const pollingItemIds = ref([])
const pollingTimer = ref(null)
const CHECKLIST_RUN_BASE_URL_STORAGE_KEY = 'checklist_item_run_base_url'

const runForm = reactive({
  itemId: null,
  baseUrl: '',
  environmentName: '',
  notes: '',
  useAuth: true,
  watchMode: true,
})

const statusIcons = {
  'Not Tested': { icon: Square, color: 'text-gray-500', label: 'Not Tested' },
  'Passed': { icon: CheckCircle, color: 'text-green-600', label: 'Passed' },
  'Failed': { icon: TriangleAlert, color: 'text-red-600', label: 'Failed' },
  'Blocked': { icon: AlertCircle, color: 'text-orange-600', label: 'Blocked' },
}

const criticalityColors = {
  Low: 'bg-green-100 text-green-800',
  Medium: 'bg-yellow-100 text-yellow-800',
  High: 'bg-orange-100 text-orange-800',
  Critical: 'bg-red-100 text-red-800',
  Major: 'bg-orange-100 text-orange-800',
  Minor: 'bg-slate-100 text-slate-800',
}

const fallbackItemStatus = { icon: Square, color: 'text-gray-500', label: 'Not Tested' }

const checklist = computed(() => checklistsStore.currentChecklist)

const scenariosStats = computed(() => {
  const items = Array.isArray(checklist.value?.items) ? checklist.value.items : []
  if (!items.length) {
    return { total: 0, passed: 0, failed: 0, blocked: 0, notTested: 0 }
  }

  const stats = { total: items.length, passed: 0, failed: 0, blocked: 0, notTested: 0 }
  items.forEach((item) => {
    const status = displayStatus(item.status)
    if (status === 'Passed') stats.passed++
    else if (status === 'Failed') stats.failed++
    else if (status === 'Blocked') stats.blocked++
    else stats.notTested++
  })
  return stats
})

const nextRunnableItem = computed(() => {
  const items = Array.isArray(checklist.value?.items) ? checklist.value.items : []
  return items.find((item) => ['Not Tested', 'Failed', 'Blocked'].includes(displayStatus(item.status))) || items[0] || null
})

function displayStatus(status) {
  return ['Passed', 'Failed', 'Blocked'].includes(status) ? status : 'Not Tested'
}

function getItemStatusMeta(status) {
  return statusIcons[displayStatus(status)] || fallbackItemStatus
}

function getItemCriticalityClass(criticality) {
  return criticalityColors[criticality] || 'bg-gray-100 text-gray-700'
}

function formatChecklistPriority(priority) {
  if (!priority) return 'Unknown'
  const value = String(priority)
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function formatChecklistStatus(status) {
  if (!status) return 'Unknown'
  const value = String(status).replaceAll('_', ' ')
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function getChecklistPriorityTagClass(priority) {
  if (priority === 'critical') return 'text-red-900 border-red-300 bg-red-100'
  if (priority === 'high') return 'text-orange-900 border-orange-300 bg-orange-100'
  if (priority === 'medium') return 'text-yellow-900 border-yellow-300 bg-yellow-100'
  if (priority === 'low') return 'text-green-900 border-green-300 bg-green-100'
  return 'text-gray-900 border-gray-300 bg-gray-100'
}

function getScenarioStatusTagClass(status) {
  const display = displayStatus(status)
  if (display === 'Passed') return 'text-green-900 border-green-300 bg-green-100'
  if (display === 'Failed') return 'text-red-900 border-red-300 bg-red-100'
  if (display === 'Blocked') return 'text-orange-900 border-orange-300 bg-orange-100'
  return 'text-gray-900 border-gray-300 bg-gray-100'
}

function executionStateLabel(state) {
  switch (state) {
    case 'queued':
      return 'Queued'
    case 'running':
      return 'Running'
    case 'passed':
      return 'Passed'
    case 'failed':
      return 'Failed'
    case 'blocked':
      return 'Blocked'
    default:
      return 'Idle'
  }
}

function executionStateClass(state) {
  return `run-chip run-chip-${state}`
}

function stateForItem(item) {
  return runtimeStateByItemId.value[item.id] || {
    execution_state: mapStatusToExecutionState(displayStatus(item.status)),
    last_run_id: null,
    last_run_status: null,
    last_error_message: null,
    execution_trace: [],
    artifacts: { trace: [], screenshot: [], video: [] },
  }
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
  if (state === 'queued') return 'Queuing...'
  if (state === 'running') return 'Running...'
  return 'Run Test'
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
      next[item.id] = {
        execution_state: mapStatusToExecutionState(displayStatus(item.status)),
        last_run_id: null,
        last_run_status: null,
        last_error_message: null,
        execution_trace: [],
        artifacts: { trace: [], screenshot: [], video: [] },
      }
    }
  }

  runtimeStateByItemId.value = next
}

async function loadChecklist() {
  try {
    await checklistsStore.fetchChecklist(checklistId)
    seedRuntimeStateFromChecklist()
  } catch (error) {
    console.error('Failed to load checklist:', error)
  }
}

async function toggleItemHistory(itemId) {
  if (showItemHistory.value[itemId]) {
    showItemHistory.value[itemId] = false
    return
  }

  try {
    const history = await checklistsStore.getItemHistory(checklistId, itemId)
    const item = checklist.value?.items?.find((entry) => entry.id === itemId)
    if (item) {
      item.history = history
    }
    showItemHistory.value[itemId] = true
  } catch (error) {
    console.error('Failed to load history:', error)
  }
}

async function updateItemStatus(itemId, newStatus) {
  try {
    await checklistsStore.updateItemStatus(checklistId, itemId, newStatus)
    const item = checklist.value?.items?.find((entry) => entry.id === itemId)
    if (item) {
      item.status = newStatus
    }
    runtimeStateByItemId.value = {
      ...runtimeStateByItemId.value,
      [itemId]: {
        ...stateForItem({ id: itemId, status: newStatus }),
        execution_state: mapStatusToExecutionState(newStatus),
      },
    }
  } catch (error) {
    console.error('Failed to update status:', error)
  }
}

async function refreshExecutionState(itemId) {
  try {
    const data = await checklistsStore.getItemExecution(checklistId, itemId)
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
      },
    }

    const item = checklist.value?.items?.find((entry) => entry.id === itemId)
    if (item && data.status) {
      item.status = data.status
    }

    if (!['queued', 'running'].includes(data.execution_state)) {
      removePollingItem(itemId)
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

function openRunModal(item) {
  runForm.itemId = item.id
  const persistedBaseUrl = readPersistedRunBaseUrl()
  runForm.baseUrl = persistedBaseUrl || 'http://localhost:5173'
  runForm.environmentName = ''
  runForm.notes = ''
  runForm.useAuth = true
  runForm.watchMode = true
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
    runModalError.value = 'No test case selected.'
    return
  }

  const normalizedBaseUrl = normalizeBaseUrl(runForm.baseUrl.trim())
  if (!isValidHttpUrl(normalizedBaseUrl)) {
    runModalError.value = 'Base URL must be a valid http/https URL.'
    return
  }

  runSubmitBusy.value = true
  persistRunBaseUrl(normalizedBaseUrl)

  try {
    const response = await checklistsStore.runChecklistItem(checklistId, runForm.itemId, {
      base_url: normalizedBaseUrl,
      use_auth: !!runForm.useAuth,
      watch_mode: !!runForm.watchMode,
      environment_name: runForm.environmentName.trim() || null,
      notes: runForm.notes.trim() || null,
    })

    runtimeStateByItemId.value = {
      ...runtimeStateByItemId.value,
      [runForm.itemId]: {
        execution_state: response.status === 'started' ? 'running' : 'queued',
        last_run_id: response.run_id || null,
        last_run_status: response.status || 'queued',
        last_error_message: null,
        execution_trace: [],
        artifacts: { trace: [], screenshot: [], video: [] },
      },
    }

    addPollingItem(runForm.itemId)
    runModalOpen.value = false
  } catch (error) {
    runModalError.value = error.response?.data?.message || error.message || 'Failed to queue run.'
  } finally {
    runSubmitBusy.value = false
  }
}

function editChecklist() {
  router.push({
    name: 'checklists',
  })
}

async function deleteChecklist() {
  if (!confirm(translateCurrentPhrase('Are you sure you want to delete this checklist? This action cannot be undone.'))) {
    return
  }

  try {
    await checklistsStore.deleteChecklist(checklistId)
    router.push({ name: 'checklists' })
  } catch (error) {
    console.error('Failed to delete checklist:', error)
  }
}

function goBack() {
  router.back()
}

function toggleItem(itemId) {
  expandedItems.value[itemId] = !expandedItems.value[itemId]
}

onMounted(() => {
  loadChecklist()
})

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
            <p class="project-execution-kicker">Checklist workspace</p>
            <h1>{{ checklist?.name || 'Loading...' }}</h1>
            <p class="project-execution-subtitle">
              Review each test case, run it automatically, and update the final QA status directly from the checklist.
            </p>
          </div>
        </div>
      </div>

      <div class="project-execution-side">
        <div class="project-execution-app-card">
          <span class="project-execution-side-label">Execution</span>
          <p class="muted">Each checklist item can be run automatically, then marked Passed, Failed, Blocked, or Not Tested.</p>
        </div>
        <div class="project-execution-hero-actions">
          <button class="btn btn-primary" :disabled="!auth.canTest || !nextRunnableItem" @click="runNextSuggestedItem">
            <PlayCircle :size="16" />
            <span>Run next test</span>
          </button>
          <button v-if="auth.canManageChecklists" @click="editChecklist" class="btn btn-secondary">
            <Edit :size="16" />
            <span>Edit</span>
          </button>
          <button v-if="auth.canManageChecklists" @click="deleteChecklist" class="btn btn-danger">
            <Trash2 :size="16" />
            <span>Delete</span>
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
          <span>Checklist</span>
          <strong>#{{ checklistId }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Scenarios</span>
          <strong>{{ scenariosStats.total }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Passed</span>
          <strong>{{ scenariosStats.passed }}</strong>
        </article>
        <article class="story-detail-metric">
          <span>Failed + blocked</span>
          <strong>{{ scenariosStats.failed + scenariosStats.blocked }}</strong>
        </article>
      </div>

      <div class="card stack">
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200 mb-4">
          <FileText :size="20" class="text-blue-600" />
          <h2>General information</h2>
        </div>

        <div class="grid grid-cols-[1fr_1fr_1fr] gap-4">
          <div>
            <p class="muted mb-1">Priority</p>
            <span :class="['tag', getChecklistPriorityTagClass(checklist.priority)]">
              {{ formatChecklistPriority(checklist.priority) }}
            </span>
          </div>

          <div>
            <p class="muted mb-1">Status</p>
            <span class="tag text-blue-900 border-blue-300 bg-blue-100">
              {{ formatChecklistStatus(checklist.status) }}
            </span>
          </div>

          <div>
            <p class="muted mb-1">Scenarios</p>
            <span class="tag text-gray-900 border-gray-300 bg-gray-100">
              {{ scenariosStats.total }} Total
            </span>
          </div>
        </div>

        <div class="mt-4">
          <p class="muted mb-2">Description</p>
          <p class="text-gray-700">{{ checklist.description }}</p>
        </div>
      </div>

      <div v-if="checklist.as_a || checklist.i_want_that || checklist.so_that" class="card stack">
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200 mb-4">
          <BookOpen :size="20" class="text-green-600" />
          <h2>User story</h2>
        </div>

        <div class="space-y-3">
          <div v-if="checklist.as_a">
            <p class="muted mb-1">As A</p>
            <p class="text-gray-700">{{ checklist.as_a }}</p>
          </div>
          <div v-if="checklist.i_want_that">
            <p class="muted mb-1">I Want That</p>
            <p class="text-gray-700 whitespace-pre-wrap">{{ checklist.i_want_that }}</p>
          </div>
          <div v-if="checklist.so_that">
            <p class="muted mb-1">So That</p>
            <p class="text-gray-700 whitespace-pre-wrap">{{ checklist.so_that }}</p>
          </div>
          <div v-if="checklist.acceptance_criteria" class="mt-4 pt-4 border-t border-gray-200">
            <p class="muted mb-2">Acceptance criteria</p>
            <div class="bg-gray-50 border border-gray-200 p-3 rounded font-mono text-sm whitespace-pre-wrap text-gray-700">
              {{ checklist.acceptance_criteria }}
            </div>
          </div>
        </div>
      </div>

      <div v-if="checklist.business_rules && checklist.business_rules.length > 0" class="card stack">
        <div class="flex items-center gap-3 pb-4 border-b border-gray-200 mb-4">
          <Grid3x3 :size="20" class="text-purple-600" />
          <h2>Business rules</h2>
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
              <h2>Executable test scenarios</h2>
              <span class="tag text-blue-900 border-blue-300 bg-blue-100">{{ scenariosStats.total }}</span>
            </div>
            <p class="muted">Run Test is available directly on each checklist item.</p>
          </div>

          <div class="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-3 text-sm">
            <div class="bg-white border border-gray-200 rounded p-2 text-center">
              <p class="muted text-xs mb-1">Total</p>
              <p class="font-bold text-gray-900">{{ scenariosStats.total }}</p>
            </div>
            <div class="bg-green-50 border border-green-200 rounded p-2 text-center">
              <p class="text-green-700 text-xs font-medium mb-1">Passed</p>
              <p class="font-bold text-green-900">{{ scenariosStats.passed }}</p>
            </div>
            <div class="bg-red-50 border border-red-200 rounded p-2 text-center">
              <p class="text-red-700 text-xs font-medium mb-1">Failed</p>
              <p class="font-bold text-red-900">{{ scenariosStats.failed }}</p>
            </div>
            <div class="bg-orange-50 border border-orange-200 rounded p-2 text-center">
              <p class="text-orange-700 text-xs font-medium mb-1">Blocked</p>
              <p class="font-bold text-orange-900">{{ scenariosStats.blocked }}</p>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded p-2 text-center">
              <p class="muted text-xs mb-1">Not Tested</p>
              <p class="font-bold text-gray-900">{{ scenariosStats.notTested }}</p>
            </div>
          </div>
        </div>

        <div v-if="checklist.items && checklist.items.length > 0" class="space-y-3">
          <div v-for="item in checklist.items" :key="item.id" class="bg-gray-50 border border-gray-200 rounded-lg overflow-hidden">
            <button
              @click="toggleItem(item.id)"
              class="w-full px-4 py-3 flex items-center justify-between hover:bg-gray-100 transition"
            >
              <div class="flex items-center gap-3 flex-1 text-left">
                <component :is="getItemStatusMeta(item.status).icon" :size="20" :class="getItemStatusMeta(item.status).color" />
                <div>
                  <div class="item-title-cell">
                    <span class="item-case-badge">TC-{{ String((item.order ?? 0) + 1).padStart(2, '0') }}</span>
                    <p class="font-semibold text-gray-900">{{ item.title }}</p>
                  </div>
                  <p v-if="item.description" class="text-sm text-gray-600">{{ item.description }}</p>
                </div>
              </div>
              <div class="flex items-center gap-2 ml-4">
                <span :class="['tag text-xs', getItemCriticalityClass(item.criticality)]">
                  {{ item.criticality }}
                </span>
                <span :class="executionStateClass(stateForItem(item).execution_state)">
                  {{ executionStateLabel(stateForItem(item).execution_state) }}
                </span>
                <span :class="['tag text-xs', getScenarioStatusTagClass(item.status)]">
                  {{ displayStatus(item.status) }}
                </span>
              </div>
            </button>

            <div v-if="expandedItems[item.id]" class="bg-white border-t border-gray-200 px-4 py-4 space-y-4">
              <div class="execution-toolbar checklist-item-toolbar">
                <div class="execution-toolbar-main">
                  <div class="execution-toolbar-copy">
                    <span class="execution-toolbar-label">Automated execution</span>
                    <h4>{{ item.title }}</h4>
                    <p class="muted">Run this checklist item automatically, then keep the final status aligned with the result.</p>
                  </div>
                </div>

                <div class="execution-toolbar-actions">
                  <button class="btn btn-primary btn-sm run-test-inline-btn" :disabled="!auth.canTest || isRunInFlight(item)" @click="openRunModal(item)">
                    <PlayCircle :size="14" />
                    <span>{{ runButtonLabel(item) }}</span>
                  </button>
                  <a
                    v-if="getArtifactUrl(item)"
                    :href="getArtifactUrl(item)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="muted artifact-link"
                  >
                    View artifacts
                  </a>
                </div>
              </div>

              <span v-if="stateForItem(item).last_error_message" class="error run-error-inline">
                {{ stateForItem(item).last_error_message }}
              </span>

              <details v-if="stateForItem(item).execution_trace?.length" class="trace-details">
                <summary class="muted trace-summary">Execution trace ({{ stateForItem(item).execution_trace.length }})</summary>
                <ol class="trace-list">
                  <li v-for="(line, index) in stateForItem(item).execution_trace" :key="`${item.id}-trace-${index}`" class="trace-item">
                    {{ line }}
                  </li>
                </ol>
              </details>

              <div>
                <p class="text-sm font-bold uppercase tracking-wider text-gray-700 mb-3">Update status</p>
                <div class="flex gap-2 flex-wrap">
                  <button
                    v-for="status in ['Not Tested', 'Passed', 'Failed', 'Blocked']"
                    :key="status"
                    @click="updateItemStatus(item.id, status)"
                    :class="['btn btn-sm', displayStatus(item.status) === status ? 'btn-primary' : 'btn-secondary']"
                  >
                    {{ status }}
                  </button>
                </div>
                <p v-if="item.tested_at" class="text-xs text-gray-600 mt-3">
                  <Clock :size="14" class="inline mr-1" />
                  Last tested: {{ new Date(item.tested_at).toLocaleString() }}
                </p>
              </div>

              <div class="border-t border-gray-200 pt-4">
                <button
                  @click="toggleItemHistory(item.id)"
                  class="flex items-center gap-2 text-sm font-bold text-blue-600 hover:text-blue-700 uppercase tracking-wider"
                >
                  <Clock :size="16" />
                  {{ showItemHistory[item.id] ? 'Hide history' : 'Show history' }}
                </button>

                <div v-if="showItemHistory[item.id] && item.history" class="mt-3 space-y-2 max-h-80 overflow-y-auto">
                  <div
                    v-for="change in item.history"
                    :key="change.id"
                    class="bg-gray-50 border border-gray-200 p-3 rounded text-sm"
                  >
                    <div class="flex justify-between items-start mb-2">
                      <p class="font-bold text-gray-900">{{ change.changed_by?.name || 'Unknown user' }}</p>
                      <span class="text-xs text-gray-600">{{ new Date(change.created_at).toLocaleString() }}</span>
                    </div>
                    <p class="text-gray-700 text-sm">
                      <strong class="text-blue-600">{{ change.field_name }}:</strong> {{ change.old_value }} → {{ change.new_value }}
                    </p>
                    <p v-if="change.notes" class="text-gray-600 text-xs mt-2 italic">{{ change.notes }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="text-center py-12">
          <AlertCircle :size="32" class="mx-auto text-gray-400 mb-3" />
          <p class="text-gray-600 muted">No scenarios added yet</p>
        </div>
      </div>
    </div>

    <div v-else class="card text-center py-12">
      <p class="muted">Checklist not found</p>
    </div>

    <div v-if="runModalOpen" class="run-modal-overlay">
      <div class="card stack run-modal-card">
        <div class="section-header section-header-tight">
          <h3>Run checklist item</h3>
        </div>

        <p class="muted modal-intro">Provide target website info for this checklist run.</p>
        <p v-if="runModalError" class="error">{{ runModalError }}</p>

        <form class="stack" @submit.prevent="submitRunModal">
          <div class="field">
            <label>Base URL (required)</label>
            <input v-model="runForm.baseUrl" placeholder="https://example.com" :disabled="runSubmitBusy" required />
          </div>

          <div class="grid">
            <div class="field">
              <label>Environment name (optional)</label>
              <input v-model="runForm.environmentName" placeholder="staging" :disabled="runSubmitBusy" />
            </div>

            <div class="field field-end-controls">
              <label class="checkbox-label">
                <input type="checkbox" v-model="runForm.useAuth" :disabled="runSubmitBusy" />
                <span>Use authenticated flow</span>
              </label>
              <p class="muted checkbox-help">Uncheck to run unauthenticated.</p>

              <label class="checkbox-label checkbox-label-spaced">
                <input type="checkbox" v-model="runForm.watchMode" :disabled="runSubmitBusy" />
                <span>Watch mode (open browser window)</span>
              </label>
              <p class="muted checkbox-help">Keep enabled to watch the test live in an external browser.</p>
            </div>
          </div>

          <div class="field">
            <label>Notes / constraints (optional)</label>
            <textarea v-model="runForm.notes" rows="3" :disabled="runSubmitBusy" />
          </div>

          <div class="actions">
            <button class="btn btn-primary" type="submit" :disabled="runSubmitBusy">
              {{ runSubmitBusy ? 'Queuing...' : 'Run now' }}
            </button>
            <button class="btn btn-secondary" type="button" :disabled="runSubmitBusy" @click="closeRunModal">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>
