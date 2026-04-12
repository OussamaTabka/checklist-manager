<script setup>
import { computed, onMounted, ref } from 'vue'
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

const averageSuccessRate = computed(() => {
  if (!projectSuccessRows.value.length) return 0
  const total = projectSuccessRows.value.reduce((sum, row) => sum + (Number(row.successRate) || 0), 0)
  return Number((total / projectSuccessRows.value.length).toFixed(1))
})

const averageCompletion = computed(() => {
  if (!projectSuccessRows.value.length) return 0
  const total = projectSuccessRows.value.reduce((sum, row) => sum + (Number(row.completion) || 0), 0)
  return Number((total / projectSuccessRows.value.length).toFixed(1))
})

const qualityScore = computed(() => {
  const penalty = Math.min(failedCriticalItems.value * 3, 35)
  const weighted = (averageSuccessRate.value * 0.65) + (averageCompletion.value * 0.35)
  return Math.max(0, Number((weighted - penalty).toFixed(1)))
})

function pct(value) {
  const n = Number(value) || 0
  return Math.max(0, Math.min(100, n))
}

function meterColor(value) {
  const n = pct(value)
  if (n >= 80) return '#16a34a'
  if (n >= 60) return '#2563eb'
  if (n >= 40) return '#d97706'
  return '#dc2626'
}

async function loadDashboard() {
  loading.value = true
  errorMessage.value = ''

  try {
    const summary = await apiRequest('/dashboard/summary', {}, auth.token)
    totalProjects.value = summary.totalProjects || 0
    totalChecklists.value = summary.totalChecklists ?? null
    globalProgress.value = summary.globalProgress || 0
    failedCriticalItems.value = summary.failedCriticalItems || 0
    projectSuccessRows.value = summary.projectSuccessRows || []
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
      <button class="btn btn-secondary btn-sm" @click="loadDashboard" data-testid="dashboard-btn-refresh">Refresh</button>
    </div>

    <p v-if="errorMessage" class="error" data-testid="dashboard-msg-error">{{ errorMessage }}</p>
    <p v-if="loading" class="muted" data-testid="dashboard-msg-loading">Loading dashboard...</p>

    <div class="stats-grid" v-if="!loading" data-testid="dashboard-stats">
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
        <div style="margin-top: 0.6rem; width: 100%; height: 10px; background: #e5e7eb; border-radius: 999px; overflow: hidden">
          <div
            :style="{ width: `${pct(globalProgress)}%`, background: meterColor(globalProgress), height: '100%', transition: 'width 250ms ease' }"
          />
        </div>
      </article>

      <article class="stat-card">
        <div class="stat-label">Critical Items Failed</div>
        <div class="stat-value">{{ failedCriticalItems }}</div>
      </article>
    </div>

    <div class="stats-grid" v-if="!loading">
      <article class="card stack">
        <div class="section-header" style="margin-bottom: 0.2rem">
          <h3>Average Completion</h3>
          <strong>{{ averageCompletion }}%</strong>
        </div>
        <div style="width: 100%; height: 12px; background: #e5e7eb; border-radius: 999px; overflow: hidden">
          <div
            :style="{ width: `${pct(averageCompletion)}%`, background: meterColor(averageCompletion), height: '100%', transition: 'width 250ms ease' }"
          />
        </div>
      </article>

      <article class="card stack">
        <div class="section-header" style="margin-bottom: 0.2rem">
          <h3>Average Success</h3>
          <strong>{{ averageSuccessRate }}%</strong>
        </div>
        <div style="width: 100%; height: 12px; background: #e5e7eb; border-radius: 999px; overflow: hidden">
          <div
            :style="{ width: `${pct(averageSuccessRate)}%`, background: meterColor(averageSuccessRate), height: '100%', transition: 'width 250ms ease' }"
          />
        </div>
      </article>

      <article class="card stack">
        <div class="section-header" style="margin-bottom: 0.2rem">
          <h3>Quality Score</h3>
          <strong>{{ qualityScore }} / 100</strong>
        </div>
        <div style="width: 100%; height: 12px; background: #e5e7eb; border-radius: 999px; overflow: hidden">
          <div
            :style="{ width: `${pct(qualityScore)}%`, background: meterColor(qualityScore), height: '100%', transition: 'width 250ms ease' }"
          />
        </div>
        <small class="muted">Quality score uses success + completion, penalized by critical failures.</small>
      </article>
    </div>

    <div class="card stack" v-if="!loading" data-testid="dashboard-project-success">
      <div class="section-header">
        <h2>Project Success Rate</h2>
      </div>

      <div class="table-wrap">
        <table data-testid="dashboard-project-success">
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
              <td>
                <div style="min-width: 180px">
                  <div style="display: flex; justify-content: space-between; font-size: 0.86rem; margin-bottom: 0.35rem">
                    <span class="muted">{{ row.successRate }}%</span>
                  </div>
                  <div style="width: 100%; height: 8px; background: #e5e7eb; border-radius: 999px; overflow: hidden">
                    <div
                      :style="{ width: `${pct(row.successRate)}%`, background: meterColor(row.successRate), height: '100%', transition: 'width 250ms ease' }"
                    />
                  </div>
                </div>
              </td>
              <td>
                <div style="min-width: 180px">
                  <div style="display: flex; justify-content: space-between; font-size: 0.86rem; margin-bottom: 0.35rem">
                    <span class="muted">{{ row.completion }}%</span>
                  </div>
                  <div style="width: 100%; height: 8px; background: #e5e7eb; border-radius: 999px; overflow: hidden">
                    <div
                      :style="{ width: `${pct(row.completion)}%`, background: meterColor(row.completion), height: '100%', transition: 'width 250ms ease' }"
                    />
                  </div>
                </div>
              </td>
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