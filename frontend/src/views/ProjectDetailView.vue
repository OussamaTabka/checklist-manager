<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { CheckCircle2, ClipboardList, ExternalLink, FilePenLine, Layers3, MessageCircle, Paperclip, PlayCircle } from 'lucide-vue-next'
import { API_BASE_URL, apiRequest, ensureCsrfCookie } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useUserStoriesStore } from '@/stores/userStories'
import { translateCurrentPhrase } from '@/lib/runtimeTranslations'

const route = useRoute()
const auth = useAuthStore()
const storiesStore = useUserStoriesStore()

const project = ref(null)
const loading = ref(false)
const pageError = ref('')
const actionError = ref('')
const actionSuccess = ref('')
const checklists = ref([])
const progress = ref(null)

const selectedVersionId = ref('')
const openExportDropdown = ref(null)
const selectedItemId = ref(null)
const comments = ref([])
const commentsLoading = ref(false)
const newComment = ref('')
const newCommentFile = ref(null)
const itemHistory = ref([])
const historyLoading = ref(false)
const showHistory = ref(false)
const runModalOpen = ref(false)
const runSubmitBusy = ref(false)
const runModalError = ref('')
const runtimeStateByItemId = ref({})
const pollingItemIds = ref([])
const pollingTimer = ref(null)
const RUN_MODAL_BASE_URL_STORAGE_KEY = 'single_test_run_base_url'
const runForm = reactive({
  itemId: null,
  baseUrl: '',
  environmentName: '',
  notes: '',
  useAuth: true,
  watchMode: true,
})
const versionForm = reactive({
  checklist_id: '',
})

const storyStatusLabels = {
  backlog: 'Backlog',
  in_progress: 'In Progress',
  ready_for_test: 'Ready for Test',
  completed: 'Completed',
}

// Inline checklist editor state
const showChecklistEditor = ref(false)
const availableItems = ref([])
const selectedExistingItemId = ref('')
const itemSearchQueries = ref({})
const checklistEditorForm = reactive({
  id: null,
  name: '',
  description: '',
  category: '',
  items: [],
})

const defaultItem = () => ({
  id: null,
  title: '',
  description: '',
  priority: 'Medium',
  criticality: 'Major',
})

const selectedVersion = computed(() => {
  if (!project.value) {
    return null
  }

  const targetId = Number(selectedVersionId.value)
  return project.value.versions.find((version) => version.id === targetId) || null
})

const projectVersions = computed(() => project.value?.versions || [])

const selectedVersionChecklistName = computed(() => selectedVersion.value?.checklist?.name || 'Checklist')

const selectedVersionItemCount = computed(() => selectedVersion.value?.items?.length || 0)

const selectedVersionPendingCount = computed(() => {
  if (!selectedVersion.value?.items) {
    return 0
  }

  return selectedVersion.value.items.filter((item) => displayStatus(item.status) === 'Pending').length
})

const nextRunnableItem = computed(() => {
  if (!selectedVersion.value?.items?.length) {
    return null
  }

  return (
    selectedVersion.value.items.find((item) => ['Pending', 'Failed', 'Blocked'].includes(displayStatus(item.status))) ||
    selectedVersion.value.items[0]
  )
})

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

function isRunInFlight(item) {
  const state = stateForItem(item).execution_state
  return state === 'queued' || state === 'running'
}

function runButtonLabel(item) {
  const state = stateForItem(item).execution_state
  if (state === 'queued') {
    return 'Queuing...'
  }
  if (state === 'running') {
    return 'Running...'
  }
  return 'Run'
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
  if (typeof window === 'undefined') {
    return ''
  }

  const persisted = window.localStorage.getItem(RUN_MODAL_BASE_URL_STORAGE_KEY) || ''
  const normalized = normalizeBaseUrl(persisted.trim())
  return isValidHttpUrl(normalized) ? normalized : ''
}

function persistRunBaseUrl(url) {
  if (typeof window === 'undefined') {
    return
  }

  window.localStorage.setItem(RUN_MODAL_BASE_URL_STORAGE_KEY, url)
}

function seedRuntimeStateFromVersion(version) {
  if (!version?.items) {
    return
  }

  const next = { ...runtimeStateByItemId.value }
  for (const item of version.items) {
    if (!next[item.id]) {
      next[item.id] = {
        execution_state: mapStatusToExecutionState(item.status),
        last_run_id: null,
        last_run_status: null,
        last_error_message: null,
        execution_trace: [],
        artifacts: {
          trace: [],
          screenshot: [],
          video: [],
        },
      }
    }
  }

  runtimeStateByItemId.value = next
}

function stateForItem(item) {
  return (
    runtimeStateByItemId.value[item.id] || {
      execution_state: mapStatusToExecutionState(item.status),
      last_run_id: null,
      last_run_status: null,
      last_error_message: null,
      execution_trace: [],
      artifacts: {
        trace: [],
        screenshot: [],
        video: [],
      },
    }
  )
}

function versionItemCount(version) {
  return Array.isArray(version?.items) ? version.items.length : 0
}

function versionPendingCount(version) {
  if (!Array.isArray(version?.items)) {
    return 0
  }

  return version.items.filter((item) => displayStatus(item.status) === 'Pending').length
}

function selectVersionCard(versionId) {
  selectedVersionId.value = String(versionId)
}

function runNextSuggestedItem() {
  if (nextRunnableItem.value) {
    openRunModal(nextRunnableItem.value)
  }
}

