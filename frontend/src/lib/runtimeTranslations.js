import { watch } from 'vue'
import { useSettingsStore } from '@/stores/settings'

const PHRASES = {
  nav_main: { en: 'MAIN', fr: 'PRINCIPAL', ar: 'الرئيسية' },
  nav_test_management: { en: 'TEST MANAGEMENT', fr: 'GESTION DES TESTS', ar: 'إدارة الاختبارات' },
  nav_admin: { en: 'ADMIN', fr: 'ADMIN', ar: 'الإدارة' },
  nav_quick_actions: { en: 'QUICK ACTIONS', fr: 'ACTIONS RAPIDES', ar: 'إجراءات سريعة' },
  nav_dashboard: { en: 'Dashboard', fr: 'Tableau de Bord', ar: 'لوحة التحكم' },
  nav_projects: { en: 'Projects', fr: 'Projets', ar: 'المشاريع' },
  nav_users_roles: { en: 'Users & Roles', fr: 'Utilisateurs et Roles', ar: 'المستخدمون والادوار' },
  nav_checklist_templates: { en: 'Checklist Templates', fr: 'Modeles de Checklist', ar: 'قوالب قوائم التحقق' },
  nav_user_stories: { en: 'User Stories', fr: 'User Stories', ar: 'قصص المستخدم' },
  nav_profile: { en: 'Profile', fr: 'Profil', ar: 'الملف الشخصي' },
  nav_settings: { en: 'Settings', fr: 'Parametres', ar: 'الإعدادات' },
  nav_logout: { en: 'Logout', fr: 'Deconnexion', ar: 'تسجيل الخروج' },
  nav_notifications: { en: 'Notifications', fr: 'Notifications', ar: 'الإشعارات' },
  nav_alerts: { en: 'Alerts', fr: 'Alertes', ar: 'التنبيهات' },
  nav_no_alerts: { en: 'No active alerts for now', fr: 'Aucune alerte active pour le moment', ar: 'لا توجد تنبيهات نشطة حاليا' },
  nav_new_project: { en: 'New Project', fr: 'Nouveau Projet', ar: 'مشروع جديد' },
  nav_new_checklist: { en: 'New Checklist', fr: 'Nouvelle Checklist', ar: 'قائمة تحقق جديدة' },
  nav_search_global: {
    en: 'Search projects, users, checklists...',
    fr: 'Rechercher des projets, utilisateurs, checklists...',
    ar: 'ابحث في المشاريع والمستخدمين وقوائم التحقق...',
  },
  nav_no_matching_result: { en: 'No matching result.', fr: 'Aucun resultat correspondant.', ar: 'لا توجد نتيجة مطابقة.' },

  common_loading: { en: 'Loading...', fr: 'Chargement...', ar: 'جاري التحميل...' },
  common_refresh: { en: 'Refresh', fr: 'Actualiser', ar: 'تحديث' },
  common_filters: { en: 'Filters', fr: 'Filtres', ar: 'عوامل التصفية' },
  common_status: { en: 'Status', fr: 'Statut', ar: 'الحالة' },
  common_priority: { en: 'Priority', fr: 'Priorite', ar: 'الاولوية' },
  common_actions: { en: 'Actions', fr: 'Actions', ar: 'الاجراءات' },
  common_all: { en: 'All', fr: 'Tous', ar: 'الكل' },
  common_name: { en: 'Name', fr: 'Nom', ar: 'الاسم' },
  common_email: { en: 'Email', fr: 'Email', ar: 'البريد الالكتروني' },
  common_role: { en: 'Role', fr: 'Role', ar: 'الدور' },
  common_reset: { en: 'Reset', fr: 'Reinitialiser', ar: 'اعادة تعيين' },
  common_search: { en: 'Search', fr: 'Rechercher', ar: 'بحث' },
  common_backlog: { en: 'Backlog', fr: 'Backlog', ar: 'قائمة الانتظار' },
  common_in_progress: { en: 'In Progress', fr: 'En Cours', ar: 'قيد التنفيذ' },
  common_not_started: { en: 'Not Started', fr: 'Non Commence', ar: 'لم يبدا' },
  common_perfect: { en: 'Perfect', fr: 'Parfait', ar: 'مثالي' },
  common_at_risk: { en: 'At Risk', fr: 'A Risque', ar: 'في خطر' },
  common_delayed: { en: 'Delayed', fr: 'En Retard', ar: 'متاخر' },

  dashboard_title: { en: 'Project Success Rate', fr: 'Taux de Reussite des Projets', ar: 'معدل نجاح المشاريع' },
  dashboard_loading: { en: 'Loading dashboard...', fr: 'Chargement du tableau de bord...', ar: 'جاري تحميل لوحة التحكم...' },
  dashboard_search_projects: { en: 'Search projects...', fr: 'Rechercher des projets...', ar: 'ابحث في المشاريع...' },
  dashboard_min_success: { en: 'Min Success', fr: 'Succes Minimum', ar: 'الحد الادنى للنجاح' },

  users_title: { en: 'Users', fr: 'Utilisateurs', ar: 'المستخدمون' },
  users_create: { en: 'Create user', fr: 'Creer un utilisateur', ar: 'انشاء مستخدم' },
  users_update: { en: 'Update user', fr: 'Mettre a jour utilisateur', ar: 'تحديث المستخدم' },
  users_user_list: { en: 'User list', fr: 'Liste des utilisateurs', ar: 'قائمة المستخدمين' },
  users_loading: { en: 'Loading users...', fr: 'Chargement des utilisateurs...', ar: 'جاري تحميل المستخدمين...' },
  users_signin: { en: 'Sign in', fr: 'Se connecter', ar: 'تسجيل الدخول' },
  users_signing_in: { en: 'Signing in...', fr: 'Connexion en cours...', ar: 'جاري تسجيل الدخول...' },
  users_forgot_password: { en: 'Forgot password?', fr: 'Mot de passe oublie ?', ar: 'هل نسيت كلمة المرور؟' },
  users_credentials_help: {
    en: 'Use your account credentials to access Checklist Manager.',
    fr: 'Utilisez vos identifiants pour acceder a Checklist Manager.',
    ar: 'استخدم بيانات حسابك للوصول الى Checklist Manager.',
  },
  users_invite_help: {
    en: 'The user will receive an invitation email to set their own password.',
    fr: 'L utilisateur recevra un email d invitation pour definir son mot de passe.',
    ar: 'سيتلقى المستخدم رسالة دعوة لتعيين كلمة المرور الخاصة به.',
  },

  stories_new: { en: 'New Story', fr: 'Nouvelle Story', ar: 'قصة جديدة' },
  stories_edit: { en: 'Edit Story', fr: 'Modifier Story', ar: 'تعديل القصة' },
  stories_create_first: { en: 'Create the first story ->', fr: 'Creer la premiere story ->', ar: 'انشئ اول قصة ->' },

  settings_title: { en: 'Settings', fr: 'Parametres', ar: 'الإعدادات' },
  settings_appearance: { en: 'Appearance', fr: 'Apparence', ar: 'المظهر' },
  settings_language: { en: 'Language', fr: 'Langue', ar: 'اللغة' },
  settings_dark_mode: { en: 'Dark Mode', fr: 'Mode Sombre', ar: 'الوضع الداكن' },
  settings_light_mode: { en: 'Light Mode', fr: 'Mode Clair', ar: 'الوضع الفاتح' },
}

