<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Archive, Bell, FolderKanban, MessageSquareText, RefreshCw, ShieldAlert, TestTube2 } from 'lucide-vue-next'
import { apiRequest, withQuery } from '@/lib/api'
import { formatNotificationDate, localizedNotificationFilters, notificationFilters, notificationStatusLabel, notificationTone, notificationTypeLabel } from '@/lib/notifications'
import { localizeError, localizeNotifications } from '@/lib/localization'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'
import { useRoute, useRouter } from 'vue-router'

const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const actionBusyId = ref('')
const pageError = ref('')
const notifications = ref([])
const unreadCount = ref(0)
const selectedFilter = ref('all')

const emptyMessage = computed(() => {
  if (selectedFilter.value === 'archived') {
    return 'Aucune notification archivée pour le moment.'
  }

  if (selectedFilter.value === 'unread') {
    return 'Aucune notification non lue pour le moment.'
  }

  return 'Aucune notification pour le moment.'
})

const visibleEmptyMessage = computed(() => {
  if (selectedFilter.value === 'archived') {
    return {
      fr: 'Aucune notification archivee pour le moment.',
      en: 'No archived notifications for now.',
      ar: 'Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¥Ø´Ø¹Ø§Ø±Ø§Øª Ù…Ø¤Ø±Ø´ÙØ© Ø­Ø§Ù„ÙŠØ§.',
    }[settings.language]
  }

  if (selectedFilter.value === 'unread') {
    return {
      fr: 'Aucune notification non lue pour le moment.',
      en: 'No unread notifications for now.',
      ar: 'Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¥Ø´Ø¹Ø§Ø±Ø§Øª ØºÙŠØ± Ù…Ù‚Ø±ÙˆØ¡Ø© Ø­Ø§Ù„ÙŠØ§.',
    }[settings.language]
  }

  return {
    fr: 'Aucune notification pour le moment.',
    en: 'No notifications for now.',
    ar: 'Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¥Ø´Ø¹Ø§Ø±Ø§Øª Ø­Ø§Ù„ÙŠØ§.',
  }[settings.language]
})

const visibleFilters = computed(() => localizedNotificationFilters(settings.language))
const visibleNotifications = computed(() => localizeNotifications(notifications.value, settings.language))
const unreadCounterLabel = computed(() => {
  const count = unreadCount.value

  if (settings.language === 'en') {
    return `${count} unread`
  }

  return `${count} non lue${count !== 1 ? 's' : ''}`
})

function iconForNotification(item) {
  switch (item.category) {
    case 'projects':
      return FolderKanban
    case 'comments':
      return MessageSquareText
    case 'system':
      return ShieldAlert
    case 'tests':
      return TestTube2
    default:
      return Bell
  }
}

async function loadNotifications() {
  loading.value = true
  pageError.value = ''

  try {
    const response = await apiRequest(
      withQuery('/notifications', {
        filter: selectedFilter.value,
        limit: 60,
      }),
      {},
      auth.token,
    )

    notifications.value = Array.isArray(response.items) ? response.items : []
    unreadCount.value = Number(response.unread_count || 0)
  } catch (error) {
    pageError.value = localizeError(error, 'notification_load_failed', settings.language)
    notifications.value = []
    unreadCount.value = 0
  } finally {
    loading.value = false
  }
}

async function markAllAsRead() {
  if (unreadCount.value < 1) {
    return
  }

  try {
    await apiRequest('/notifications/read-all', { method: 'PATCH' }, auth.token)
    notifications.value = notifications.value.map((item) => ({
      ...item,
      is_read: true,
      read_at: item.read_at || new Date().toISOString(),
    }))
    unreadCount.value = 0
  } catch (error) {
    pageError.value = localizeError(error, 'notification_mark_read_failed', settings.language)
  }
}

async function openNotification(item) {
  actionBusyId.value = item.id

  try {
    if (!item.is_read) {
      const response = await apiRequest(`/notifications/${item.id}/read`, { method: 'PATCH' }, auth.token)
      notifications.value = notifications.value.map((entry) => (entry.id === item.id ? response.item : entry))
      unreadCount.value = Math.max(0, unreadCount.value - 1)
      item = response.item
    }

    await router.push(item.link || '/notifications')
    maybeShowDeletedStoryToast(item)
  } catch (error) {
    pageError.value = localizeError(error, 'notification_open_failed', settings.language)
  } finally {
    actionBusyId.value = ''
  }
}

