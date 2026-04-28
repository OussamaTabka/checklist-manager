<script setup>
import { ref, computed } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { t } from '@/lib/translations'
import { translatePhrase } from '@/lib/runtimeTranslations'
import { ArrowLeft, Check, Globe, Moon, Palette } from 'lucide-vue-next'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()
const settingsStore = useSettingsStore()

const showNotification = ref(false)
const notificationMessage = ref('')

function tr(text) {
  return translatePhrase(text, settingsStore.language)
}

const currentLanguageLabel = computed(() => {
  const lang = settingsStore.languages.find((item) => item.code === settingsStore.language)
  return lang ? `${lang.flag} ${lang.name}` : 'Français'
})

const settingsCopy = computed(() => {
  switch (auth.primaryRole) {
    case 'admin':
      return {
        kicker: tr('Admin Preferences'),
        title: tr('Platform Settings'),
        description: tr('Adjust the interface language and appearance for administration work.'),
      }
    case 'testeur':
      return {
        kicker: tr('Execution Preferences'),
        title: tr('Tester Settings'),
        description: tr('Tune the workspace for faster reading, execution, and day-to-day testing comfort.'),
      }
    default:
      return {
        kicker: tr('Workspace Preferences'),
        title: t('settings.title', settingsStore.language),
        description: tr('Configure language and display behavior for your project-management workspace.'),
      }
  }
})

function showSavedMessage(message) {
  notificationMessage.value = message
  showNotification.value = true
  setTimeout(() => {
    showNotification.value = false
  }, 2000)
}

function changeLanguage(langCode) {
  settingsStore.setLanguage(langCode)
  showSavedMessage(t('msg.saved', settingsStore.language))
}

function toggleDarkMode() {
  settingsStore.toggleDarkMode()
  const message = settingsStore.darkMode
    ? t('settings.darkModeEnabled', settingsStore.language)
    : t('settings.darkModeDisabled', settingsStore.language)

  showSavedMessage(message)
}

function goBack() {
  router.back()
}
</script>

<template>
  <section class="page stack settings-page-pro">
    <div class="settings-hero">
      <button @click="goBack" class="btn btn-secondary btn-sm">
        <ArrowLeft :size="16" />
        <span>{{ tr('Back') }}</span>
      </button>

      <div class="settings-hero-copy">
        <p class="story-kicker">{{ settingsCopy.kicker }}</p>
        <h1>{{ settingsCopy.title }}</h1>
        <p>{{ settingsCopy.description }}</p>
      </div>
    </div>

    <Transition name="slide">
      <div v-if="showNotification" class="settings-toast">
        <Check :size="16" />
        <span>{{ notificationMessage }}</span>
      </div>
    </Transition>

    <div class="settings-grid-pro">
      <article class="settings-panel-pro">
        <div class="settings-panel-head">
          <div class="settings-panel-icon bg-blue-50 text-blue-700">
            <Globe :size="18" />
          </div>
          <div>
            <h2>{{ t('settings.language', settingsStore.language) }}</h2>
            <p>{{ currentLanguageLabel }}</p>
          </div>
        </div>

        <div class="settings-language-grid">
          <button
            v-for="lang in settingsStore.languages"
            :key="lang.code"
            @click="changeLanguage(lang.code)"
            class="settings-choice-card"
            :class="{ 'settings-choice-active': settingsStore.language === lang.code }"
          >
            <span class="settings-choice-flag">{{ lang.flag }}</span>
            <strong>{{ lang.name }}</strong>
          </button>
        </div>
      </article>

      <article class="settings-panel-pro">
        <div class="settings-panel-head">
          <div class="settings-panel-icon bg-amber-50 text-amber-700">
            <Moon :size="18" />
          </div>
          <div>
            <h2>{{ t('settings.darkMode', settingsStore.language) }}</h2>
            <p>
              {{
                settingsStore.darkMode
                  ? t('settings.darkModeEnabled', settingsStore.language)
                  : t('settings.darkModeDisabled', settingsStore.language)
              }}
            </p>
          </div>
        </div>

        <div class="settings-theme-row">
          <div class="settings-theme-preview">
            <Palette :size="18" />
            <span>{{ settingsStore.darkMode ? tr('Dark canvas enabled') : tr('Light canvas enabled') }}</span>
          </div>

          <button
            @click="toggleDarkMode"
            :class="['settings-toggle', { 'settings-toggle-on': settingsStore.darkMode }]"
          >
            <span />
          </button>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.slide-enter-active,
.slide-leave-active {
  transition: all 0.25s ease;
}

.slide-enter-from,
.slide-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
