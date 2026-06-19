import { getCurrentLanguage } from '@/lib/localization'

const FILTER_LABELS = {
  all: { fr: 'Toutes', en: 'All' },
  unread: { fr: 'Non lues', en: 'Unread' },
  projects: { fr: 'Projets', en: 'Projects' },
  tests: { fr: 'Tests', en: 'Tests' },
  comments: { fr: 'Commentaires', en: 'Comments' },
  system: { fr: 'Système', en: 'System' },
  archived: { fr: 'Archivées', en: 'Archived' },
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

  const locale = language === 'en' ? 'en-US' : 'fr-FR'

  return date.toLocaleString(locale, {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function notificationStatusLabel(notification, language = getCurrentLanguage()) {
  if (notification.is_archived) {
    return { fr: 'Archivée', en: 'Archived' }[language] || 'Archivée'
  }

  if (notification.is_read) {
    return { fr: 'Lue', en: 'Read' }[language] || 'Lue'
  }

  return { fr: 'Non lue', en: 'Unread' }[language] || 'Non lue'
}

export function notificationTone(notification) {
  if (!notification.is_read) {
    return notification.priority || 'medium'
  }

  return 'read'
}

export function notificationTypeLabel(notification, language = getCurrentLanguage()) {
  const labels = {
    projects: { fr: 'Projet', en: 'Project' },
    tests: { fr: 'Test', en: 'Test' },
    comments: { fr: 'Commentaire', en: 'Comment' },
    system: { fr: 'Système', en: 'System' },
    default: { fr: 'Notification', en: 'Notification' },
  }

  const label = labels[notification.category] || labels.default
  return label[language] || label.fr
}