const INDEX = new Map()
Object.entries(PHRASES).forEach(([key, values]) => {
  Object.values(values).forEach((value) => {
    INDEX.set(normalize(value), key)
  })
})

let observer = null
let translating = false

function normalize(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase()
}

function translateKnownPhrase(sourceText, language) {
  const key = INDEX.get(normalize(sourceText))
  if (!key) {
    return sourceText
  }

  const translation = PHRASES[key]?.[language]
  return translation || sourceText
}

function translateTextNode(node, language) {
  if (!node?.nodeValue) {
    return
  }

  const raw = node.nodeValue
  const trimmed = raw.trim()
  if (!trimmed) {
    return
  }

  const translated = translateKnownPhrase(trimmed, language)
  if (translated === trimmed) {
    return
  }

  const leading = raw.match(/^\s*/)?.[0] || ''
  const trailing = raw.match(/\s*$/)?.[0] || ''
  node.nodeValue = `${leading}${translated}${trailing}`
}

function translateAttributes(root, language) {
  const elements = root.querySelectorAll('*')
  const attrs = ['placeholder', 'title', 'aria-label']

  elements.forEach((el) => {
    attrs.forEach((attr) => {
      const current = el.getAttribute(attr)
      if (!current) {
        return
      }
      const translated = translateKnownPhrase(current, language)
      if (translated !== current) {
        el.setAttribute(attr, translated)
      }
    })

    if (
      el.tagName === 'INPUT' &&
      ['button', 'submit'].includes((el.getAttribute('type') || '').toLowerCase())
    ) {
      const value = el.getAttribute('value')
      if (value) {
        const translated = translateKnownPhrase(value, language)
        if (translated !== value) {
          el.setAttribute('value', translated)
        }
      }
    }
  })
}

function translateTree(language) {
  if (typeof document === 'undefined' || !document.body) {
    return
  }

  translating = true
  try {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const parentTag = node.parentElement?.tagName
        if (parentTag && ['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(parentTag)) {
          return NodeFilter.FILTER_REJECT
        }

        return NodeFilter.FILTER_ACCEPT
      },
    })

    const textNodes = []
    let current = walker.nextNode()
    while (current) {
      textNodes.push(current)
      current = walker.nextNode()
    }

    textNodes.forEach((node) => translateTextNode(node, language))
    translateAttributes(document.body, language)
  } finally {
    translating = false
  }
}

export function initRuntimeTranslations(pinia) {
  if (typeof document === 'undefined') {
    return
  }

  const settings = useSettingsStore(pinia)

  const runTranslation = () => {
    translateTree(settings.language)
  }

  watch(
    () => settings.language,
    () => {
      runTranslation()
    },
    { immediate: true },
  )

  if (observer) {
    observer.disconnect()
  }

  observer = new MutationObserver(() => {
    if (translating) {
      return
    }

    runTranslation()
  })

  observer.observe(document.body, {
    childList: true,
    subtree: true,
  })
}
