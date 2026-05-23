import { ref, watch, computed } from 'vue'
import { defineStore } from 'pinia'

export const useSettingsStore = defineStore('settings', () => {
  const STORAGE_KEY = 'app_settings'
  const SUPPORTED_LANGUAGE_CODES = new Set(['fr', 'en'])

  const language = ref('fr')
  const darkMode = ref(false)

  const languages = [
    { code: 'fr', name: 'Français', flag: '🇫🇷' },
    { code: 'en', name: 'English', flag: '🇬🇧' },
  ]

  function loadSettings() {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored) {
      try {
        const settings = JSON.parse(stored)
        language.value = SUPPORTED_LANGUAGE_CODES.has(settings.language) ? settings.language : 'fr'
        darkMode.value = settings.darkMode || false
      } catch (e) {
        console.error('Failed to load settings:', e)
      }
    }

    applyDarkMode()
    applyLanguage()
  }

  function saveSettings() {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        language: language.value,
        darkMode: darkMode.value,
      }),
    )
  }

  function setLanguage(lang) {
    language.value = SUPPORTED_LANGUAGE_CODES.has(lang) ? lang : 'fr'
    applyLanguage()
    saveSettings()
  }

  function toggleDarkMode() {
    darkMode.value = !darkMode.value
    applyDarkMode()
    saveSettings()
  }

  function applyDarkMode() {
    const html = document.documentElement
    if (darkMode.value) {
      html.classList.add('dark')
    } else {
      html.classList.remove('dark')
    }
  }

  function applyLanguage() {
    const html = document.documentElement
    html.lang = language.value
    html.dir = 'ltr'
    document.body.style.direction = 'ltr'
  }

  const currentLanguageName = computed(() => {
    const lang = languages.find((item) => item.code === language.value)
    return lang?.name || 'Français'
  })

  const currentLanguageFlag = computed(() => {
    const lang = languages.find((item) => item.code === language.value)
    return lang?.flag || '🇫🇷'
  })

  watch(darkMode, () => {
    applyDarkMode()
  })

  watch(language, () => {
    applyLanguage()
  })

  return {
    language,
    darkMode,
    languages,
    currentLanguageName,
    currentLanguageFlag,
    loadSettings,
    saveSettings,
    setLanguage,
    toggleDarkMode,
  }
})
