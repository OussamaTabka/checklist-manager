const DEFAULT_LANGUAGE = 'fr'
const SUPPORTED_LANGUAGES = new Set(['fr', 'en'])

const MESSAGES = {
  request_failed: {
    fr: 'La requête a échoué.',
    en: 'Request failed.'
  },
  error_generic: {
    fr: 'Une erreur est survenue.',
    en: 'An error occurred.'
  },
  error_prefix: {
    fr: 'Erreur',
    en: 'Error'
  },
  confirm_archive_project: {
    fr: 'Voulez-vous vraiment archiver ce projet ?',
    en: 'Are you sure you want to archive this project?'
  },
  confirm_delete_project_permanent: {
    fr: 'Voulez-vous vraiment supprimer définitivement le projet « {name} » ? Cette action est irréversible.',
    en: 'Permanently delete project "{name}"? This action cannot be undone.'
  },
  confirm_delete_user_permanent: {
    fr: 'Voulez-vous vraiment supprimer définitivement l’utilisateur « {name} » ? Cette action est irréversible.',
    en: 'Permanently delete user "{name}"? This action cannot be undone.'
  },
  user_delete_reassign_projects_title: {
    fr: 'Réassigner les projets avant suppression',
    en: 'Reassign projects before deletion'
  },
  user_delete_reassign_projects_intro: {
    fr: 'Cet utilisateur possède {count} projet(s). Choisissez un chef de projet actif avant la suppression définitive.',
    en: 'This user owns {count} project(s). Choose an active project manager before permanent deletion.'
  },
  user_delete_reassign_projects_select: {
    fr: 'Nouveau chef de projet',
    en: 'New project manager'
  },
  user_delete_reassign_projects_placeholder: {
    fr: 'Sélectionner un chef de projet',
    en: 'Select a project manager'
  },
  user_delete_reassign_projects_hint: {
    fr: 'Les user stories créées par cet utilisateur seront également réassignées. Les checklists conserveront un créateur vide.',
    en: 'User stories created by this user will also be reassigned. Checklists will keep an empty creator.'
  },
  user_delete_reassign_projects_none: {
    fr: 'Aucun chef de projet actif n’est disponible pour la réassignation.',
    en: 'No active project manager is available for reassignment.'
  },
  user_delete_reassign_projects_required: {
    fr: 'Veuillez sélectionner un chef de projet remplaçant.',
    en: 'Please select a replacement project manager.'
  },
  confirm_archive_story: {
    fr: 'Archiver la user story "{title}" ?',
    en: 'Archive user story "{title}"?'
  },
  confirm_delete_story_title: {
    fr: 'Voulez-vous vraiment supprimer la user story "{title}" ?',
    en: 'Delete user story "{title}"?'
  },
  confirm_delete_story: {
    fr: 'Voulez-vous vraiment supprimer cette user story ?',
    en: 'Are you sure you want to delete this user story?'
  },
  confirm_delete_comment: {
    fr: 'Voulez-vous vraiment supprimer ce commentaire ?',
    en: 'Delete this comment?'
  },
  confirm_delete_checklist: {
    fr: 'Voulez-vous vraiment supprimer cette checklist ? Cette action est irréversible.',
    en: 'Are you sure you want to delete this checklist? This action cannot be undone.'
  },
  project_archived_success: {
    fr: 'Le projet a été archivé avec succès.',
    en: 'Project archived successfully.'
  },
  project_restored_success: {
    fr: 'Le projet a été restauré avec succès.',
    en: 'Project restored successfully.'
  },
  project_deleted_success: {
    fr: 'Le projet a été supprimé définitivement avec succès.',
    en: 'Project permanently deleted successfully.'
  },
  project_created_success: {
    fr: 'Projet créé avec succès.',
    en: 'Project created successfully.'
  },
  project_updated_success: {
    fr: 'Le projet a été mis à jour avec succès.',
    en: 'Project updated successfully.'
  },
  project_name_exists: {
    fr: 'Ce nom de projet existe déjà.',
    en: 'This project name already exists.'
  },
  project_choose_another_name: {
    fr: 'Veuillez choisir un autre nom de projet.',
    en: 'Please choose another project name.'
  },
  project_name_check_failed: {
    fr: 'Impossible de vérifier le nom du projet.',
    en: 'Unable to verify the project name.'
  },
  project_metadata_failed: {
    fr: 'Impossible de charger les données nécessaires à la création du projet.',
    en: 'Unable to load the data needed to create the project.'
  },
  project_create_failed: {
    fr: 'Impossible de créer le projet. Veuillez vérifier les informations saisies.',
    en: 'Unable to create the project. Please check the entered information.'
  },
  user_story_required: {
    fr: 'Veuillez ajouter manuellement ou importer au moins une user story valide.',
    en: 'Please manually add or import at least one valid user story.'
  },
  user_story_file_read_failed: {
    fr: 'Impossible de lire le fichier des user stories.',
    en: 'Unable to read the user stories file.'
  },
  user_story_file_type_invalid: {
    fr: "Le format du fichier importé n'est pas valide.",
    en: 'The imported file format is not valid.'
  },
  user_role_updated_success: {
    fr: 'Le rôle de l’utilisateur a été mis à jour avec succès.',
    en: 'User role updated successfully.'
  },
  user_created_success: {
    fr: 'Utilisateur créé avec succès.',
    en: 'User created successfully.'
  },
  user_invitation_resent_success: {
    fr: 'L’e-mail d’initialisation du mot de passe a été renvoyé avec succès.',
    en: 'The password setup email was resent successfully.'
  },
  user_invitation_revoked_success: {
    fr: 'L’invitation a été révoquée avec succès.',
    en: 'Invitation revoked successfully.'
  },
  user_archived_success: {
    fr: 'Utilisateur archivé avec succès.',
    en: 'User archived successfully.'
  },
  user_restored_success: {
    fr: 'Utilisateur restauré avec succès.',
    en: 'User restored successfully.'
  },
  user_deleted_success: {
    fr: 'Utilisateur supprimé définitivement avec succès.',
    en: 'User permanently deleted successfully.'
  },
  user_not_found_refreshed: {
    fr: 'Cet utilisateur n’existe plus. La liste a été actualisée.',
    en: 'This user no longer exists. The list was refreshed.'
  },
  user_delete_blocked: {
    fr: 'Suppression définitive impossible : cet utilisateur possède encore {details}.',
    en: 'Permanent deletion is impossible: this user still owns {details}.'
  },
  password_initialized_success: {
    fr: 'Mot de passe initialisé avec succès.',
    en: 'Password initialized successfully.'
  },
  password_initialized_login: {
    fr: 'Votre mot de passe a été initialisé. Vous pouvez maintenant vous connecter.',
    en: 'Your password has been initialized. You can now sign in.'
  },
  password_reset_link_sent: {
    fr: 'Si cette adresse e-mail existe, un lien de réinitialisation a été envoyé.',
    en: 'If your email exists, a reset link has been sent.'
  },
  password_reset_success: {
    fr: 'Votre mot de passe a été réinitialisé avec succès.',
    en: 'Your password has been reset successfully.'
  },
  notification_load_failed: {
    fr: 'Impossible de charger les notifications.',
    en: 'Unable to load notifications.'
  },
  notification_mark_read_failed: {
    fr: 'Impossible de marquer les notifications comme lues.',
    en: 'Unable to mark notifications as read.'
  },
  notification_open_failed: {
    fr: 'Impossible d’ouvrir cette notification.',
    en: 'Unable to open this notification.'
  },
  notification_archive_failed: {
    fr: 'Impossible d’archiver cette notification.',
    en: 'Unable to archive this notification.'
  },
  notification_marked_read: {
    fr: 'Notification marquée comme lue.',
    en: 'Notification marked as read.'
  },
  notifications_marked_read: {
    fr: 'Toutes les notifications ont été marquées comme lues.',
    en: 'All notifications have been marked as read.'
  },
  notification_archived: {
    fr: 'Notification archivée.',
    en: 'Notification archived.'
  },
  account_activated_success: {
    fr: 'Compte activé avec succès. Vous pouvez maintenant vous connecter.',
    en: 'Account activated successfully. You can now sign in.'
  }
}