function getArtifactUrl(item) {
  const runtime = stateForItem(item)
  const traces = Array.isArray(runtime.artifacts?.trace) ? runtime.artifacts.trace : []
  const screenshots = Array.isArray(runtime.artifacts?.screenshot) ? runtime.artifacts.screenshot : []
  const videos = Array.isArray(runtime.artifacts?.video) ? runtime.artifacts.video : []
  const candidates = [...traces, ...screenshots, ...videos]
  const first = candidates.find((value) => typeof value === 'string' && /^https?:\/\//.test(value))
  return first || null
}

function ensurePollingStarted() {
  if (pollingTimer.value || pollingItemIds.value.length === 0) {
    return
  }

  pollingTimer.value = setInterval(async () => {
    const ids = [...pollingItemIds.value]
    for (const itemId of ids) {
      await refreshTestCaseState(itemId)
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

async function refreshTestCaseState(itemId) {
  try {
    const data = await apiRequest(`/test-cases/${itemId}`, {}, auth.token)

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

    if (!['queued', 'running'].includes(data.execution_state)) {
      removePollingItem(itemId)
      await loadProject()
      if (selectedVersionId.value) {
        await loadProgress(selectedVersionId.value)
      }
    }
  } catch {
    // keep polling on transient errors
  }
}

function openRunModal(item) {
  runForm.itemId = item.id
  const persistedBaseUrl = readPersistedRunBaseUrl()
  if (persistedBaseUrl) {
    runForm.baseUrl = persistedBaseUrl
  } else {
    runForm.baseUrl = project.value?.app_url ? normalizeBaseUrl(project.value.app_url) : 'http://localhost:5173'
  }
  runForm.environmentName = ''
  runForm.notes = ''
  runForm.useAuth = true
  runForm.watchMode = true
  runModalError.value = ''
  runSubmitBusy.value = false
  runModalOpen.value = true
}

function closeRunModal() {
  if (runSubmitBusy.value) {
    return
  }
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
    await ensureCsrfCookie()

    const response = await apiRequest(
      `/test-cases/${runForm.itemId}/runs`,
      {
        method: 'POST',
        body: {
          base_url: normalizedBaseUrl,
          use_auth: !!runForm.useAuth,
          watch_mode: !!runForm.watchMode,
          environment_name: runForm.environmentName.trim() || null,
          notes: runForm.notes.trim() || null,
        },
      },
      auth.token,
    )

    runtimeStateByItemId.value = {
      ...runtimeStateByItemId.value,
      [runForm.itemId]: {
        execution_state: response.status === 'started' ? 'running' : 'queued',
        last_run_id: response.run_id || null,
        last_run_status: response.status || 'queued',
        last_error_message: null,
        execution_trace: [],
        artifacts: {
          trace: [],
          screenshot: [],
          video: [],
        },
      },
    }

    addPollingItem(runForm.itemId)
    runModalOpen.value = false
  } catch (error) {
    runModalError.value = error.data?.message || error.message || 'Failed to queue run.'
  } finally {
    runSubmitBusy.value = false
  }
}

async function loadProject() {
  loading.value = true
  pageError.value = ''

  try {
    const data = await apiRequest(`/projects/${route.params.id}`, {}, auth.token)
    project.value = data

    if (data.versions?.length) {
      const existingSelection = data.versions.find((version) => String(version.id) === String(selectedVersionId.value))
      const versionToUse = existingSelection || data.versions[0]

      selectedVersionId.value = String(versionToUse.id)
      await loadProgress(versionToUse.id)
      seedRuntimeStateFromVersion(versionToUse)
    } else {
      selectedVersionId.value = ''
      selectedItemId.value = null
      progress.value = null
    }
  } catch (error) {
    pageError.value = error.data?.message || error.message
  } finally {
    loading.value = false
  }
}

async function loadUserStories() {
  try {
    await storiesStore.fetchStories(route.params.id)
  } catch {
    // Store error is enough for this embedded view.
  }
}

async function loadProgress(versionId) {
  if (!versionId) {
    progress.value = null
    return
  }

  try {
    progress.value = await apiRequest(`/project-versions/${versionId}/progress`, {}, auth.token)
  } catch {
    progress.value = null
  }
}

async function loadChecklists() {
  if (!auth.isTester) {
    checklists.value = []
    return
  }

  try {
    const data = await apiRequest(`/checklists?project_id=${route.params.id}`, {}, auth.token)
    checklists.value = (data.data || []).filter((checklist) => checklist.is_active)
  } catch {
    checklists.value = []
  }
}

function normalizeStatusForApi(status) {
  return status === 'Pending' ? 'Not Tested' : status
}

function displayStatus(status) {
  return status === 'Not Tested' ? 'Pending' : status
}

function getEventBorderColor(eventType) {
  const colors = {
    'change': 'background: #f0f9ff; border-left-color: #3b82f6;',
    'comment': 'background: #fdf2f8; border-left-color: #7c3aed;',
    'test': 'background: #f0fdf4; border-left-color: #059669;',
    'execution': 'background: #fff7ed; border-left-color: #ea580c;',
  }
  return colors[eventType] || 'background: #f3f4f6; border-left-color: #6b7280;'
}

async function updateItemStatus(itemId, status) {
  actionError.value = ''
  actionSuccess.value = ''

  try {
    await apiRequest(
      `/version-items/${itemId}/status`,
      {
        method: 'PATCH',
        body: { status: normalizeStatusForApi(status) },
      },
      auth.token,
    )

    actionSuccess.value = 'Item status updated.'
    await loadProject()
  } catch (error) {
    actionError.value = error.data?.message || error.message
  }
}

async function createVersion() {
  actionError.value = ''
  actionSuccess.value = ''

  try {
    await apiRequest(
      `/projects/${route.params.id}/versions`,
      {
        method: 'POST',
        body: {
          checklist_id: Number(versionForm.checklist_id),
        },
      },
      auth.token,
    )

    versionForm.checklist_id = ''
    actionSuccess.value = 'New version created successfully.'
    await loadProject()
  } catch (error) {
    actionError.value = error.data?.message || error.message
  }
}

// Inline checklist editor functions
function resetChecklistEditor() {
  checklistEditorForm.id = null
  checklistEditorForm.name = ''
  checklistEditorForm.description = ''
  checklistEditorForm.category = ''
  checklistEditorForm.items = [defaultItem()]
  selectedExistingItemId.value = ''
  itemSearchQueries.value = {}
}

function openChecklistEditor(checklistId) {
  const checklist = checklists.value.find((c) => c.id === checklistId)
  if (!checklist) {
    actionError.value = 'Checklist not found'
    return
  }

  checklistEditorForm.id = checklist.id
  checklistEditorForm.name = checklist.name
  checklistEditorForm.description = checklist.description || ''
  checklistEditorForm.category = checklist.category || ''
  checklistEditorForm.items = checklist.items.map((item) => ({
    id: null, // Don't keep original IDs for new version items
    title: item.title,
    description: item.description || '',
    priority: item.priority,
    criticality: item.criticality,
  }))
  showChecklistEditor.value = true
  versionForm.checklist_id = ''
}

function closeChecklistEditor() {
  showChecklistEditor.value = false
  resetChecklistEditor()
}

async function loadAvailableItems() {
  if (!auth.canManageChecklists) {
    availableItems.value = []
    return
  }

  try {
    const data = await apiRequest('/checklists/items/available', {}, auth.token)
    availableItems.value = data || []
  } catch (error) {
    // Expected for roles without checklist privileges when stale calls happen.
    if (error?.status === 403) {
      availableItems.value = []
      return
    }

    console.log('Could not load available items:', error.message)
  }
}

function addItemToEditor() {
  checklistEditorForm.items.push(defaultItem())
}

function removeItemFromEditor(index) {
  if (checklistEditorForm.items.length === 1) {
    return
  }
  checklistEditorForm.items.splice(index, 1)
  delete itemSearchQueries.value[index]
}

function addExistingItemToEditor() {
  if (!selectedExistingItemId.value) {
    return
  }

  const existingItem = availableItems.value.find(
    (item) => String(item.id) === String(selectedExistingItemId.value)
  )

  if (!existingItem) {
    return
  }

  checklistEditorForm.items.push({
    id: null,
    title: existingItem.title,
    description: existingItem.description || '',
    priority: existingItem.priority,
    criticality: existingItem.criticality,
  })

  selectedExistingItemId.value = ''
}

function selectExistingItemInEditor(itemIndex, existingItem) {
  checklistEditorForm.items[itemIndex] = {
    id: null,
    title: existingItem.title,
    description: existingItem.description || '',
    priority: existingItem.priority,
    criticality: existingItem.criticality,
  }
  itemSearchQueries.value[itemIndex] = ''
}

function getFilteredItemsInEditor(itemIndex) {
  const query = itemSearchQueries.value[itemIndex] || ''
  if (!query) return availableItems.value
  
  return availableItems.value.filter(item =>
    item.title.toLowerCase().startsWith(query.toLowerCase())
  )
}

async function createVersionWithEditedChecklist() {
  actionError.value = ''
  actionSuccess.value = ''

  try {
    // Create a temporary checklist with the edited data
    const checklistPayload = {
      name: checklistEditorForm.name,
      description: checklistEditorForm.description || null,
      category: checklistEditorForm.category || null,
      project_id: Number(route.params.id),
      is_active: true,
      items: checklistEditorForm.items.map((item) => ({
        title: item.title,
        description: item.description || null,
        priority: item.priority,
        criticality: item.criticality,
      })),
    }

    const newChecklist = await apiRequest('/checklists', { method: 'POST', body: checklistPayload }, auth.token)

    // Create version with the new checklist
    await apiRequest(
      `/projects/${route.params.id}/versions`,
      {
        method: 'POST',
        body: {
          checklist_id: newChecklist.id,
        },
      },
      auth.token,
    )

    closeChecklistEditor()
    await loadChecklists() // Reload checklists to show the new one
    actionSuccess.value = 'New version created with custom checklist.'
    await loadProject()
  } catch (error) {
    actionError.value = error.data?.message || error.message
  }
}

async function loadComments(itemId) {
  if (!itemId) {
    comments.value = []
    return
  }

  commentsLoading.value = true
  

 try {
    const data = await apiRequest(`/version-items/${itemId}/comments`, {}, auth.token)
    comments.value = Array.isArray(data) ? data : []
  } catch {
    comments.value = []
  } finally {
    commentsLoading.value = false
  }
}

async function loadHistory(itemId) {
  if (!itemId) {
    itemHistory.value = []
    return
  }

  historyLoading.value = true

  try {
    const data = await apiRequest(`/version-items/${itemId}/history`, {}, auth.token)
    itemHistory.value = {
      timeline: Array.isArray(data.timeline) ? data.timeline : [],
      total_events: data.total_events || 0,
      tested_by: data.tested_by,
      tested_at: data.tested_at,
      current_status: data.current_status,
    }
  } catch {
    itemHistory.value = { timeline: [], total_events: 0, tested_by: null, tested_at: null, current_status: null }
  } finally {
    historyLoading.value = false
  }
}

async function addComment(itemId) {
  if (!newComment.value.trim()) {
    return
  }

  actionError.value = ''
  actionSuccess.value = ''

  try {
    // Use FormData to support file upload
    const formData = new FormData()
    formData.append('content', newComment.value)
    
    if (newCommentFile.value) {
      formData.append('file', newCommentFile.value)
    }

    const response = await fetch(`${API_BASE_URL}/version-items/${itemId}/comments`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Authorization': `Bearer ${auth.token}`,
        'Accept': 'application/json',
      },
      body: formData,
    })

    if (!response.ok) {
      const errorData = await response.json()
      throw new Error(errorData.message || 'Failed to add comment')
    }

    newComment.value = ''
    newCommentFile.value = null
    if (document.querySelector('input[type="file"]')) {
      document.querySelector('input[type="file"]').value = ''
    }
    actionSuccess.value = 'Comment added successfully.'
    await loadComments(itemId)
  } catch (error) {
    actionError.value = error.message || 'Failed to add comment'
  }
}

function onFileSelected(event) {
  const file = event.target.files?.[0]
  if (file) {
    // Validate file size (max 10MB)
    const maxSize = 10 * 1024 * 1024
    if (file.size > maxSize) {
      actionError.value = 'File size must be less than 10 MB'
      newCommentFile.value = null
      event.target.value = ''
      return
    }
    newCommentFile.value = file
    actionError.value = ''
  }
}

async function deleteComment(commentId, itemId) {
  if (!confirm(translateCurrentPhrase('Delete this comment?'))) {
    return
  }

  actionError.value = ''

  try {
    await apiRequest(`/comments/${commentId}`, { method: 'DELETE' }, auth.token)
    actionSuccess.value = 'Comment deleted.'
    await loadComments(itemId)
  } catch (error) {
    actionError.value = error.data?.message || error.message
  }
}

async function exportVersion(versionId, format = 'csv') {
  actionError.value = ''
  actionSuccess.value = ''

  try {
    await downloadExportFile(
      `/project-versions/${versionId}/export/${format}`,
      `version-${versionId}-export.${format}`,
    )
    openExportDropdown.value = null
    actionSuccess.value = 'Version exported successfully.'
  } catch (error) {
    actionError.value = error.data?.message || error.message
  }
}

async function exportProject(format = 'csv') {
  actionError.value = ''
  actionSuccess.value = ''

  try {
    await downloadExportFile(
      `/projects/${route.params.id}/export/${format}`,
      `project-${route.params.id}-export.${format}`,
    )
    openExportDropdown.value = null
    actionSuccess.value = 'Project exported successfully.'
  } catch (error) {
    actionError.value = error.data?.message || error.message
  }
}

async function downloadExportFile(endpoint, fallbackFilename) {
  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    method: 'GET',
    credentials: 'include',
    headers: {
      Accept: '*/*',
    },
  })

  if (!response.ok) {
    let message = `Export failed with status ${response.status}`
    try {
      const body = await response.json()
      message = body.message || body.error || message
    } catch {
      // Keep default message when body is not JSON.
    }
    throw new Error(message)
  }

  const blob = await response.blob()
  const contentDisposition = response.headers.get('content-disposition') || ''
  const match = contentDisposition.match(/filename="?([^"]+)"?/) || contentDisposition.match(/filename=([^;]+)/)
  const filename = match?.[1]?.trim() || fallbackFilename

  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