function maybeShowDeletedStoryToast(item) {
  if (item?.type !== 'user_story_deleted') {
    return
  }

  toast.info('La user story concernee a ete supprimee. Verifiez les checklists associees.')
}

async function archiveNotification(item) {
  actionBusyId.value = item.id

  try {
    await apiRequest(`/notifications/${item.id}/archive`, { method: 'PATCH' }, auth.token)
    notifications.value = notifications.value.filter((entry) => entry.id !== item.id)

    if (!item.is_read) {
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    }
  } catch (error) {
    pageError.value = localizeError(error, 'notification_archive_failed', settings.language)
  } finally {
    actionBusyId.value = ''
  }
}

watch(
  () => route.query.filter,
  (value) => {
    const nextValue = typeof value === 'string' ? value : 'all'
    selectedFilter.value = notificationFilters.some((filter) => filter.value === nextValue) ? nextValue : 'all'
  },
  { immediate: true },
)

watch(selectedFilter, async (value) => {
  if (route.query.filter !== value) {
    await router.replace({ query: value === 'all' ? {} : { filter: value } })
  }

  await loadNotifications()
})

onMounted(async () => {
  if (!auth.canReceiveNotifications) {
    await router.replace({ name: 'dashboard' })
    return
  }

  await loadNotifications()
})
</script>

<template>
  <section class="page stack notifications-page">
    <div class="section-header notifications-page-head">
      <div>
        <p class="dashboard-section-kicker">Centre de suivi</p>
        <h1 class="page-title-icon">
          <Bell :size="28" />
          <span>Notifications</span>
        </h1>
        <p class="page-subtitle">
          Retrouvez les alertes utiles selon votre rôle, avec accès directà la bonne section.
        </p>
      </div>

      <div class="actions">
        <button class="btn btn-secondary" type="button" @click="loadNotifications" :disabled="loading">
          <RefreshCw :size="16" />
          <span>Actualiser</span>
        </button>
        <button class="btn btn-primary" type="button" @click="markAllAsRead" :disabled="unreadCount < 1">
          Tout marquer comme lu
        </button>
      </div>
    </div>

    <p v-if="pageError" class="error">{{ pageError }}</p>

    <div class="card stack">
      <div class="notifications-toolbar">
        <div class="chip-row">
          <button
            v-for="filter in visibleFilters"
            :key="filter.value"
            type="button"
            class="filter-chip"
            :class="{ active: selectedFilter === filter.value }"
            @click="selectedFilter = filter.value"
          >
            {{ filter.label }}
          </button>
        </div>

        <span class="mini-chip notifications-counter">
          {{ unreadCounterLabel }}
        </span>
      </div>

      <div v-if="loading" class="muted">Chargement des notifications...</div>

      <div v-else-if="notifications.length === 0" class="notifications-empty">
        <Bell :size="26" />
        <p>{{ visibleEmptyMessage }}</p>
      </div>

      <div v-else class="notifications-page-list">
        <article
          v-for="item in visibleNotifications"
          :key="item.id"
          class="notification-card"
          :class="`notification-card-${notificationTone(item)}`"
        >
          <button
            type="button"
            class="notification-card-main"
            @click="openNotification(item)"
            :disabled="actionBusyId === item.id"
          >
            <div class="notification-card-icon">
              <component :is="iconForNotification(item)" :size="18" />
            </div>

            <div class="notification-card-copy">
              <div class="notification-card-top">
                <strong>{{ item.title }}</strong>
                <span class="notification-status-pill">{{ notificationStatusLabel(item, settings.language) }}</span>
              </div>

              <p>{{ item.message }}</p>

              <div class="notification-card-meta">
                <span>{{ notificationTypeLabel(item, settings.language) }}</span>
                <span v-if="item.priority">{{ item.priority }}</span>
                <span>{{ formatNotificationDate(item.created_at, settings.language) }}</span>
              </div>
            </div>
          </button>

          <button
            type="button"
            class="notification-archive-btn"
            @click="archiveNotification(item)"
            :disabled="actionBusyId === item.id"
            title="Archiver"
          >
            <Archive :size="16" />
            <span>Archiver</span>
          </button>
        </article>
      </div>
    </div>
  </section>
</template>
