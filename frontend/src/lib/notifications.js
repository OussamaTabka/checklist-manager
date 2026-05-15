import { getCurrentLanguage } from '@/lib/localization'

const FILTER_LABELS = {
  all: { fr: 'Toutes', en: 'All', ar: 'الكل' },
  unread: { fr: 'Non lues', en: 'Unread', ar: 'غير مقروءة' },
  projects: { fr: 'Projets', en: 'Projects', ar: 'المشاريع' },
  tests: { fr: 'Tests', en: 'Tests', ar: 'الاختبارات' },
  comments: { fr: 'Commentaires', en: 'Comments', ar: 'التعليقات' },
  system: { fr: 'Systeme', en: 'System', ar: 'النظام' },
  archived: { fr: 'Archivees', en: 'Archived', ar: 'مؤرشفة' },
}

export const notificationFilters = Object.keys(FILTER_LABELS).map((value) => ({
  value,
  label: FILTER_LABELS[value].fr,
}))

export function localizedNotificationFilters(language = getCurrentLanguage()) {
  return Object.entries(FILTER_LABELS).map(([value, labels]) => ({
    value,
    label: labels[language] || labels.fr,
  }))
}

export function formatNotificationDate(value, language = getCurrentLanguage()) {
  if (!value) {
    return ''
  }

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return ''
  }

  const locale = language === 'en' ? 'en-US' : language === 'ar' ? 'ar-SA' : 'fr-FR'

  return date.toLocaleString(locale, {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function notificationStatusLabel(notification, language = getCurrentLanguage()) {
  if (notification.is_archived) {
    return { fr: 'Archivee', en: 'Archived', ar: 'مؤرشفة' }[language] || 'Archivee'
  }

  if (notification.is_read) {
    return { fr: 'Lue', en: 'Read', ar: 'مقروءة' }[language] || 'Lue'
  }

  return { fr: 'Non lue', en: 'Unread', ar: 'غير مقروءة' }[language] || 'Non lue'
}

export function notificationTone(notification) {
  if (!notification.is_read) {
    return notification.priority || 'medium'
  }

  return 'read'
}

export function notificationTypeLabel(notification, language = getCurrentLanguage()) {
  const labels = {
    projects: { fr: 'Projet', en: 'Project', ar: 'مشروع' },
    tests: { fr: 'Test', en: 'Test', ar: 'اختبار' },
    comments: { fr: 'Commentaire', en: 'Comment', ar: 'تعليق' },
    system: { fr: 'Systeme', en: 'System', ar: 'النظام' },
    default: { fr: 'Notification', en: 'Notification', ar: 'إشعار' },
  }

  const label = labels[notification.category] || labels.default
  return label[language] || label.fr
}