watch(selectedVersionId, async (value) => {
  if (value) {
    await loadProgress(value)
    seedRuntimeStateFromVersion(selectedVersion.value)
  }
})

onBeforeUnmount(() => {
  if (pollingTimer.value) {
    clearInterval(pollingTimer.value)
    pollingTimer.value = null
  }
})

onMounted(async () => {
  if (auth.isTester) {
    await Promise.all([loadProject(), loadChecklists(), loadAvailableItems(), loadUserStories()])
    return
  }

  await Promise.all([loadProject(), loadUserStories()])
})
</script>

<template>
  <section class="page stack">
    <p v-if="pageError" class="error">{{ pageError }}</p>
    <p v-if="loading" class="muted">Loading project...</p>

    <template v-if="project && !loading">
      <div class="project-execution-hero">
        <div class="project-execution-copy">
          <p class="project-execution-kicker">Test workspace</p>
          <h1>{{ project.name }}</h1>
          <p class="project-execution-subtitle">
            Project context, linked user stories, execution checklists, environments, and automated runs in one place.
          </p>

          <div class="project-meta-pills">
            <span class="project-meta-pill">
              <Layers3 :size="14" />
              <span>{{ projectVersions.length }} version<span v-if="projectVersions.length !== 1">s</span></span>
            </span>
            <span class="project-meta-pill">
              <ClipboardList :size="14" />
              <span>{{ storiesStore.stories.length }} stor{{ storiesStore.stories.length === 1 ? 'y' : 'ies' }}</span>
            </span>
            <span class="project-meta-pill">
              <CheckCircle2 :size="14" />
              <span>{{ selectedVersionItemCount }} executable item<span v-if="selectedVersionItemCount !== 1">s</span></span>
            </span>
          </div>
        </div>

        <div class="project-execution-side">
          <div class="project-execution-app-card">
            <span class="project-execution-side-label">Target application</span>
            <a
              v-if="project.app_url"
              :href="project.app_url"
              target="_blank"
              rel="noopener noreferrer"
              class="project-app-link"
            >
              <span>{{ project.app_url }}</span>
              <ExternalLink :size="14" />
            </a>
            <p v-else class="muted">No app URL configured yet.</p>
            <p class="muted">Created by {{ project.creator?.name || '-' }}</p>
            <p class="muted">Objectives: {{ project.test_objectives || 'No test objectives defined yet.' }}</p>
          </div>

          <div class="project-execution-hero-actions">
            <button
              class="btn btn-primary"
              :disabled="!auth.canTest || !nextRunnableItem"
              @click="runNextSuggestedItem"
            >
              <PlayCircle :size="16" />
              <span>Run next test</span>
            </button>
            <RouterLink
              v-if="auth.canManageStories"
              class="btn btn-secondary"
              :to="{ name: 'story-create', query: { projectId: project.id } }"
            >
              New story
            </RouterLink>
          </div>
        </div>
      </div>

      <div class="card stack">
        <div class="section-header">
          <div>
            <h3>User stories</h3>
            <p class="muted">Review stories first, then build execution-ready versions from the right checklist.</p>
          </div>
        </div>

        <p v-if="storiesStore.error" class="error">{{ storiesStore.error }}</p>
        <p v-else-if="storiesStore.loading" class="muted">Loading user stories...</p>

        <div v-else-if="storiesStore.stories.length > 0" class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Story</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Checklists</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="story in storiesStore.stories" :key="story.id">
                <td>
                  <div class="stack stack-xs">
                    <strong>{{ story.title }}</strong>
                    <span class="muted">{{ story.description || 'No description' }}</span>
                  </div>
                </td>
                <td>{{ storyStatusLabels[story.status] || story.status }}</td>
                <td>{{ story.priority }}</td>
                <td>{{ story.checklists?.length || 0 }}</td>
                <td>
                  <RouterLink
                    class="btn btn-secondary btn-sm"
                    :to="{ name: 'story-detail', params: { id: story.id }, query: { projectId: project.id } }"
                  >
                    Open
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-else class="muted">No user stories yet. Start with stories, then build checklist drafts from them.</p>
      </div>

      <div v-if="auth.isTester" class="card stack project-create-version-card">
        <div class="section-header">
          <div>
            <h3>Create execution checklist</h3>
            <p class="muted">Choose a project checklist draft, adjust it if needed, then create the execution version.</p>
          </div>
        </div>
        <form class="grid" @submit.prevent="createVersion">
          <div class="field">
            <label>Execution checklist</label>
            <select v-model="versionForm.checklist_id" required>
              <option disabled value="">Select checklist</option>
              <option v-for="checklist in checklists" :key="checklist.id" :value="checklist.id">
                {{ checklist.name }}
              </option>
            </select>
          </div>
          <div class="actions actions-end-gap">
            <button class="btn btn-primary" type="submit">Create version</button>
            <button 
              type="button"
              class="btn btn-secondary"
              :disabled="!versionForm.checklist_id"
              @click="openChecklistEditor(Number(versionForm.checklist_id))"
            >
              Edit Before Execution
            </button>
          </div>
        </form>
      </div>

      <!-- Inline Checklist Editor Modal -->
      <div v-if="showChecklistEditor" class="card stack checklist-editor-panel">
        <h3>Edit Checklist Before Execution</h3>
        <p class="muted">Customize this checklist before creating the version. Changes won't affect the original checklist.</p>
        
        <p v-if="actionError" class="error">{{ actionError }}</p>

        <form class="stack" @submit.prevent="createVersionWithEditedChecklist">
          <div class="grid">
            <div class="field">
              <label>Name</label>
              <input v-model="checklistEditorForm.name" required />
            </div>
            <div class="field">
              <label>Category</label>
              <input v-model="checklistEditorForm.category" placeholder="e.g., Automation, Security, Performance" />
            </div>
          </div>

          <div class="field">
            <label>Description</label>
            <textarea v-model="checklistEditorForm.description" rows="3" />
          </div>

          <div class="stack">
            <div class="section-header">
              <h4>Items</h4>
              <button type="button" class="btn btn-secondary btn-sm" @click="addItemToEditor">Add item</button>
            </div>

            <div class="card stack">
              <h5>Previous use cases</h5>
              <p class="muted">Pick an already existing item and add it to this checklist.</p>
              <div class="grid">
                <div class="field">
                  <label>Existing item</label>
                  <select v-model="selectedExistingItemId">
                    <option value="">Select a previous use case</option>
                    <option v-for="existingItem in availableItems" :key="existingItem.id" :value="existingItem.id">
                      {{ existingItem.title }} ({{ existingItem.priority }} / {{ existingItem.criticality }})
                    </option>
                  </select>
                </div>
                <div class="field field-end">
                  <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="!selectedExistingItemId"
                    @click="addExistingItemToEditor"
                  >
                    Add selected use case
                  </button>
                </div>
              </div>
            </div>

            <div v-for="(item, index) in checklistEditorForm.items" :key="index" class="card stack">
              <div class="grid">
                <div class="field field-relative">
                  <label>Title</label>
                  <input 
                    v-model="item.title" 
                    required
                    @input="itemSearchQueries[index] = item.title"
                    @focus="loadAvailableItems"
                    placeholder="Start typing to search existing items..."
                  />
                  <div
                    v-if="getFilteredItemsInEditor(index).length > 0 && itemSearchQueries[index]"
                    class="autocomplete-dropdown"
                  >
                    <div v-for="suggestion in getFilteredItemsInEditor(index)" 
                         :key="suggestion.id"
                         @click="selectExistingItemInEditor(index, suggestion)"
                         class="autocomplete-option">
                      <strong>{{ suggestion.title }}</strong>
                      <div class="autocomplete-desc">{{ suggestion.description || 'No description' }}</div>
                      <div class="autocomplete-meta">
                        Priority: {{ suggestion.priority }} | Criticality: {{ suggestion.criticality }}
                      </div>
                    </div>
                  </div>
                </div>
                <div class="field">
                  <label>Priority</label>
                  <select v-model="item.priority" required>
                    <option>Low</option>
                    <option>Medium</option>
                    <option>High</option>
                  </select>
                </div>
                <div class="field">
                  <label>Criticality</label>
                  <select v-model="item.criticality" required>
                    <option>Minor</option>
                    <option>Major</option>
                    <option>Critical</option>
                  </select>
                </div>
              </div>

              <div class="field">
                <label>Description</label>
                <textarea v-model="item.description" rows="2" />
              </div>

              <div class="actions">
                <button type="button" class="btn btn-danger btn-sm" @click="removeItemFromEditor(index)">Remove item</button>
              </div>
            </div>
          </div>

          <div class="actions">
            <button class="btn btn-primary" type="submit">Create execution version with custom checklist</button>
            <button type="button" class="btn btn-secondary" @click="closeChecklistEditor">Cancel</button>
          </div>
        </form>
      </div>

      <div class="card stack execution-workspace-card">
        <div class="section-header">
          <div>
            <h3>Execution workspace</h3>
            <p class="muted">Choose a version, confirm the environment, then run each test item from one place.</p>
          </div>
        </div>

        <div v-if="projectVersions.length === 0" class="empty-dashed-card execution-empty-state">
          <h4>No project version yet</h4>
          <p class="muted">The assigned tester must create an execution checklist before automated runs and item-by-item tracking become available.</p>
        </div>

        <template v-else>
          <div class="version-card-grid">
            <button
              v-for="version in projectVersions"
              :key="version.id"
              type="button"
              :class="['version-selector-card', { active: String(version.id) === String(selectedVersionId) }]"
              @click="selectVersionCard(version.id)"
            >
              <div class="version-selector-top">
                <span class="version-selector-badge">v{{ version.version_number }}</span>
                <span class="version-selector-pending">{{ versionPendingCount(version) }} pending</span>
              </div>
              <strong>{{ version.checklist?.name || 'Checklist' }}</strong>
              <p>{{ versionItemCount(version) }} executable item<span v-if="versionItemCount(version) !== 1">s</span></p>
            </button>
          </div>

          <div v-if="selectedVersion" class="execution-toolbar">
            <div class="execution-toolbar-main">
              <div class="execution-toolbar-copy">
                <span class="execution-toolbar-label">Selected version</span>
                <h4>v{{ selectedVersion.version_number }} | {{ selectedVersionChecklistName }}</h4>
                <p class="muted">
                  {{ selectedVersionItemCount }} items ready for execution, including
                  {{ selectedVersionPendingCount }} still pending.
                </p>
              </div>

              <div class="execution-environment-box">
                <span class="execution-toolbar-label">Test environment</span>
                <strong>{{ project.app_url || 'No base URL configured' }}</strong>
                <p class="muted">The Run Test action opens the environment settings before launching the automatic check.</p>
              </div>
            </div>

            <div class="execution-toolbar-actions">
              <button
                class="btn btn-primary"
                :disabled="!auth.canTest || !nextRunnableItem"
                @click="runNextSuggestedItem"
              >
                <PlayCircle :size="16" />
                <span>Run Test</span>
              </button>

              <div v-if="selectedVersionId" class="relative-wrap">
                <button
                  class="btn btn-secondary"
                  @click="openExportDropdown = openExportDropdown === 'version' ? null : 'version'"
                >
                  Export version
                </button>
                <div
                  v-if="openExportDropdown === 'version'"
                  class="card stack export-dropdown-menu"
                >
                  <button class="btn btn-secondary btn-sm" @click="exportVersion(Number(selectedVersionId), 'csv')">CSV</button>
                  <button class="btn btn-secondary btn-sm" @click="exportVersion(Number(selectedVersionId), 'pdf')">PDF</button>
                  <button class="btn btn-secondary btn-sm" @click="exportVersion(Number(selectedVersionId), 'xls')">XLS</button>
                </div>
              </div>

              <div class="relative-wrap">
                <button
                  class="btn btn-secondary"
                  @click="openExportDropdown = openExportDropdown === 'project' ? null : 'project'"
                >
                  Export project
                </button>
                <div
                  v-if="openExportDropdown === 'project'"
                  class="card stack export-dropdown-menu"
                >
                  <button class="btn btn-secondary btn-sm" @click="exportProject('csv')">CSV</button>
                  <button class="btn btn-secondary btn-sm" @click="exportProject('pdf')">PDF</button>
                  <button class="btn btn-secondary btn-sm" @click="exportProject('xls')">XLS</button>
                </div>
              </div>
            </div>
          </div>
        </template>

        <div v-if="progress" class="progress-stats-grid">
          <div class="card progress-stat">
            <div class="progress-stat-header">
              <span class="progress-stat-label">Completion</span>
              <span class="progress-stat-value text-blue-600">{{ progress.completion_percent }}%</span>
            </div>
            <div class="progress-bar">
              <div class="progress-bar-fill bg-gradient-to-r from-blue-500 to-blue-600" :style="{ width: progress.completion_percent + '%' }"></div>
            </div>
          </div>

          <div class="card progress-stat">
            <div class="progress-stat-header">
              <span class="progress-stat-label">Passed</span>
              <span class="progress-stat-value text-green-600">{{ progress.passed }}</span>
            </div>
            <div class="progress-bar">
              <div class="progress-bar-fill bg-gradient-to-r from-green-500 to-green-600" :style="{ width: (progress.passed / (progress.total || 1)) * 100 + '%' }"></div>
            </div>
          </div>

          <div class="card progress-stat">
            <div class="progress-stat-header">
              <span class="progress-stat-label">Failed</span>
              <span class="progress-stat-value text-red-600">{{ progress.failed }}</span>
            </div>
            <div class="progress-bar">
              <div class="progress-bar-fill bg-gradient-to-r from-red-500 to-red-600" :style="{ width: (progress.failed / (progress.total || 1)) * 100 + '%' }"></div>
            </div>
          </div>

          <div class="card progress-stat">
            <div class="progress-stat-header">
              <span class="progress-stat-label">Blocked</span>
              <span class="progress-stat-value text-amber-600">{{ progress.blocked }}</span>
            </div>
            <div class="progress-bar">
              <div class="progress-bar-fill bg-gradient-to-r from-amber-500 to-amber-600" :style="{ width: (progress.blocked / (progress.total || 1)) * 100 + '%' }"></div>
            </div>
          </div>

          <div class="card progress-stat">
            <div class="progress-stat-header">
              <span class="progress-stat-label">Not Tested</span>
              <span class="progress-stat-value text-gray-600">{{ progress.not_tested }}</span>
            </div>
            <div class="progress-bar">
              <div class="progress-bar-fill bg-gradient-to-r from-gray-500 to-gray-600" :style="{ width: (progress.not_tested / (progress.total || 1)) * 100 + '%' }"></div>
            </div>
          </div>
        </div>

        <p v-if="actionError" class="error">{{ actionError }}</p>
        <p v-if="actionSuccess" class="success">{{ actionSuccess }}</p>

        <div v-if="selectedVersion" class="execution-table-header">
          <div>
            <h4>Executable checklist items</h4>
            <p class="muted">Each item can be run automatically, then updated with status, comments, and traceability.</p>
          </div>
        </div>

        <div class="table-wrap execution-table-wrap" v-if="selectedVersion">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Title</th>
                <th>Priority</th>
                <th>Criticality</th>
                <th>Status</th>
                <th>Automated test</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="item in selectedVersion.items" :key="item.id">
                <tr>
                  <td>{{ item.order + 1 }}</td>
                  <td>
                    <div class="item-title-cell">
                      <span class="item-case-badge">TC-{{ String(item.order + 1).padStart(2, '0') }}</span>
                      <strong class="item-title-link" @click="selectedItemId === item.id ? (selectedItemId = null) : (selectedItemId = item.id, loadComments(item.id))">
                        {{ item.title }}
                      </strong>
                    </div>
                    <div class="muted">{{ item.description || '-' }}</div>
                  </td>
                  <td>{{ item.priority }}</td>
                  <td>{{ item.criticality }}</td>
                  <td>
                    <select
                      class="status-select"
                      :disabled="!auth.canTest"
                      :value="displayStatus(item.status)"
                      @change="updateItemStatus(item.id, $event.target.value)"
                    >
                      <option>Pending</option>
                      <option>Passed</option>
                      <option>Failed</option>
                      <option>Blocked</option>
                    </select>
                  </td>
                  <td>
                    <div class="stack run-actions-stack">
                      <span :class="executionStateClass(stateForItem(item).execution_state)">
                        {{ executionStateLabel(stateForItem(item).execution_state) }}
                      </span>

                      <button class="btn btn-primary btn-sm run-test-inline-btn" :disabled="!auth.canTest || isRunInFlight(item)" @click="openRunModal(item)">
                        <PlayCircle :size="14" />
                        <span>{{ runButtonLabel(item) }} Test</span>
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
                    </div>
                  </td>
                </tr>
                <tr v-if="selectedItemId === item.id">
                  <td colspan="7" class="detail-row-cell">
                    <div class="card stack">
                      <div class="tabs-row">
                        <button 
                          :class="['btn btn-secondary btn-sm', { 'btn-primary': !showHistory }]" 
                          class="btn-icon-tight"
                          @click="showHistory = false; loadComments(item.id)"
                        >
                          <MessageCircle :size="16" />
                          <span>Comments ({{ comments.length }})</span>
                        </button>
                        <button 
                          :class="['btn btn-secondary btn-sm', { 'btn-primary': showHistory }]" 
                          class="btn-icon-tight"
                          @click="showHistory = true; loadHistory(item.id)"
                        >
                          <ClipboardList :size="16" />
                          <span>Timeline ({{ itemHistory.total_events }})</span>
                        </button>
                      </div>

                      <!-- COMMENTS TAB -->
                      <div v-if="!showHistory" class="stack">
                        <h4 class="subsection-title">Comments</h4>
                        <div v-if="commentsLoading" class="muted">Loading comments...</div>
                        <div v-else class="stack">
                          <div v-if="comments.length === 0" class="muted">No comments yet.</div>
                          <div v-for="comment in comments" :key="comment.id" class="comment-item">
                            <div class="comment-head-row">
                              <strong>{{ comment.user?.name || 'Anonymous' }}</strong>
                              <button v-if="comment.user_id === auth.user.id || auth.isAdmin" class="btn btn-danger btn-sm" @click="deleteComment(comment.id, item.id)">Delete</button>
                            </div>
                            <p class="muted comment-time">{{ new Date(comment.created_at).toLocaleString() }}</p>
                            <p>{{ comment.content }}</p>
                          </div>
                        </div>

                        <div class="field">
                          <textarea v-model="newComment" placeholder="Add a comment..." rows="3" />
                        </div>
                        
                        <div v-if="auth.isTester" class="field">
                          <label>Attach File (for testeurs)</label>
                          <input 
                            ref="commentFileInput" 
                            type="file" 
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv"
                            @change="onFileSelected"
                          />
                          <p v-if="newCommentFile" class="muted attachment-text">
                            <span class="attachment-pill">
                              <Paperclip :size="14" />
                              <span>{{ newCommentFile.name }} ({{ (newCommentFile.size / 1024).toFixed(2) }} KB)</span>
                            </span>
                          </p>
                        </div>
                        
                        <button class="btn btn-primary" @click="addComment(item.id)">Add comment</button>
                      </div>

                      <!-- CHANGE HISTORY TAB -->
                      <div v-if="showHistory" class="stack">
                        <h4 class="subsection-title">Complete History & Traceability</h4>
                        
                        <!-- Testing Info Summary -->
                        <div v-if="itemHistory.tested_by || itemHistory.tested_at" class="history-tested-box">
                          <div class="history-tested-title">
                            <CheckCircle2 :size="16" />
                            <span>Tested</span>
                          </div>
                          <div v-if="itemHistory.tested_by" class="history-tested-line">
                            <strong>Tested by:</strong> {{ itemHistory.tested_by.name }}
                          </div>
                          <div v-if="itemHistory.tested_at" class="history-tested-line">
                            <strong>Tested at:</strong> {{ new Date(itemHistory.tested_at).toLocaleString() }}
                          </div>
                          <div class="history-tested-status-line">
                            <strong>Status:</strong> <span class="history-tested-status-pill">{{ itemHistory.current_status }}</span>
                          </div>
                        </div>

                        <!-- Unified Timeline -->
                        <div v-if="historyLoading" class="muted">Loading history...</div>
                        <div v-else>
                          <div v-if="itemHistory.timeline.length === 0" class="muted">No history events recorded.</div>
                          <div v-else>
                            <div v-for="event in itemHistory.timeline" :key="event.id" class="timeline-event" :style="getEventBorderColor(event.type)">
                              
                              <!-- Status Change Event -->
                              <template v-if="event.type === 'change'">
                                <div class="timeline-event-head">
                                  <div>
                                    <strong class="timeline-event-title">
                                      <FilePenLine :size="16" />
                                      <span>Status Changed</span>
                                    </strong>
                                    <div class="muted timeline-event-meta">
                                      {{ event.field_name }}: <strong>{{ event.old_value }}</strong> → <strong>{{ event.new_value }}</strong>
                                    </div>
                                  </div>
                                  <small class="muted">{{ new Date(event.timestamp).toLocaleString() }}</small>
                                </div>
                                <div class="timeline-meta-block">
                                  <small class="muted">Changed by: <strong>{{ event.user_name }}</strong></small>
                                </div>
                                <div v-if="event.old_value || event.new_value" class="timeline-change-grid">
                                  <div v-if="event.old_value" class="timeline-change-col">
                                    <small class="timeline-old-label">Previous</small>
                                    <div class="timeline-old-value">{{ event.old_value }}</div>
                                  </div>
                                  <div v-if="event.new_value" class="timeline-change-col">
                                    <small class="timeline-new-label">New</small>
                                    <div class="timeline-new-value">{{ event.new_value }}</div>
                                  </div>
                                </div>
                              </template>

                              <!-- Comment Event -->
                              <template v-if="event.type === 'comment'">
                                <div class="timeline-event-head">
                                  <div>
                                    <strong class="timeline-event-title">
                                      <MessageCircle :size="16" />
                                      <span>Comment Added</span>
                                    </strong>
                                    <div class="muted timeline-event-meta">
                                      By: <strong>{{ event.user_name }}</strong>
                                    </div>
                                  </div>
                                  <small class="muted">{{ new Date(event.timestamp).toLocaleString() }}</small>
                                </div>
                                <p class="timeline-comment">{{ event.content }}</p>
                                <div v-if="event.file_name" class="timeline-file-box">
                                  <Paperclip :size="14" />
                                  <strong>{{ event.file_name }}</strong>
                                  <span class="muted">({{ event.file_size ? (event.file_size / 1024).toFixed(2) : '?' }} KB)</span>
                                </div>
                              </template>

                              <!-- Test Event -->
                              <template v-if="event.type === 'test'">
                                <div class="timeline-event-head">
                                  <div>
                                    <strong class="timeline-event-title">
                                      <CheckCircle2 :size="16" />
                                      <span>Item Tested</span>
                                    </strong>
                                    <div class="muted timeline-event-meta">
                                      Result: <strong class="timeline-result-pill">{{ event.status }}</strong>
                                    </div>
                                  </div>
                                  <small class="muted">{{ new Date(event.timestamp).toLocaleString() }}</small>
                                </div>
                                <div class="timeline-meta-block">
                                  <small class="muted">Tested by: <strong>{{ event.user_name }}</strong></small>
                                </div>
                              </template>

                              <!-- Execution Failure Event -->
                              <template v-if="event.type === 'execution'">
                                <div class="timeline-event-head">
                                  <div>
                                    <strong class="timeline-event-title">
                                      <ClipboardList :size="16" />
                                      <span>Execution Failed</span>
                                    </strong>
                                    <div class="muted timeline-event-meta">
                                      <span v-if="event.run_id">Run: <strong>{{ event.run_id }}</strong></span>
                                      <span v-if="event.error_type"> • Type: <strong>{{ event.error_type }}</strong></span>
                                    </div>
                                  </div>
                                  <small class="muted">{{ new Date(event.timestamp).toLocaleString() }}</small>
                                </div>

                                <p class="timeline-execution-error">
                                  {{ event.error_message || 'Execution failed without an explicit error message.' }}
                                </p>

                                <div class="timeline-meta-block">
                                  <small class="muted" v-if="event.user_name">Requested by: <strong>{{ event.user_name }}</strong></small>
                                </div>
                              </template>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <div
        v-if="runModalOpen"
        class="run-modal-overlay"
      >
        <div class="card stack run-modal-card">
          <div class="section-header section-header-tight">
            <h3>Run Test Case</h3>
          </div>

          <p class="muted modal-intro">
            Provide target website info for this run.
          </p>

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
    </template>
  </section>
