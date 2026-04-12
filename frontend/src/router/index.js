import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import LoginView from '@/views/LoginView.vue'
import ForgotPasswordView from '@/views/ForgotPasswordView.vue'
import ResetPasswordView from '@/views/ResetPasswordView.vue'
import AcceptInvitationView from '@/views/AcceptInvitationView.vue'
import DashboardView from '@/views/DashboardView.vue'
import ProjectsView from '@/views/ProjectsView.vue'
import ProjectDetailView from '@/views/ProjectDetailView.vue'
import ChecklistsView from '@/views/ChecklistsView.vue'
import UsersView from '@/views/UsersView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { guestOnly: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: ForgotPasswordView,
      meta: { guestOnly: true },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: ResetPasswordView,
      meta: { guestOnly: true },
    },
    {
      path: '/accept-invitation',
      name: 'accept-invitation',
      component: AcceptInvitationView,
    },
    {
      path: '/',
      redirect: { name: 'dashboard' },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: DashboardView,
      meta: { requiresAuth: true },
    },
    {
      path: '/projects',
      name: 'projects',
      component: ProjectsView,
      meta: { requiresAuth: true, roles: ['admin', 'chef', 'admin_contenus', 'testeur'] },
    },
    {
      path: '/projects/:id',
      name: 'project-detail',
      component: ProjectDetailView,
      meta: { requiresAuth: true },
    },
    {
      path: '/checklists',
      name: 'checklists',
      component: ChecklistsView,
      meta: { requiresAuth: true, roles: ['chef', 'admin_contenus'] },
    },
    {
      path: '/users',
      name: 'users',
      component: UsersView,
      meta: { requiresAuth: true, roles: ['admin'] },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  auth.hydrate()

  const shouldAttemptRestore =
    (to.meta.requiresAuth && Boolean(auth.token)) ||
    (to.meta.guestOnly && !auth.user && Boolean(auth.token))

  if (shouldAttemptRestore) {
    await auth.fetchMe()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  if (to.meta.roles && !auth.hasAnyRole(to.meta.roles)) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
