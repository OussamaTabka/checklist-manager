<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { apiRequest } from '@/lib/api'
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
const selectedItemId = ref(null)
const comments = ref([])
const commentsLoading = ref(false)
const newComment = ref('')
const versionForm = reactive({
  checklist_id: '',
})

const selectedVersion = computed(() => {
  if (!project.value) {
    return null
  }

  const targetId = Number(selectedVersionId.value)
  return project.value.versions.find((version) => version.id === targetId) || null
})

async function loadProject() {
  loading.value = true
  pageError.value = ''

  try {
    const data = await apiRequest(`/projects/${route.params.id}`, {}, auth.token)
    project.value = data

    if (data.versions?.length) {
      selectedVersionId.value = String(data.versions[0].id)
      await loadProgress(data.versions[0].id)
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

async function addComment(itemId) {
  if (!newComment.value.trim()) {
    return
  }

  actionError.value = ''
  actionSuccess.value = ''

  try {
    await apiRequest(
      `/version-items/${itemId}/comments`,
      {
        method: 'POST',
        body: { content: newComment.value },
      },
      auth.token,
    )

    newComment.value = ''
    actionSuccess.value = 'Comment added successfully.'
    await loadComments(itemId)
  } catch (error) {
    actionError.value = error.data?.message || error.message
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

async function exportVersion(versionId) {
  actionError.value = ''
  actionSuccess.value = ''

  try {
    const data = await apiRequest(`/project-versions/${versionId}/export`, {}, auth.token)
    downloadJSON(data, `version-${versionId}-export.json`)
    actionSuccess.value = 'Version exported successfully.'
  } catch (error) {
    actionError.value = error.data?.message || error.message
  }
}

async function exportProject() {
  actionError.value = ''
  actionSuccess.value = ''

  try {
    const data = await apiRequest(`/projects/${route.params.id}/export`, {}, auth.token)
    downloadJSON(data, `project-${route.params.id}-export.json`)
    actionSuccess.value = 'Project exported successfully.'
  } catch (error) {
    actionError.value = error.data?.message || error.message
  }
}

function downloadJSON(data, filename) {
  const jsonString = JSON.stringify(data, null, 2)
  const blob = new Blob([jsonString], { type: 'application/json' })
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
  }
})

onMounted(async () => {
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
          <div class="actions" style="align-items: end">
            <button class="btn btn-primary" type="submit">Create version</button>
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
            <button v-if="selectedVersionId" class="btn btn-secondary btn-sm" @click="exportVersion(Number(selectedVersionId))">Export Version</button>
            <button class="btn btn-secondary btn-sm" @click="exportProject">Export Project</button>
          </div>
        </div>

        <div v-if="progress" class="grid">
          <div class="card">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-bold uppercase tracking-wider text-gray-700">Completion</span>
              <span class="text-2xl font-bold text-blue-600">{{ progress.completion_percent }}%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
              <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-full transition-all duration-300" :style="{ width: progress.completion_percent + '%' }"></div>
            </div>
          </div>

          <div class="card">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-bold uppercase tracking-wider text-gray-700">Passed</span>
              <span class="text-2xl font-bold text-green-600">{{ progress.passed }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
              <div class="bg-gradient-to-r from-green-500 to-green-600 h-full transition-all duration-300" :style="{ width: (progress.passed / (progress.total || 1)) * 100 + '%' }"></div>
            </div>
          </div>

          <div class="card">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-bold uppercase tracking-wider text-gray-700">Failed</span>
              <span class="text-2xl font-bold text-red-600">{{ progress.failed }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
              <div class="bg-gradient-to-r from-red-500 to-red-600 h-full transition-all duration-300" :style="{ width: (progress.failed / (progress.total || 1)) * 100 + '%' }"></div>
            </div>
          </div>

          <div class="card">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-bold uppercase tracking-wider text-gray-700">Blocked</span>
              <span class="text-2xl font-bold text-amber-600">{{ progress.blocked }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
              <div class="bg-gradient-to-r from-amber-500 to-amber-600 h-full transition-all duration-300" :style="{ width: (progress.blocked / (progress.total || 1)) * 100 + '%' }"></div>
            </div>
          </div>

          <div class="card">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-bold uppercase tracking-wider text-gray-700">Not Tested</span>
              <span class="text-2xl font-bold text-gray-600">{{ progress.not_tested }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
              <div class="bg-gradient-to-r from-gray-500 to-gray-600 h-full transition-all duration-300" :style="{ width: (progress.not_tested / (progress.total || 1)) * 100 + '%' }"></div>
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
                </tr>
                <tr v-if="selectedItemId === item.id">
                  <td colspan="5" style="padding: 1.5rem">
                    <div class="card stack">
                      <h4>Comments</h4>
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
                      <button class="btn btn-primary" @click="addComment(item.id)">Add comment</button>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </section>
</template>