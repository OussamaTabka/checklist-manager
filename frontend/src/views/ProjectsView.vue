<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  ArrowLeft,
  ArrowRight,
  BookOpen,
  ChartColumn,
  CirclePlus,
  ClipboardList,
  Info,
  LoaderCircle,
  Pencil,
  Rocket,
  Save,
  Settings,
  Sparkles,
  Trash2,
  Users,
  X,
} from 'lucide-vue-next'
import { apiRequest, withQuery } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const projects = ref([])
const pagination = reactive({
  current_page: 1,
  last_page: 1,
})

const checklists = ref([])
const users = ref([]) // for testers
const listError = ref('')
const createError = ref('')
const successMessage = ref('')
const loadingProjects = ref(false)
const creating = ref(false)

const form = reactive({
  id: null,
  name: '',
  description: '',
  app_url: '',
  checklist_id: '',
  checklist_ids: [], // additional checklists
  tester_ids: [], // assigned testers
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

async function loadProjectMetadata() {
  if (!auth.canManageProjects) {
    return
  }

  try {
    const data = await apiRequest('/projects/metadata', {}, auth.token)
    checklists.value = data.checklists || []
    users.value = data.testers || []
  } catch (error) {
    checklists.value = []
    users.value = []
    createError.value = error.data?.message || error.message || 'Unable to load project setup data'
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
      app_url: form.app_url,
      ...(form.checklist_id ? { checklist_id: Number(form.checklist_id) } : {}),
    }

    if (form.id) {
      // Update project (name, description, app_url only)
      await apiRequest(`/projects/${form.id}`, { method: 'PUT', body: payload }, auth.token)
      successMessage.value = 'Project updated successfully.'
    } else {
      // Create new project with testers and checklists
      const createPayload = {
        ...payload,
        checklist_id: Number(form.checklist_id),
        tester_ids: form.tester_ids.map((id) => Number(id)),
        checklist_ids: form.checklist_ids.map((id) => Number(id)),
      }

      await apiRequest(
        '/projects',
        {
          method: 'POST',
          body: createPayload,
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
  form.app_url = ''
  form.checklist_id = ''
  form.checklist_ids = []
  form.tester_ids = []
}

function editProject(project) {
  form.id = project.id
  form.name = project.name
  form.description = project.description || ''
  form.app_url = project.app_url || ''
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
  await Promise.all([loadProjects(), loadProjectMetadata()])
})
</script>

<template>
  <section class="page stack">
    <div style="margin-bottom: 2rem;">
      <h1 style="color: #1f2937; font-size: 2.5rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 0.6rem;">
        <Rocket :size="30" :stroke-width="2.3" />
        <span>Projects</span>
      </h1>
      <p class="muted" style="margin-top: 0.5rem; font-size: 1rem;">
        Manage testing projects, assign checklists and testers, and track execution progress.
      </p>
    </div>

    <div v-if="auth.canManageProjects" class="card stack">
      <div style="border-bottom: 2px solid #e5e7eb; padding-bottom: 1rem; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #1f2937; display: flex; align-items: center; gap: 0.5rem;">
          <Pencil v-if="form.id" :size="20" :stroke-width="2.2" />
          <CirclePlus v-else :size="20" :stroke-width="2.2" />
          <span>{{ form.id ? 'Edit Project' : 'Create New Project' }}</span>
        </h2>
      </div>

      <p v-if="createError" class="error">{{ createError }}</p>
      <p v-if="successMessage" class="success">{{ successMessage }}</p>

      <form class="stack" @submit.prevent="submitProject">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
          <div class="field">
            <label style="font-weight: 600; color: #1f2937;">Project Name</label>
            <input v-model="form.name" required placeholder="e.g., API Testing Phase 1" />
          </div>
          <div class="field">
            <label style="font-weight: 600; color: #1f2937;">App URL</label>
            <input v-model="form.app_url" type="url" placeholder="https://example.com" required />
          </div>
        </div>

        <div class="field">
          <label style="font-weight: 600; color: #1f2937;">Description</label>
          <textarea v-model="form.description" rows="3" placeholder="Add details about this project..." />
        </div>

        <!-- Create New Project Only -->
        <div v-if="!form.id" class="card project-setup-section">
          <div style="border-bottom: 2px solid #e5e7eb; padding-bottom: 1rem; margin-bottom: 1.5rem;">
            <h3 style="margin: 0; color: #1f2937; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
              <Settings :size="18" :stroke-width="2.2" />
              <span>Project Setup</span>
            </h3>
          </div>

          <!-- Primary Checklist -->
          <div class="field">
            <label style="font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 0.5rem;">
              <ClipboardList :size="18" :stroke-width="2.1" />
              Primary Checklist <span style="color: #ef4444;">*</span>
            </label>
            <select v-model="form.checklist_id" required style="margin-top: 0.5rem;">
              <option disabled value="">Select primary checklist for version 1</option>
              <option v-for="checklist in checklists" :key="checklist.id" :value="checklist.id">
                {{ checklist.name }}{{ checklist.description ? ' - ' + checklist.description : '' }}
              </option>
            </select>
            <p class="muted" style="font-size: 0.85rem; margin-top: 0.5rem; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 0.45rem;">
              <Info :size="14" style="margin-top: 0.1rem; flex-shrink: 0;" />
              <span>Used to create project version 1. You can add more checklists below.</span>
            </p>
          </div>

          <!-- Additional Checklists -->
          <div class="field">
            <label style="font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 0.5rem;">
              <BookOpen :size="18" :stroke-width="2.1" />
              Additional Checklists
            </label>
            <p class="muted" style="font-size: 0.85rem; margin: 0.5rem 0 1rem 0;">
              Select all checklists you want to assign to this project
            </p>
            <div v-if="checklists.length === 0" class="muted" style="padding: 1rem; text-align: center; background: #f9fafb; border-radius: 0.5rem;">
              No checklists available
            </div>
            <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.75rem;">
              <label 
                v-for="checklist in checklists" 
                :key="checklist.id" 
                style="
                  display: flex; 
                  align-items: flex-start; 
                  gap: 0.75rem; 
                  cursor: pointer;
                  padding: 0.75rem;
                  background: #f9fafb;
                  border: 1px solid #e5e7eb;
                  border-radius: 0.5rem;
                  transition: all 0.2s ease;
                  hover-state: hover;
                "
              >
                <input 
                  type="checkbox" 
                  :value="checklist.id" 
                  v-model="form.checklist_ids"
                  style="margin-top: 0.25rem; cursor: pointer; width: 18px; height: 18px;"
                />
                <div style="flex: 1;">
                  <div style="font-weight: 500; color: #1f2937;">{{ checklist.name }}</div>
                  <div v-if="checklist.description" class="muted" style="font-size: 0.8rem; margin-top: 0.25rem;">
                    {{ checklist.description }}
                  </div>
                </div>
              </label>
            </div>
          </div>

          <!-- Assign Testers -->
          <div class="field" style="margin-top: 1.5rem;">
            <label style="font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 0.5rem;">
              <Users :size="18" :stroke-width="2.1" />
              Assign Testers
            </label>
            <p class="muted" style="font-size: 0.85rem; margin: 0.5rem 0 1rem 0;">
              Select testers who will execute tests for this project
            </p>
            <div v-if="users.length === 0" class="muted" style="padding: 1rem; text-align: center; background: #f9fafb; border-radius: 0.5rem;">
              No testers available
            </div>
            <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.75rem;">
              <label 
                v-for="user in users" 
                :key="user.id" 
                style="
                  display: flex; 
                  align-items: flex-start; 
                  gap: 0.75rem; 
                  cursor: pointer;
                  padding: 0.75rem;
                  background: #f0fdf4;
                  border: 1px solid #dcfce7;
                  border-radius: 0.5rem;
                  transition: all 0.2s ease;
                "
              >
                <input 
                  type="checkbox" 
                  :value="user.id" 
                  v-model="form.tester_ids"
                  style="margin-top: 0.25rem; cursor: pointer; width: 18px; height: 18px;"
                />
                <div style="flex: 1;">
                  <div style="font-weight: 500; color: #1f2937;">{{ user.name }}</div>
                  <div class="muted" style="font-size: 0.8rem; margin-top: 0.25rem;">{{ user.email }}</div>
                </div>
              </label>
            </div>
          </div>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #e5e7eb;">
          <button class="btn btn-primary" type="submit" :disabled="creating" style="min-width: 200px; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;">
            <LoaderCircle v-if="creating" :size="16" class="spin" />
            <Save v-else-if="form.id" :size="16" />
            <Sparkles v-else :size="16" />
            <span>{{ creating ? (form.id ? 'Updating...' : 'Creating...') : (form.id ? 'Update Project' : 'Create Project') }}</span>
          </button>
          <button v-if="form.id" type="button" class="btn btn-secondary" @click="resetForm" style="display: inline-flex; align-items: center; gap: 0.4rem;">
            <X :size="16" />
            <span>Cancel</span>
          </button>
        </div>
      </form>
    </div>

    <div class="card stack" style="margin-top: 2rem;">
      <div style="border-bottom: 2px solid #e5e7eb; padding-bottom: 1rem; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #1f2937; display: flex; align-items: center; gap: 0.5rem;">
          <ChartColumn :size="20" :stroke-width="2.2" />
          <span>Project List</span>
        </h2>
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
              <th>Checklists</th>
              <th>Assigned Testers</th>
              <th>App URL</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="project in projects" :key="project.id">
              <td>{{ project.id }}</td>
              <td>
                <RouterLink :to="{ name: 'project-detail', params: { id: project.id } }">
                  {{ project.name }}
                </RouterLink>
              </td>
              <td>{{ project.creator?.name || '-' }}</td>
              <td>
                <div v-if="project.checklists && project.checklists.length > 0" style="font-size: 0.85rem;">
                  <div v-for="checklist in project.checklists" :key="checklist.id" style="background: #f3f4f6; padding: 0.25rem 0.5rem; border-radius: 0.25rem; margin-bottom: 0.25rem;">
                    {{ checklist.name }}
                  </div>
                </div>
                <span v-else class="muted">-</span>
              </td>
              <td>
                <div v-if="project.testers && project.testers.length > 0" style="font-size: 0.85rem;">
                  <div v-for="tester in project.testers" :key="tester.id" style="background: #dcfce7; padding: 0.25rem 0.5rem; border-radius: 0.25rem; margin-bottom: 0.25rem;">
                    {{ tester.name }}
                  </div>
                </div>
                <span v-else class="muted">No testers assigned</span>
              </td>
              <td>
                <a v-if="project.app_url" :href="project.app_url" target="_blank" rel="noopener noreferrer" style="font-size: 0.85rem;">
                  {{ new URL(project.app_url).hostname }}
                </a>
                <span v-else class="muted">-</span>
              </td>
              <td>
                <div class="actions" style="gap: 0.5rem;">
                  <button v-if="canManageProject(project)" class="btn btn-secondary btn-sm" @click="editProject(project)" title="Edit project" style="display: inline-flex; align-items: center; gap: 0.3rem;">
                    <Pencil :size="14" />
                    <span>Edit</span>
                  </button>
                  <button v-if="canManageProject(project)" class="btn btn-danger btn-sm" @click="deleteProject(project.id)" title="Delete project" style="display: inline-flex; align-items: center; gap: 0.3rem;">
                    <Trash2 :size="14" />
                    <span>Delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="projects.length === 0">
              <td colspan="7" class="muted">No projects found.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination" style="justify-content: center; gap: 1rem; margin-top: 2rem;">
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page <= 1"
          @click="loadProjects(pagination.current_page - 1)"
          title="Go to previous page"
          style="display: inline-flex; align-items: center; gap: 0.3rem;"
        >
          <ArrowLeft :size="14" />
          <span>Previous</span>
        </button>
        <span class="muted" style="font-weight: 600;">
          Page {{ pagination.current_page }} of {{ pagination.last_page }}
        </span>
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="loadProjects(pagination.current_page + 1)"
          title="Go to next page"
          style="display: inline-flex; align-items: center; gap: 0.3rem;"
        >
          <span>Next</span>
          <ArrowRight :size="14" />
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }

  to {
    transform: rotate(360deg);
  }
}
</style>