const NOTIFICATION_TEXT = {
  project_assigned: {
    title: {
      fr: 'Nouveau projet assigné',
      en: 'New assigned project'
    },
    message: {
      fr: 'Vous avez été assigné à un nouveau projet.',
      en: 'You have been assigned to a new project.'
    }
  },
  chef_comment_added: {
    title: {
      fr: 'Nouveau commentaire chef de projet',
      en: 'New project manager comment'
    },
    message: {
      fr: 'Le chef de projet a ajouté un commentaire.',
      en: 'The project manager added a comment.'
    }
  },
  tester_comment_added: {
    title: {
      fr: 'Nouveau commentaire testeur',
      en: 'New tester comment'
    },
    message: {
      fr: 'Un testeur a ajouté un commentaire.',
      en: 'A tester added a comment.'
    }
  },
  automated_test_completed: {
    title: {
      fr: 'Exécution automatique terminée',
      en: 'Automated execution completed'
    },
    message: {
      fr: 'L’exécution automatique d’un test est terminée.',
      en: 'An automated test execution is complete.'
    }
  },
  tester_execution_completed: {
    title: {
      fr: 'Exécution du testeur terminée',
      en: 'Tester execution completed'
    },
    message: {
      fr: 'Un testeur a terminé une exécution automatique.',
      en: 'A tester completed an automated execution.'
    }
  },
  system_execution_error: {
    title: {
      fr: 'Erreur d’exécution automatique',
      en: 'Automated execution error'
    },
    message: {
      fr: 'Une erreur est survenue lors d’une exécution automatique.',
      en: 'An error occurred during automated execution.'
    }
  },
  pending_tests_reminder: {
    title: {
      fr: 'Tests en attente',
      en: 'Pending tests'
    },
    message: {
      fr: 'Des tests sont encore en attente d’exécution.',
      en: 'Some tests are still waiting to be executed.'
    }
  },
  project_pending_tests: {
    title: {
      fr: 'Tests en attente dans le projet',
      en: 'Pending tests in the project'
    },
    message: {
      fr: 'Des tests sont encore en attente d’exécution dans l’un de vos projets.',
      en: 'Some tests are still waiting to be executed in one of your projects.'
    }
  },
  project_low_progress: {
    title: {
      fr: 'Progression faible',
      en: 'Low progress'
    },
    message: {
      fr: 'La progression des tests d’un projet est faible.',
      en: 'Test progress is low for a project.'
    }
  }
}

