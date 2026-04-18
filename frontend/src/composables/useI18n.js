import { useSettingsStore } from '@/stores/settings'
import { t } from '@/lib/translations'
import { computed } from 'vue'

export function useI18n() {
  const settingsStore = useSettingsStore()

  const currentLanguage = computed(() => settingsStore.language)

  const translate = (key) => t(key, currentLanguage.value)

  const isRTL = computed(() => settingsStore.language === 'ar')

  return {
    currentLanguage,
    translate,
    t: translate,
    isRTL,
    languages: settingsStore.languages,
  }
}
