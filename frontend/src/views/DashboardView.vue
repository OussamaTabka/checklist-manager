<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { apiRequest } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const loading = ref(false)
const errorMessage = ref('')

const totalProjects = ref(0)
const totalChecklists = ref(null)
const globalProgress = ref(0)
const failedCriticalItems = ref(0)
const projectSuccessRows = ref([])

function computeVersionStats(version) {
  const items = version?.items || []
  const passed = items.filter((item) => item.status === 'Passed').length
  const failed = items.filter((item) => item.status === 'Failed').length
  const blocked = items.filter((item) => item.status === 'Blocked').length
  const pending = items.filter((item) => item.status === 'Not Tested').length
  const total = items.length

  const tested = passed + failed + blocked
  const completion = total ? Number(((tested / total) * 100).toFixed(2)) : 0
  const successRate = tested ? Number(((passed / tested) * 100).toFixed(2)) : 0
  const criticalFailed = items.filter(
    (item) => item.criticality === 'Critical' && item.status === 'Failed',
  ).length

  return { total, passed, failed, blocked, pending, completion, successRate, criticalFailed }
}

async function loadDashboard() {
  loading.value = true
  errorMessage.value = ''

  try {
    const projectsPage = await apiRequest('/projects', {}, auth.token)
    const projects = projectsPage.data || []
    totalProjects.value = projectsPage.total || projects.length

    if (auth.isAdmin) {
      try {
        const checklistsPage = await apiRequest('/checklists', {}, auth.token)
        totalChecklists.value = checklistsPage.total || (checklistsPage.data || []).length
      } catch {
        totalChecklists.value = null
      }
    }

    const details = await Promise.all(
      projects.map((project) => apiRequest(`/projects/${project.id}`, {}, auth.token)),
    )

    let totalItems = 0
    let testedItems = 0
    let criticalFailures = 0

    projectSuccessRows.value = details.map((project) => {
      const latestVersion = (project.versions || []).sort((a, b) => b.version_number - a.version_number)[0]
      const stats = computeVersionStats(latestVersion)

      totalItems += stats.total
      testedItems += stats.passed + stats.failed + stats.blocked
      criticalFailures += stats.criticalFailed

      return {
        id: project.id,
        name: project.name,
        version: latestVersion?.version_number || '-',
        successRate: stats.successRate,
        completion: stats.completion,
        failed: stats.failed,
      }
    })

    globalProgress.value = totalItems ? Number(((testedItems / totalItems) * 100).toFixed(2)) : 0
    failedCriticalItems.value = criticalFailures
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadDashboard()
})
</script>

<template>
  <section class="page stack">
    <div class="section-header">
      <h1>Dashboard</h1>
      <button class="btn btn-secondary btn-sm" @click="loadDashboard">Refresh</button>
    </div>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
    <p v-if="loading" class="muted">Loading dashboard...</p>

    <div class="stats-grid" v-if="!loading">
      <article class="stat-card">
        <div class="stat-label">Total Projects</div>
        <div class="stat-value">{{ totalProjects }}</div>
      </article>

      <article class="stat-card">
        <div class="stat-label">Total Checklists</div>
        <div class="stat-value">{{ totalChecklists ?? '-' }}</div>
      </article>

      <article class="stat-card">
        <div class="stat-label">Global Progress</div>
        <div class="stat-value">{{ globalProgress }}%</div>
      </article>

      <article class="stat-card">
        <div class="stat-label">Critical Items Failed</div>
        <div class="stat-value">{{ failedCriticalItems }}</div>
      </article>
    </div>

    <div class="card stack" v-if="!loading">
      <div class="section-header">
        <h2>Project Success Rate</h2>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Project</th>
              <th>Latest Version</th>
              <th>Success Rate</th>
              <th>Completion</th>
              <th>Failed Items</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in projectSuccessRows" :key="row.id">
              <td>{{ row.name }}</td>
              <td>v{{ row.version }}</td>
              <td>{{ row.successRate }}%</td>
              <td>{{ row.completion }}%</td>
              <td>{{ row.failed }}</td>
              <td>
                <RouterLink class="btn btn-secondary btn-sm" :to="{ name: 'project-detail', params: { id: row.id } }">
                  Open
                </RouterLink>
              </td>
            </tr>
            <tr v-if="projectSuccessRows.length === 0">
              <td colspan="6" class="muted">No projects available.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>