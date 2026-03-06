<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { apiRequest, withQuery } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const projects = ref([])
const pagination = reactive({
  current_page: 1,
  last_page: 1,
})

const checklists = ref([])
const listError = ref('')
const createError = ref('')
const successMessage = ref('')
const loadingProjects = ref(false)
const creating = ref(false)

const form = reactive({
  id: null,
  name: '',
  description: '',
  checklist_id: '',
})

async function loadProjects(page = 1) {
  loadingProjects.value = true
  listError.value = ''

  try {
    const data = await apiRequest(withQuery('/projects', { page }), {}, auth.token)
    projects.value = data.data || []
    pagination.current_page = data.current_page
    pagination.last_page = data.last_page
  } catch (error) {
    listError.value = error.data?.message || error.message
  } finally {
    loadingProjects.value = false
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

async function submitProject() {
  createError.value = ''
  successMessage.value = ''
  creating.value = true

  try {
    const payload = {
      name: form.name,
      description: form.description || null,
      ...(form.checklist_id ? { checklist_id: Number(form.checklist_id) } : {}),
    }

    if (form.id) {
      await apiRequest(`/projects/${form.id}`, { method: 'PUT', body: payload }, auth.token)
      successMessage.value = 'Project updated successfully.'
    } else {
      await apiRequest(
        '/projects',
        {
          method: 'POST',
          body: {
            ...payload,
            checklist_id: Number(form.checklist_id),
          },
        },
        auth.token,
      )
      successMessage.value = 'Project created successfully.'
    }

    resetForm()
    await loadProjects(1)
  } catch (error) {
    createError.value = error.data?.message || error.message
  } finally {
    creating.value = false
  }
}

function resetForm() {
  form.id = null
  form.name = ''
  form.description = ''
  form.checklist_id = ''
}

function editProject(project) {
  form.id = project.id
  form.name = project.name
  form.description = project.description || ''
  form.checklist_id = ''
}

function canManageProject(project) {
  return auth.isAdmin || project.created_by === auth.user?.id
}

async function deleteProject(projectId) {
  if (!confirm('Are you sure you want to delete this project?')) {
    return
  }

  listError.value = ''

  try {
    await apiRequest(`/projects/${projectId}`, { method: 'DELETE' }, auth.token)
    successMessage.value = 'Project deleted successfully.'
    await loadProjects(1)
  } catch (error) {
    listError.value = error.data?.message || error.message
  }
}

onMounted(async () => {
  await Promise.all([loadProjects(), loadChecklists()])
})
</script>

<template>
  <section class="page stack">
    <div class="section-header">
      <h1>Projects</h1>
      <p class="muted">Track project versions and test execution progress.</p>
    </div>

    <div v-if="auth.canManageProjects" class="card stack">
      <h2>{{ form.id ? 'Edit project' : 'Create project' }}</h2>

      <p v-if="createError" class="error">{{ createError }}</p>
      <p v-if="successMessage" class="success">{{ successMessage }}</p>

      <form class="stack" @submit.prevent="submitProject">
        <div class="grid">
          <div class="field">
            <label>Name</label>
            <input v-model="form.name" required />
          </div>
          <div class="field" v-if="!form.id">
            <label>Checklist</label>
            <select v-model="form.checklist_id" required>
              <option disabled value="">Select checklist</option>
              <option v-for="checklist in checklists" :key="checklist.id" :value="checklist.id">
                {{ checklist.name }}
              </option>
            </select>
          </div>
        </div>

        <div class="field">
          <label>Description</label>
          <textarea v-model="form.description" rows="3" />
        </div>

        <div class="actions">
          <button class="btn btn-primary" type="submit" :disabled="creating">
            {{ creating ? (form.id ? 'Updating...' : 'Creating...') : (form.id ? 'Update project' : 'Create project') }}
          </button>
          <button v-if="form.id" type="button" class="btn btn-secondary" @click="resetForm">Cancel</button>
        </div>
      </form>
    </div>

    <div class="card stack">
      <div class="section-header">
        <h2>Project list</h2>
      </div>

      <p v-if="listError" class="error">{{ listError }}</p>
      <p v-if="loadingProjects" class="muted">Loading projects...</p>

      <div v-if="!loadingProjects" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Creator</th>
              <th>Description</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="project in projects" :key="project.id">
              <td>{{ project.id }}</td>
              <td>{{ project.name }}</td>
              <td>{{ project.creator?.name || '-' }}</td>
              <td>{{ project.description || '-' }}</td>
              <td>
                <div class="actions">
                  <RouterLink class="btn btn-secondary btn-sm" :to="{ name: 'project-detail', params: { id: project.id } }">
                    Open
                  </RouterLink>
                  <button v-if="canManageProject(project)" class="btn btn-secondary btn-sm" @click="editProject(project)">Edit</button>
                  <button v-if="canManageProject(project)" class="btn btn-danger btn-sm" @click="deleteProject(project.id)">Delete</button>
                </div>
              </td>
            </tr>
            <tr v-if="projects.length === 0">
              <td colspan="5" class="muted">No projects found.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page <= 1"
          @click="loadProjects(pagination.current_page - 1)"
        >
          Previous
        </button>
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="loadProjects(pagination.current_page + 1)"
        >
          Next
        </button>
      </div>
    </div>
  </section>
</template>