</template>

<style>
.project-execution-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.95fr);
  gap: 1.5rem;
  padding: 1.6rem 1.7rem;
  border-radius: 1.6rem;
  background:
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.16), transparent 14rem),
    radial-gradient(circle at bottom left, rgba(16, 185, 129, 0.12), transparent 14rem),
    linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.2);
  box-shadow: 0 22px 40px -34px rgba(15, 23, 42, 0.38);
}

.project-execution-kicker {
  margin: 0 0 0.55rem;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: #0f766e;
}

.project-execution-copy h1 {
  margin: 0;
  font-size: clamp(2rem, 3vw, 2.9rem);
  line-height: 1;
  letter-spacing: -0.05em;
}

.project-execution-subtitle {
  max-width: 56rem;
  margin: 0.85rem 0 1.15rem;
  font-size: 1rem;
  line-height: 1.7;
  color: #475569;
}

.project-meta-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 0.7rem;
}

.project-meta-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.6rem 0.85rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.82);
  border: 1px solid rgba(148, 163, 184, 0.22);
  color: #0f172a;
  font-weight: 600;
}

.project-execution-side {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.project-execution-app-card {
  padding: 1rem 1.1rem;
  border-radius: 1.2rem;
  background: rgba(255, 255, 255, 0.78);
  border: 1px solid rgba(148, 163, 184, 0.18);
}

.project-execution-side-label,
.execution-toolbar-label {
  display: block;
  margin-bottom: 0.45rem;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #64748b;
}

.project-app-link {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-weight: 700;
  color: #0f172a;
  text-decoration: none;
  word-break: break-word;
}

.project-app-link:hover {
  color: #0f766e;
}

.project-execution-hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.project-execution-hero-actions .btn,
.execution-toolbar-actions .btn,
.run-test-inline-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
}