function normalize(text) {
  return String(text || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[’‘`]/g, "'")
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase()
}

const PHRASE_INDEX = new Map()

Object.entries(MESSAGES).forEach(([key, values]) => {
  Object.values(values).forEach((value) => {
    PHRASE_INDEX.set(normalize(value), key)
  })
})

Object.values(NOTIFICATION_TEXT).forEach((entry) => {
  Object.entries(entry).forEach(([, values]) => {
    Object.values(values).forEach((value) => {
      PHRASE_INDEX.set(normalize(value), value)
    })
  })
})

const EXTRA_PHRASES = {
  'request failed': 'request_failed',
  'unauthenticated': {
    fr: 'Non authentifié.',
    en: 'Unauthenticated.'
  },
  'notifications are not available for admin users.': {
    fr: 'Les notifications ne sont pas disponibles pour les administrateurs.',
    en: 'Notifications are not available for admin users.'
  },
  'Identifiants invalides.': {
    fr: 'Identifiants invalides.',
    en: 'Invalid credentials.'
  },
  "votre compte n’est pas encore actif. veuillez initialiser votre mot de passe depuis le lien reçu par e-mail.": {
    fr: 'Votre compte n’est pas encore actif. Veuillez initialiser votre mot de passe depuis le lien reçu par e-mail.',
    en: 'Your account is not active yet. Please set your password from the email link.'
  },
  'deconnexion reussie.': {
    fr: 'Déconnexion réussie.',
    en: 'Logged out successfully.'
  },
  'profil mis a jour avec succes.': {
    fr: 'Profil mis à jour avec succès.',
    en: 'Profile updated successfully.'
  },
  'un projet avec ce nom existe déjà.': 'project_name_exists',
  'veuillez ajouter ou importer au moins une user story valide.': 'user_story_required',
  'format de fichier non supporte. utilisez un fichier csv, xlsx ou json.': 'user_story_file_type_invalid',
  'format non supporte. utilisez un fichier csv, xlsx ou json.': 'user_story_file_type_invalid',
  "le format du fichier importé n'est pas valide.": 'user_story_file_type_invalid',
  'one or more testers not found or invalid role': {
    fr: 'Un ou plusieurs testeurs sont introuvables ou possèdent un rôle invalide.',
    en: 'One or more testers were not found or have an invalid role.'
  },
  'checklist not found or not active': {
    fr: 'Checklist introuvable ou inactive.',
    en: 'Checklist not found or not active.'
  },
  'invalid invitation link.': {
    fr: 'Lien d’invitation invalide.',
    en: 'Invalid invitation link.'
  },
  'this invitation is no longer valid.': {
    fr: 'Cette invitation n’est plus valide.',
    en: 'This invitation is no longer valid.'
  },
  'this invitation has expired.': {
    fr: 'Cette invitation a expiré.',
    en: 'This invitation has expired.'
  },
  'account activated successfully. you can now sign in.': 'account_activated_success',
  'mot de passe initialisé avec succès.': 'password_initialized_success',
  'si cette adresse e-mail existe, un lien d’initialisation du mot de passe a été envoyé.': 'password_reset_link_sent',
  'notification marquée comme lue.': 'notification_marked_read',
  'toutes les notifications ont été marquées comme lues.': 'notifications_marked_read',
  'notification archivée.': 'notification_archived',
  'checklist deleted': {
    fr: 'Checklist supprimée.',
    en: 'Checklist deleted.'
  },
  'checklist mise à jour avec succès.': {
    fr: 'Checklist mise à jour avec succès.',
    en: 'Checklist updated successfully.'
  },
  'checklist créée avec succès.': {
    fr: 'Checklist créée avec succès.',
    en: 'Checklist created successfully.'
  },
  'checklist supprimée avec succès.': {
    fr: 'Checklist supprimée avec succès.',
    en: 'Checklist deleted successfully.'
  },
  'checklist archivée avec succès.': {
    fr: 'Checklist archivée avec succès.',
    en: 'Checklist archived successfully.'
  },
  'checklist restaurée avec succès.': {
    fr: 'Checklist restaurée avec succès.',
    en: 'Checklist restored successfully.'
  },
  'checklist supprimee definitivement avec succes.': {
    fr: 'Checklist supprimee definitivement avec succes.',
    en: 'Checklist permanently deleted successfully.'
  },
  'checklist status updated': {
    fr: 'Statut de la checklist mis à jour.',
    en: 'Checklist status updated.'
  },
  'status updated': {
    fr: 'Statut mis à jour.',
    en: 'Status updated.'
  },
  'item status updated.': {
    fr: 'Statut de l’élément mis à jour.',
    en: 'Item status updated.'
  },
  'no test case selected.': {
    fr: 'Aucun cas de test sélectionné.',
    en: 'No test case selected.'
  },
  'base url must be a valid http/https url.': {
    fr: 'L’URL de base doit être une URL HTTP/HTTPS valide.',
    en: 'Base URL must be a valid http/https URL.'
  },
  'failed to queue run.': {
    fr: 'Impossible de mettre l’exécution en file d’attente.',
    en: 'Failed to queue run.'
  },
  'la user story a été archivée avec succès.': {
    fr: 'La user story a été archivée avec succès.',
    en: 'User story archived successfully.'
  },
  'la user story a été supprimée avec succès.': {
    fr: 'La user story a été supprimée avec succès.',
    en: 'User story deleted successfully.'
  },
  'new version created successfully.': {
    fr: 'Nouvelle version créée avec succès.',
    en: 'New version created successfully.'
  },
  'new version created with custom checklist.': {
    fr: 'Nouvelle version créée avec une checklist personnalisée.',
    en: 'New version created with custom checklist.'
  },
  'checklist not found': {
    fr: 'Checklist introuvable.',
    en: 'Checklist not found.'
  },
  'failed to add comment': {
    fr: 'Impossible d’ajouter le commentaire.',
    en: 'Failed to add comment.'
  },
  'comment added successfully.': {
    fr: 'Commentaire ajouté avec succès.',
    en: 'Comment added successfully.'
  },
  'version exported successfully.': {
    fr: 'Version exportée avec succès.',
    en: 'Version exported successfully.'
  },
  'project exported successfully.': {
    fr: 'Projet exporté avec succès.',
    en: 'Project exported successfully.'
  },
  'aucun cas de test disponible.': {
    fr: 'Aucun cas de test disponible.',
    en: 'No test cases available.'
  },
  'tests exécutés avec succès.': {
    fr: 'Tests exécutés avec succès.',
    en: 'Tests executed successfully.'
  },
  'impossible d’exécuter les tests.': {
    fr: 'Impossible d’exécuter les tests.',
    en: 'Unable to execute tests.'
  },
  'l’exécution des tests a dépassé 10 minutes.': {
    fr: 'L’exécution des tests a dépassé 10 minutes.',
    en: 'Test execution exceeded 10 minutes.'
  },
  'l’exécution a échoué.': {
    fr: 'L’exécution a échoué.',
    en: 'Execution failed.'
  },
  'launch the checklist agent? it will reuse approved checklists first, then generate missing coverage.': {
    fr: 'Lancer l’agent de checklist ? Il réutilisera les checklists approuvées, puis générera la couverture manquante.',
    en: 'Launch the checklist agent? It will reuse approved checklists first, then generate missing coverage.'
  },
  'detach this checklist?': {
    fr: 'Détacher cette checklist ?',
    en: 'Detach this checklist?'
  },
  'checklist détachée de la user story.': {
    fr: 'Checklist détachée de la user story.',
    en: 'Checklist detached from the user story.'
  },
  'checklist associée à la user story avec succès.': {
    fr: 'Checklist existante associée à la user story.',
    en: 'Checklist attached successfully to the user story.'
  },
  'checklists associées à la user story avec succès.': {
    fr: 'Checklists existantes associées à la user story.',
    en: 'checklists attached successfully to the user story.'
  },
  'checklist approuvée et associée à la user story.': {
    fr: 'Checklist approuvée et associée à la user story.',
    en: 'Checklist approved and attached to the user story.'
  },
  'validez le fichier avant l’importation.': {
    fr: 'Validez le fichier avant l’importation.',
    en: 'Validate the file before importing.'
  }
}

Object.entries(EXTRA_PHRASES).forEach(([phrase, value]) => {
  PHRASE_INDEX.set(normalize(phrase), value)
})

export function getCurrentLanguage() {
  const lang =
    typeof document !== 'undefined' && document.documentElement?.lang
      ? document.documentElement.lang
      : DEFAULT_LANGUAGE

  return SUPPORTED_LANGUAGES.has(lang) ? lang : DEFAULT_LANGUAGE
}

export function interpolate(template, params = {}) {
  return String(template || '').replace(/\{(\w+)\}/g, (match, key) => {
    const value = params[key]
    return value === undefined || value === null ? match : String(value)
  })
}

export function tr(key, params = {}, language = getCurrentLanguage()) {
  const values = MESSAGES[key]
  const template = values?.[language] || values?.[DEFAULT_LANGUAGE] || key
  return interpolate(template, params)
}

function valueForIndexEntry(entry, language) {
  if (!entry) {
    return null
  }

  if (typeof entry === 'string') {
    return MESSAGES[entry]?.[language] || MESSAGES[entry]?.[DEFAULT_LANGUAGE] || entry
  }

  return entry[language] || entry[DEFAULT_LANGUAGE] || null
}

export function localizeMessage(message, language = getCurrentLanguage(), params = {}) {
  const source = String(message || '').trim()
  if (!source) {
    return source
  }

  const direct = valueForIndexEntry(PHRASE_INDEX.get(normalize(source)), language)
  if (direct) {
    return interpolate(direct, params)
  }

  const colonIndex = source.indexOf(': ')
  if (colonIndex > 0) {
    const prefix = source.slice(0, colonIndex)
    const suffix = source.slice(colonIndex + 2)
    const localizedPrefix = localizeMessage(prefix, language)
    const localizedSuffix = localizeMessage(suffix, language)

    if (localizedPrefix !== prefix || localizedSuffix !== suffix) {
      return `${localizedPrefix}: ${localizedSuffix}`
    }
  }

  return interpolate(source, params)
}

export function localizeError(error, fallbackKey = 'error_generic', language = getCurrentLanguage()) {
  const message =
    error?.data?.message ||
    error?.response?.data?.message ||
    error?.message ||
    tr(fallbackKey, {}, language)
  return localizeMessage(message, language)
}

export function localizeNotification(notification, language = getCurrentLanguage()) {
  const entry = NOTIFICATION_TEXT[notification?.type]

  return {
    ...notification,
    title: entry?.title?.[language] || localizeMessage(notification?.title, language),
    message: entry?.message?.[language] || localizeMessage(notification?.message, language)
  }
}

export function localizeNotifications(notifications, language = getCurrentLanguage()) {
  return (Array.isArray(notifications) ? notifications : []).map((item) =>
    localizeNotification(item, language),
  )
}
