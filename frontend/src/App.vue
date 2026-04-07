<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import LogoHeader from '@/components/LogoHeader.vue'

const auth = useAuthStore()
const router = useRouter()

const roleLabel = computed(() => auth.roles.join(', '))

async function handleLogout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="portal-shell">
    <template v-if="auth.isAuthenticated">
      <header class="topbar">
        <LogoHeader />

        <div class="topbar-right">
          <div class="user-meta">
            <strong>{{ auth.user?.name }}</strong>
            <span>{{ roleLabel }}</span>
          </div>
          <button class="btn btn-secondary" @click="handleLogout">Logout</button>
        </div>
      </header>

      <div class="portal-layout">
        <aside class="sidebar">
          <div class="sidebar-title">Navigation</div>
          <nav class="sidebar-nav">
            <RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink>
            <RouterLink :to="{ name: 'projects' }">Projects</RouterLink>
            <RouterLink v-if="auth.canManageChecklists" :to="{ name: 'checklists' }">Checklist Templates</RouterLink>
            <RouterLink v-if="auth.canManageUsers" :to="{ name: 'users' }">Users & Roles</RouterLink>
          </nav>
        </aside>

        <main class="portal-content">
          <RouterView />
        </main>
      </div>
    </template>

    <main v-else class="auth-content">
      <RouterView />
    </main>
  </div>
</template>