.project-create-version-card {
  border-color: rgba(14, 165, 233, 0.18);
  background: linear-gradient(180deg, rgba(248, 250, 252, 0.92), rgba(255, 255, 255, 0.98));
}

.execution-workspace-card {
  gap: 1.35rem;
}

.execution-empty-state {
  text-align: left;
}

.execution-empty-state h4 {
  margin: 0 0 0.35rem;
}

.version-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.95rem;
}

.version-selector-card {
  text-align: left;
  padding: 1rem 1.05rem;
  border-radius: 1.15rem;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: linear-gradient(180deg, #ffffff, #f8fafc);
  box-shadow: 0 18px 32px -28px rgba(15, 23, 42, 0.3);
  transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
}

.version-selector-card:hover {
  transform: translateY(-2px);
  border-color: rgba(14, 165, 233, 0.35);
  box-shadow: 0 22px 36px -28px rgba(14, 165, 233, 0.28);
}

.version-selector-card.active {
  border-color: rgba(14, 165, 233, 0.55);
  background:
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.12), transparent 7rem),
    linear-gradient(180deg, #f8fdff, #ffffff);
  box-shadow: 0 24px 40px -28px rgba(14, 165, 233, 0.28);
}

.version-selector-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.7rem;
}

.version-selector-badge,
.item-case-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.3rem 0.6rem;
  border-radius: 999px;
  background: #e0f2fe;
  color: #075985;
  font-size: 0.76rem;
  font-weight: 800;
  letter-spacing: 0.05em;
}

