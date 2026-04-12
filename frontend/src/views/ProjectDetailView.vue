<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { CheckCircle2, ClipboardList, FilePenLine, MessageCircle, Paperclip } from 'lucide-vue-next'
import { API_BASE_URL, apiRequest, ensureCsrfCookie } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

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
      selectedVersionId.value = String(data.versions[0].id)
      await loadProgress(data.versions[0].id)
      seedRuntimeStateFromVersion(data.versions[0])
    } else {
      selectedVersionId.value = ''
      progress.value = null
    }
  } catch (error) {
    pageError.value = error.data?.message || error.message
  } finally {
    loading.value = false
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
  if (!auth.canManageProjects) {
    return
  }

  try {
    const data = await apiRequest('/checklists', {}, auth.token)
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
  if (!confirm('Delete this comment?')) {
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
  if (auth.canManageChecklists) {
    await Promise.all([loadProject(), loadChecklists(), loadAvailableItems()])
    return
  }

  await Promise.all([loadProject(), loadChecklists()])
})
</script>

<template>
  <section class="page stack">
    <div class="section-header">
      <h1>Project details</h1>
    </div>

    <p v-if="pageError" class="error">{{ pageError }}</p>
    <p v-if="loading" class="muted">Loading project...</p>

    <template v-if="project && !loading">
      <div class="card stack">
        <h2>{{ project.name }}</h2>
        <p class="muted">{{ project.description || 'No description' }}</p>
        <p class="muted">
          App URL:
          <a v-if="project.app_url" :href="project.app_url" target="_blank" rel="noopener noreferrer">{{ project.app_url }}</a>
          <span v-else>-</span>
        </p>
        <p class="muted">Created by: {{ project.creator?.name || '-' }}</p>
      </div>

      <div v-if="auth.canManageProjects" class="card stack">
        <h3>Create new version</h3>
        <form class="grid" @submit.prevent="createVersion">
          <div class="field">
            <label>Checklist</label>
            <select v-model="versionForm.checklist_id" required>
              <option disabled value="">Select checklist</option>
              <option v-for="checklist in checklists" :key="checklist.id" :value="checklist.id">
                {{ checklist.name }}
              </option>
            </select>
          </div>
          <div class="actions" style="align-items: end; gap: 0.5rem;">
            <button class="btn btn-primary" type="submit">Create version</button>
            <button 
              type="button"
              class="btn btn-secondary"
              :disabled="!versionForm.checklist_id"
              @click="openChecklistEditor(Number(versionForm.checklist_id))"
            >
              Edit Checklist
            </button>
          </div>
        </form>
      </div>

      <!-- Inline Checklist Editor Modal -->
      <div v-if="showChecklistEditor" class="card stack" style="border: 2px dashed #007bff; background-color: #f8f9fa;">
        <h3>Edit Checklist for New Version</h3>
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
                <div class="field" style="align-self: end;">
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
                <div class="field" style="position: relative;">
                  <label>Title</label>
                  <input 
                    v-model="item.title" 
                    required
                    @input="itemSearchQueries[index] = item.title"
                    @focus="loadAvailableItems"
                    placeholder="Start typing to search existing items..."
                  />
                  <div v-if="getFilteredItemsInEditor(index).length > 0 && itemSearchQueries[index]" 
                       class="autocomplete-dropdown"
                       style="
                         position: absolute;
                         top: 100%;
                         left: 0;
                         right: 0;
                         background: white;
                         border: 1px solid #ddd;
                         border-top: none;
                         max-height: 200px;
                         overflow-y: auto;
                         z-index: 10;
                       ">
                    <div v-for="suggestion in getFilteredItemsInEditor(index)" 
                         :key="suggestion.id"
                         @click="selectExistingItemInEditor(index, suggestion)"
                         style="
                           padding: 8px 12px;
                           cursor: pointer;
                           border-bottom: 1px solid #eee;
                         "
                         @mouseenter="$event.target.style.backgroundColor = '#f0f0f0'"
                         @mouseleave="$event.target.style.backgroundColor = 'white'">
                      <strong>{{ suggestion.title }}</strong>
                      <div style="font-size: 0.85em; color: #666;">{{ suggestion.description || 'No description' }}</div>
                      <div style="font-size: 0.8em; color: #999;">
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
            <button class="btn btn-primary" type="submit">Create version with custom checklist</button>
            <button type="button" class="btn btn-secondary" @click="closeChecklistEditor">Cancel</button>
          </div>
        </form>
      </div>

      <div class="card stack">
        <div class="grid">
          <div class="field">
            <label>Version</label>
            <select v-model="selectedVersionId">
              <option v-for="version in project.versions" :key="version.id" :value="version.id">
                v{{ version.version_number }} - {{ version.checklist?.name || 'Checklist' }}
              </option>
            </select>
          </div>
          <div style="align-items: end; display: flex; gap: 0.5rem">
            <div v-if="selectedVersionId" style="position: relative">
              <button
                class="btn btn-secondary btn-sm"
                @click="openExportDropdown = openExportDropdown === 'version' ? null : 'version'"
              >
                Export Version
              </button>
              <div
                v-if="openExportDropdown === 'version'"
                class="card stack"
                style="position: absolute; right: 0; top: calc(100% + 0.4rem); z-index: 20; min-width: 120px; padding: 0.4rem"
              >
                <button class="btn btn-secondary btn-sm" @click="exportVersion(Number(selectedVersionId), 'csv')">CSV</button>
                <button class="btn btn-secondary btn-sm" @click="exportVersion(Number(selectedVersionId), 'pdf')">PDF</button>
                <button class="btn btn-secondary btn-sm" @click="exportVersion(Number(selectedVersionId), 'xls')">XLS</button>
              </div>
            </div>

            <div style="position: relative">
              <button
                class="btn btn-secondary btn-sm"
                @click="openExportDropdown = openExportDropdown === 'project' ? null : 'project'"
              >
                Export Project
              </button>
              <div
                v-if="openExportDropdown === 'project'"
                class="card stack"
                style="position: absolute; right: 0; top: calc(100% + 0.4rem); z-index: 20; min-width: 120px; padding: 0.4rem"
              >
                <button class="btn btn-secondary btn-sm" @click="exportProject('csv')">CSV</button>
                <button class="btn btn-secondary btn-sm" @click="exportProject('pdf')">PDF</button>
                <button class="btn btn-secondary btn-sm" @click="exportProject('xls')">XLS</button>
              </div>
            </div>
          </div>
        </div>

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

        <div class="table-wrap" v-if="selectedVersion">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Title</th>
                <th>Priority</th>
                <th>Criticality</th>
                <th>Status</th>
                <th>Run</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="item in selectedVersion.items" :key="item.id">
                <tr>
                  <td>{{ item.order + 1 }}</td>
                  <td>
                    <strong style="cursor: pointer; color: #2563eb" @click="selectedItemId === item.id ? (selectedItemId = null) : (selectedItemId = item.id, loadComments(item.id))">
                      {{ item.title }}
                    </strong>
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
                    <div class="stack" style="gap: 0.4rem">
                      <span :class="executionStateClass(stateForItem(item).execution_state)">
                        {{ executionStateLabel(stateForItem(item).execution_state) }}
                      </span>

                      <button class="btn btn-secondary btn-sm" :disabled="!auth.canTest || isRunInFlight(item)" @click="openRunModal(item)">
                        {{ runButtonLabel(item) }}
                      </button>

                      <a
                        v-if="getArtifactUrl(item)"
                        :href="getArtifactUrl(item)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="muted"
                        style="font-size: 0.8rem; text-decoration: underline"
                      >
                        View artifacts
                      </a>

                      <span v-if="stateForItem(item).last_error_message" class="error" style="padding: 0.5rem; font-size: 0.75rem; margin: 0">
                        {{ stateForItem(item).last_error_message }}
                      </span>

                      <details v-if="stateForItem(item).execution_trace?.length" style="font-size: 0.78rem">
                        <summary class="muted" style="cursor: pointer">Execution trace ({{ stateForItem(item).execution_trace.length }})</summary>
                        <ol style="margin: 0.35rem 0 0; padding-left: 1.1rem; max-height: 12rem; overflow: auto">
                          <li v-for="(line, index) in stateForItem(item).execution_trace" :key="`${item.id}-trace-${index}`" style="margin-bottom: 0.2rem">
                            {{ line }}
                          </li>
                        </ol>
                      </details>
                    </div>
                  </td>
                </tr>
                <tr v-if="selectedItemId === item.id">
                  <td colspan="7" style="padding: 1.5rem">
                    <div class="card stack">
                      <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 2px solid #e5e7eb; padding-bottom: 0;">
                        <button 
                          :class="['btn btn-secondary btn-sm', { 'btn-primary': !showHistory }]" 
                          style="display: inline-flex; align-items: center; gap: 0.35rem;"
                          @click="showHistory = false; loadComments(item.id)"
                        >
                          <MessageCircle :size="16" />
                          <span>Comments ({{ comments.length }})</span>
                        </button>
                        <button 
                          :class="['btn btn-secondary btn-sm', { 'btn-primary': showHistory }]" 
                          style="display: inline-flex; align-items: center; gap: 0.35rem;"
                          @click="showHistory = true; loadHistory(item.id)"
                        >
                          <ClipboardList :size="16" />
                          <span>Timeline ({{ itemHistory.total_events }})</span>
                        </button>
                      </div>

                      <!-- COMMENTS TAB -->
                      <div v-if="!showHistory" class="stack">
                        <h4 style="margin: 0 0 1rem 0">Comments</h4>
                        <div v-if="commentsLoading" class="muted">Loading comments...</div>
                        <div v-else class="stack">
                          <div v-if="comments.length === 0" class="muted">No comments yet.</div>
                          <div v-for="comment in comments" :key="comment.id" class="comment-item">
                            <div style="display: flex; justify-content: space-between; align-items: center">
                              <strong>{{ comment.user?.name || 'Anonymous' }}</strong>
                              <button v-if="comment.user_id === auth.user.id || auth.isAdmin" class="btn btn-danger btn-sm" @click="deleteComment(comment.id, item.id)">Delete</button>
                            </div>
                            <p class="muted" style="font-size: 0.85rem; margin: 0.25rem 0">{{ new Date(comment.created_at).toLocaleString() }}</p>
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
                          <p v-if="newCommentFile" class="muted" style="margin-top: 0.5rem; font-size: 0.9rem">
                            <span style="display: inline-flex; align-items: center; gap: 0.35rem;">
                              <Paperclip :size="14" />
                              <span>{{ newCommentFile.name }} ({{ (newCommentFile.size / 1024).toFixed(2) }} KB)</span>
                            </span>
                          </p>
                        </div>
                        
                        <button class="btn btn-primary" @click="addComment(item.id)">Add comment</button>
                      </div>

                      <!-- CHANGE HISTORY TAB -->
                      <div v-if="showHistory" class="stack">
                        <h4 style="margin: 0 0 1rem 0">Complete History & Traceability</h4>
                        
                        <!-- Testing Info Summary -->
                        <div v-if="itemHistory.tested_by || itemHistory.tested_at" style="background: #f3f4f6; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; border-left: 4px solid #059669;">
                          <div style="font-weight: 600; color: #047857; margin-bottom: 0.5rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                            <CheckCircle2 :size="16" />
                            <span>Tested</span>
                          </div>
                          <div v-if="itemHistory.tested_by" style="font-size: 0.9rem; margin-bottom: 0.25rem;">
                            <strong>Tested by:</strong> {{ itemHistory.tested_by.name }}
                          </div>
                          <div v-if="itemHistory.tested_at" style="font-size: 0.9rem;">
                            <strong>Tested at:</strong> {{ new Date(itemHistory.tested_at).toLocaleString() }}
                          </div>
                          <div style="font-size: 0.9rem; margin-top: 0.5rem;">
                            <strong>Status:</strong> <span style="background: #dcfce7; padding: 0.25rem 0.5rem; border-radius: 0.25rem; color: #15803d;">{{ itemHistory.current_status }}</span>
                          </div>
                        </div>

                        <!-- Unified Timeline -->
                        <div v-if="historyLoading" class="muted">Loading history...</div>
                        <div v-else>
                          <div v-if="itemHistory.timeline.length === 0" class="muted">No history events recorded.</div>
                          <div v-else>
                            <div v-for="event in itemHistory.timeline" :key="event.id" style="padding: 1rem; margin-bottom: 0.75rem; border-radius: 0.5rem; border-left: 4px solid;" :style="getEventBorderColor(event.type)">
                              
                              <!-- Status Change Event -->
                              <template v-if="event.type === 'change'">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                  <div>
                                    <strong style="font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                                      <FilePenLine :size="16" />
                                      <span>Status Changed</span>
                                    </strong>
                                    <div class="muted" style="font-size: 0.85rem; margin-top: 0.25rem;">
                                      {{ event.field_name }}: <strong>{{ event.old_value }}</strong> → <strong>{{ event.new_value }}</strong>
                                    </div>
                                  </div>
                                  <small class="muted">{{ new Date(event.timestamp).toLocaleString() }}</small>
                                </div>
                                <div style="margin-top: 0.5rem;">
                                  <small class="muted">Changed by: <strong>{{ event.user_name }}</strong></small>
                                </div>
                                <div v-if="event.old_value || event.new_value" style="background: white; padding: 0.75rem; border-radius: 0.375rem; margin-top: 0.5rem; display: flex; gap: 1rem;">
                                  <div v-if="event.old_value" style="flex: 1;">
                                    <small style="color: #991b1b; font-weight: 500;">Previous</small>
                                    <div style="font-family: monospace; background: #fee2e2; padding: 0.5rem; border-radius: 0.25rem; color: #991b1b; font-size: 0.85rem;">{{ event.old_value }}</div>
                                  </div>
                                  <div v-if="event.new_value" style="flex: 1;">
                                    <small style="color: #15803d; font-weight: 500;">New</small>
                                    <div style="font-family: monospace; background: #dcfce7; padding: 0.5rem; border-radius: 0.25rem; color: #15803d; font-size: 0.85rem;">{{ event.new_value }}</div>
                                  </div>
                                </div>
                              </template>

                              <!-- Comment Event -->
                              <template v-if="event.type === 'comment'">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                  <div>
                                    <strong style="font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                                      <MessageCircle :size="16" />
                                      <span>Comment Added</span>
                                    </strong>
                                    <div class="muted" style="font-size: 0.85rem; margin-top: 0.25rem;">
                                      By: <strong>{{ event.user_name }}</strong>
                                    </div>
                                  </div>
                                  <small class="muted">{{ new Date(event.timestamp).toLocaleString() }}</small>
                                </div>
                                <p style="margin: 0.75rem 0; padding: 0.75rem; background: white; border-radius: 0.375rem; border-left: 2px solid #7c3aed;">{{ event.content }}</p>
                                <div v-if="event.file_name" style="padding: 0.5rem; background: #f3f4f6; border-radius: 0.25rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;">
                                  <Paperclip :size="14" />
                                  <strong>{{ event.file_name }}</strong>
                                  <span class="muted">({{ event.file_size ? (event.file_size / 1024).toFixed(2) : '?' }} KB)</span>
                                </div>
                              </template>

                              <!-- Test Event -->
                              <template v-if="event.type === 'test'">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                  <div>
                                    <strong style="font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                                      <CheckCircle2 :size="16" />
                                      <span>Item Tested</span>
                                    </strong>
                                    <div class="muted" style="font-size: 0.85rem; margin-top: 0.25rem;">
                                      Result: <strong style="padding: 0.25rem 0.5rem; border-radius: 0.25rem; background: #dcfce7; color: #15803d;">{{ event.status }}</strong>
                                    </div>
                                  </div>
                                  <small class="muted">{{ new Date(event.timestamp).toLocaleString() }}</small>
                                </div>
                                <div style="margin-top: 0.5rem;">
                                  <small class="muted">Tested by: <strong>{{ event.user_name }}</strong></small>
                                </div>
                              </template>

                              <!-- Execution Failure Event -->
                              <template v-if="event.type === 'execution'">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                  <div>
                                    <strong style="font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                                      <ClipboardList :size="16" />
                                      <span>Execution Failed</span>
                                    </strong>
                                    <div class="muted" style="font-size: 0.85rem; margin-top: 0.25rem;">
                                      <span v-if="event.run_id">Run: <strong>{{ event.run_id }}</strong></span>
                                      <span v-if="event.error_type"> • Type: <strong>{{ event.error_type }}</strong></span>
                                    </div>
                                  </div>
                                  <small class="muted">{{ new Date(event.timestamp).toLocaleString() }}</small>
                                </div>

                                <p style="margin: 0.75rem 0; padding: 0.75rem; background: #fff; border-radius: 0.375rem; border-left: 2px solid #ea580c; white-space: pre-wrap;">
                                  {{ event.error_message || 'Execution failed without an explicit error message.' }}
                                </p>

                                <div style="margin-top: 0.5rem;">
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
        style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); z-index: 60; display: grid; place-items: center; padding: 1rem"
      >
        <div class="card stack" style="width: min(620px, 96vw)">
          <div class="section-header" style="margin-bottom: 0">
            <h3>Run Test Case</h3>
          </div>

          <p class="muted" style="margin-top: 0">
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

              <div class="field" style="justify-content: end">
                <label style="text-transform: none; letter-spacing: normal; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 0.5rem">
                  <input type="checkbox" v-model="runForm.useAuth" :disabled="runSubmitBusy" />
                  <span>Use authenticated flow</span>
                </label>
                <p class="muted" style="margin: 0; font-size: 0.8rem">Uncheck to run unauthenticated.</p>

                <label style="margin-top: 0.5rem; text-transform: none; letter-spacing: normal; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 0.5rem">
                  <input type="checkbox" v-model="runForm.watchMode" :disabled="runSubmitBusy" />
                  <span>Watch mode (open browser window)</span>
                </label>
                <p class="muted" style="margin: 0; font-size: 0.8rem">Keep enabled to watch the test live in an external browser.</p>
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