.version-selector-pending {
  font-size: 0.84rem;
  color: #475569;
  font-weight: 600;
}

.version-selector-card strong {
  display: block;
  margin-bottom: 0.35rem;
  font-size: 1rem;
  color: #0f172a;
}

.version-selector-card p {
  margin: 0;
  color: #64748b;
  line-height: 1.5;
}

.execution-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) auto;
  gap: 1rem;
  align-items: stretch;
  padding: 1rem;
  border-radius: 1.25rem;
  background: linear-gradient(180deg, rgba(248, 250, 252, 0.95), rgba(241, 245, 249, 0.92));
  border: 1px solid rgba(148, 163, 184, 0.2);
}

.execution-toolbar-main {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(260px, 0.9fr);
  gap: 1rem;
}

.execution-toolbar-copy h4 {
  margin: 0;
  font-size: 1.2rem;
}

.execution-environment-box {
  padding: 0.95rem 1rem;
  border-radius: 1rem;
  background: rgba(255, 255, 255, 0.78);
  border: 1px solid rgba(148, 163, 184, 0.16);
}

.execution-environment-box strong {
  display: block;
  margin-bottom: 0.3rem;
  color: #0f172a;
  word-break: break-word;
}

.execution-toolbar-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.75rem;
}

.execution-table-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}

.execution-table-header h4 {
  margin: 0;
}

.execution-table-wrap {
  overflow: visible;
}

.item-title-cell {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.55rem;
  margin-bottom: 0.35rem;
}

.run-test-inline-btn {
  min-width: 8.4rem;
}

.dark .project-execution-hero {
  background:
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.16), transparent 14rem),
    radial-gradient(circle at bottom left, rgba(16, 185, 129, 0.08), transparent 14rem),
    linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(17, 24, 39, 0.98));
  border-color: rgba(51, 65, 85, 0.8);
}

.dark .project-execution-subtitle,
.dark .version-selector-pending,
.dark .version-selector-card p,
.dark .project-execution-side-label,
.dark .execution-toolbar-label {
  color: #94a3b8;
}

.dark .project-meta-pill,
.dark .project-execution-app-card,
.dark .execution-environment-box,
.dark .version-selector-card,
.dark .execution-toolbar {
  background: rgba(15, 23, 42, 0.88);
  border-color: rgba(51, 65, 85, 0.9);
  color: #e2e8f0;
}

.dark .project-app-link,
.dark .version-selector-card strong,
.dark .execution-environment-box strong,
.dark .execution-toolbar-copy h4,
.dark .item-case-badge {
  color: #f8fafc;
}

.dark .version-selector-badge,
.dark .item-case-badge {
  background: rgba(14, 165, 233, 0.18);
  color: #bae6fd;
}

.dark .version-selector-card.active {
  background:
    radial-gradient(circle at top right, rgba(14, 165, 233, 0.14), transparent 8rem),
    rgba(15, 23, 42, 0.94);
}

@media (max-width: 960px) {
  .project-execution-hero,
  .execution-toolbar,
  .execution-toolbar-main {
    grid-template-columns: 1fr;
  }

  .execution-toolbar-actions {
    justify-content: flex-start;
  }
}
</style>
