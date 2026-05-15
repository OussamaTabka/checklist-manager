const DEFAULT_LANGUAGE = 'fr'
const SUPPORTED_LANGUAGES = new Set(['fr', 'en', 'ar'])

const MESSAGES = {
  request_failed: {
    fr: 'La requete a echoue.',
    en: 'Request failed.',
    ar: 'فشل الطلب.',
  },
  error_generic: {
    fr: 'Une erreur est survenue.',
    en: 'An error occurred.',
    ar: 'حدث خطأ.',
  },
  error_prefix: {
    fr: 'Erreur',
    en: 'Error',
    ar: 'خطأ',
  },
  confirm_archive_project: {
    fr: 'Voulez-vous vraiment archiver ce projet ?',
    en: 'Are you sure you want to archive this project?',
    ar: 'هل تريد فعلا أرشفة هذا المشروع؟',
  },
  confirm_delete_project_permanent: {
    fr: 'Supprimer definitivement le projet "{name}" ? Cette action est irreversible.',
    en: 'Permanently delete project "{name}"? This action cannot be undone.',
    ar: 'حذف المشروع "{name}" نهائيا؟ لا يمكن التراجع عن هذا الاجراء.',
  },
  confirm_delete_user_permanent: {
    fr: 'Supprimer definitivement l utilisateur "{name}" ? Cette action est irreversible.',
    en: 'Permanently delete user "{name}"? This action cannot be undone.',
    ar: 'حذف المستخدم "{name}" نهائيا؟ لا يمكن التراجع عن هذا الاجراء.',
  },
  confirm_archive_story: {
    fr: 'Archiver la user story "{title}" ?',
    en: 'Archive user story "{title}"?',
    ar: 'أرشفة قصة المستخدم "{title}"؟',
  },
  confirm_delete_story: {
    fr: 'Voulez-vous vraiment supprimer cette User Story ?',
    en: 'Are you sure you want to delete this user story?',
    ar: 'هل تريد فعلا حذف قصة المستخدم هذه؟',
  },
  confirm_delete_comment: {
    fr: 'Supprimer ce commentaire ?',
    en: 'Delete this comment?',
    ar: 'حذف هذا التعليق؟',
  },
  confirm_delete_checklist: {
    fr: 'Voulez-vous vraiment supprimer cette checklist ? Cette action est irreversible.',
    en: 'Are you sure you want to delete this checklist? This action cannot be undone.',
    ar: 'هل تريد فعلا حذف قائمة التحقق هذه؟ لا يمكن التراجع عن هذا الاجراء.',
  },
  project_archived_success: {
    fr: 'Le projet a ete archive avec succes.',
    en: 'Project archived successfully.',
    ar: 'تمت أرشفة المشروع بنجاح.',
  },
  project_restored_success: {
    fr: 'Le projet a ete restaure avec succes.',
    en: 'Project restored successfully.',
    ar: 'تمت استعادة المشروع بنجاح.',
  },
  project_deleted_success: {
    fr: 'Le projet a ete supprime definitivement avec succes.',
    en: 'Project permanently deleted successfully.',
    ar: 'تم حذف المشروع نهائيا بنجاح.',
  },
  project_created_success: {
    fr: 'Projet cree avec succes.',
    en: 'Project created successfully.',
    ar: 'تم إنشاء المشروع بنجاح.',
  },
  project_updated_success: {
    fr: 'Le projet a ete mis a jour avec succes.',
    en: 'Project updated successfully.',
    ar: 'تم تحديث المشروع بنجاح.',
  },
  project_name_exists: {
    fr: 'Ce nom de projet existe deja.',
    en: 'This project name already exists.',
    ar: 'اسم المشروع موجود مسبقا.',
  },
  project_choose_another_name: {
    fr: 'Veuillez choisir un autre nom de projet.',
    en: 'Please choose another project name.',
    ar: 'يرجى اختيار اسم مشروع آخر.',
  },
  project_name_check_failed: {
    fr: 'Impossible de verifier le nom du projet.',
    en: 'Unable to verify the project name.',
    ar: 'تعذر التحقق من اسم المشروع.',
  },
  project_metadata_failed: {
    fr: 'Impossible de charger les donnees necessaires a la creation du projet.',
    en: 'Unable to load the data needed to create the project.',
    ar: 'تعذر تحميل البيانات اللازمة لإنشاء المشروع.',
  },
  project_create_failed: {
    fr: 'Impossible de creer le projet. Veuillez verifier les informations saisies.',
    en: 'Unable to create the project. Please check the entered information.',
    ar: 'تعذر إنشاء المشروع. يرجى التحقق من المعلومات المدخلة.',
  },
  user_story_required: {
    fr: 'Veuillez ajouter manuellement ou importer au moins une User Story valide.',
    en: 'Please manually add or import at least one valid user story.',
    ar: 'يرجى إضافة أو استيراد قصة مستخدم صالحة واحدة على الأقل.',
  },
  user_story_file_read_failed: {
    fr: 'Impossible de lire le fichier de User Stories.',
    en: 'Unable to read the user stories file.',
    ar: 'تعذرت قراءة ملف قصص المستخدم.',
  },
  user_role_updated_success: {
    fr: 'Le role de l utilisateur a ete mis a jour avec succes.',
    en: 'User role updated successfully.',
    ar: 'تم تحديث دور المستخدم بنجاح.',
  },
  user_created_success: {
    fr: 'Utilisateur cree avec succes.',
    en: 'User created successfully.',
    ar: 'تم إنشاء المستخدم بنجاح.',
  },
  user_invitation_resent_success: {
    fr: 'L email d initialisation du mot de passe a ete renvoye avec succes.',
    en: 'The password setup email was resent successfully.',
    ar: 'تمت إعادة إرسال بريد إعداد كلمة المرور بنجاح.',
  },
  user_invitation_revoked_success: {
    fr: 'L invitation a ete revoquee avec succes.',
    en: 'Invitation revoked successfully.',
    ar: 'تم إلغاء الدعوة بنجاح.',
  },
  user_archived_success: {
    fr: 'Utilisateur archive avec succes.',
    en: 'User archived successfully.',
    ar: 'تمت أرشفة المستخدم بنجاح.',
  },
  user_restored_success: {
    fr: 'Utilisateur restaure avec succes.',
    en: 'User restored successfully.',
    ar: 'تمت استعادة المستخدم بنجاح.',
  },
  user_deleted_success: {
    fr: 'Utilisateur supprime definitivement avec succes.',
    en: 'User permanently deleted successfully.',
    ar: 'تم حذف المستخدم نهائيا بنجاح.',
  },
  user_not_found_refreshed: {
    fr: 'Cet utilisateur n existe plus. La liste a ete actualisee.',
    en: 'This user no longer exists. The list was refreshed.',
    ar: 'هذا المستخدم لم يعد موجودا. تم تحديث القائمة.',
  },
  user_delete_blocked: {
    fr: 'Suppression definitive impossible : cet utilisateur possede encore {details}.',
    en: 'Permanent deletion is impossible: this user still owns {details}.',
    ar: 'لا يمكن الحذف النهائي: ما زال هذا المستخدم يملك {details}.',
  },
  password_initialized_success: {
    fr: 'Mot de passe initialise avec succes.',
    en: 'Password initialized successfully.',
    ar: 'تم إعداد كلمة المرور بنجاح.',
  },
  password_initialized_login: {
    fr: 'Votre mot de passe a ete initialise. Vous pouvez maintenant vous connecter.',
    en: 'Your password has been initialized. You can now sign in.',
    ar: 'تم إعداد كلمة المرور. يمكنك الآن تسجيل الدخول.',
  },
  password_reset_link_sent: {
    fr: 'Si cette adresse email existe, un lien de reinitialisation a ete envoye.',
    en: 'If your email exists, a reset link has been sent.',
    ar: 'إذا كان البريد الإلكتروني موجودا، فقد تم إرسال رابط إعادة التعيين.',
  },
  password_reset_success: {
    fr: 'Votre mot de passe a ete reinitialise avec succes.',
    en: 'Your password has been reset successfully.',
    ar: 'تمت إعادة تعيين كلمة المرور بنجاح.',
  },
  notification_load_failed: {
    fr: 'Impossible de charger les notifications.',
    en: 'Unable to load notifications.',
    ar: 'تعذر تحميل الإشعارات.',
  },
  notification_mark_read_failed: {
    fr: 'Impossible de marquer les notifications comme lues.',
    en: 'Unable to mark notifications as read.',
    ar: 'تعذر تعليم الإشعارات كمقروءة.',
  },
  notification_open_failed: {
    fr: 'Impossible d ouvrir cette notification.',
    en: 'Unable to open this notification.',
    ar: 'تعذر فتح هذا الإشعار.',
  },
  notification_archive_failed: {
    fr: 'Impossible d archiver cette notification.',
    en: 'Unable to archive this notification.',
    ar: 'تعذر أرشفة هذا الإشعار.',
  },
  notification_marked_read: {
    fr: 'Notification marquee comme lue.',
    en: 'Notification marked as read.',
    ar: 'تم تعليم الإشعار كمقروء.',
  },
  notifications_marked_read: {
    fr: 'Toutes les notifications ont ete marquees comme lues.',
    en: 'All notifications have been marked as read.',
    ar: 'تم تعليم كل الإشعارات كمقروءة.',
  },
  notification_archived: {
    fr: 'Notification archivee.',
    en: 'Notification archived.',
    ar: 'تمت أرشفة الإشعار.',
  },
  account_activated_success: {
    fr: 'Compte active avec succes. Vous pouvez maintenant vous connecter.',
    en: 'Account activated successfully. You can now sign in.',
    ar: 'تم تفعيل الحساب بنجاح. يمكنك الآن تسجيل الدخول.',
  },
}

const NOTIFICATION_TEXT = {
  project_assigned: {
    title: {
      fr: 'Nouveau projet assigne',
      en: 'New assigned project',
      ar: 'مشروع جديد مسند',
    },
    message: {
      fr: 'Vous avez ete assigne a un nouveau projet.',
      en: 'You have been assigned to a new project.',
      ar: 'تم إسناد مشروع جديد إليك.',
    },
  },
  chef_comment_added: {
    title: {
      fr: 'Nouveau commentaire chef de projet',
      en: 'New project manager comment',
      ar: 'تعليق جديد من مدير المشروع',
    },
    message: {
      fr: 'Le chef de projet a ajoute un commentaire.',
      en: 'The project manager added a comment.',
      ar: 'أضاف مدير المشروع تعليقا.',
    },
  },
  tester_comment_added: {
    title: {
      fr: 'Nouveau commentaire testeur',
      en: 'New tester comment',
      ar: 'تعليق جديد من المختبر',
    },
    message: {
      fr: 'Un testeur a ajoute un commentaire.',
      en: 'A tester added a comment.',
      ar: 'أضاف مختبر تعليقا.',
    },
  },
  automated_test_completed: {
    title: {
      fr: 'Execution automatique terminee',
      en: 'Automated execution completed',
      ar: 'اكتمل التنفيذ الآلي',
    },
    message: {
      fr: 'L execution automatique d un test est terminee.',
      en: 'An automated test execution is complete.',
      ar: 'اكتمل التنفيذ الآلي لاختبار.',
    },
  },
  tester_execution_completed: {
    title: {
      fr: 'Execution testeur terminee',
      en: 'Tester execution completed',
      ar: 'اكتمل تنفيذ المختبر',
    },
    message: {
      fr: 'Un testeur a termine une execution automatique.',
      en: 'A tester completed an automated execution.',
      ar: 'أنهى مختبر تنفيذا آليا.',
    },
  },
  system_execution_error: {
    title: {
      fr: 'Erreur d execution automatique',
      en: 'Automated execution error',
      ar: 'خطأ في التنفيذ الآلي',
    },
    message: {
      fr: 'Une erreur est survenue lors d une execution automatique.',
      en: 'An error occurred during automated execution.',
      ar: 'حدث خطأ أثناء التنفيذ الآلي.',
    },
  },
  pending_tests_reminder: {
    title: {
      fr: 'Tests en attente',
      en: 'Pending tests',
      ar: 'اختبارات معلقة',
    },
    message: {
      fr: 'Des tests sont encore en attente d execution.',
      en: 'Some tests are still waiting to be executed.',
      ar: 'ما زالت بعض الاختبارات في انتظار التنفيذ.',
    },
  },
  project_pending_tests: {
    title: {
      fr: 'Tests en attente dans le projet',
      en: 'Pending tests in the project',
      ar: 'اختبارات معلقة في المشروع',
    },
    message: {
      fr: 'Des tests sont encore en attente d execution dans l un de vos projets.',
      en: 'Some tests are still waiting to be executed in one of your projects.',
      ar: 'ما زالت بعض الاختبارات في انتظار التنفيذ في أحد مشاريعك.',
    },
  },
  project_low_progress: {
    title: {
      fr: 'Progression faible',
      en: 'Low progress',
      ar: 'تقدم منخفض',
    },
    message: {
      fr: 'La progression des tests d un projet est faible.',
      en: 'Test progress is low for a project.',
      ar: 'تقدم الاختبارات منخفض في أحد المشاريع.',
    },
  },
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
    fr: 'Non authentifie.',
    en: 'Unauthenticated.',
    ar: 'غير مصادق.',
  },
  'notifications are not available for admin users.': {
    fr: 'Les notifications ne sont pas disponibles pour les administrateurs.',
    en: 'Notifications are not available for admin users.',
    ar: 'الإشعارات غير متاحة للمسؤولين.',
  },
  'identifiants invalides.': {
    fr: 'Identifiants invalides.',
    en: 'Invalid credentials.',
    ar: 'بيانات الاعتماد غير صحيحة.',
  },
  "votre compte n'est pas encore actif. veuillez initialiser votre mot de passe depuis le lien recu par email.": {
    fr: 'Votre compte n est pas encore actif. Veuillez initialiser votre mot de passe depuis le lien recu par email.',
    en: 'Your account is not active yet. Please set your password from the email link.',
    ar: 'حسابك غير مفعل بعد. يرجى إعداد كلمة المرور من رابط البريد الإلكتروني.',
  },
  'deconnexion reussie.': {
    fr: 'Deconnexion reussie.',
    en: 'Logged out successfully.',
    ar: 'تم تسجيل الخروج بنجاح.',
  },
  'profil mis a jour avec succes.': {
    fr: 'Profil mis a jour avec succes.',
    en: 'Profile updated successfully.',
    ar: 'تم تحديث الملف الشخصي بنجاح.',
  },
  'un projet avec ce nom existe deja.': 'project_name_exists',
  'veuillez ajouter ou importer au moins une user story valide.': 'user_story_required',
  'one or more testers not found or invalid role': {
    fr: 'Un ou plusieurs testeurs sont introuvables ou ont un role invalide.',
    en: 'One or more testers were not found or have an invalid role.',
    ar: 'تعذر العثور على مختبر واحد أو أكثر أو أن الدور غير صالح.',
  },
  'checklist not found or not active': {
    fr: 'Checklist introuvable ou inactive.',
    en: 'Checklist not found or not active.',
    ar: 'قائمة التحقق غير موجودة أو غير نشطة.',
  },
  'invalid invitation link.': {
    fr: 'Lien d invitation invalide.',
    en: 'Invalid invitation link.',
    ar: 'رابط الدعوة غير صالح.',
  },
  'this invitation is no longer valid.': {
    fr: 'Cette invitation n est plus valide.',
    en: 'This invitation is no longer valid.',
    ar: 'هذه الدعوة لم تعد صالحة.',
  },
  'this invitation has expired.': {
    fr: 'Cette invitation a expire.',
    en: 'This invitation has expired.',
    ar: 'انتهت صلاحية هذه الدعوة.',
  },
  'account activated successfully. you can now sign in.': 'account_activated_success',
  'mot de passe initialise avec succes.': 'password_initialized_success',
  'si cette adresse email existe, un lien d initialisation du mot de passe a ete envoye.': 'password_reset_link_sent',
  'notification marquee comme lue.': 'notification_marked_read',
  'toutes les notifications ont ete marquees comme lues.': 'notifications_marked_read',
  'notification archivee.': 'notification_archived',
  'checklist deleted': {
    fr: 'Checklist supprimee.',
    en: 'Checklist deleted.',
    ar: 'تم حذف قائمة التحقق.',
  },
  'checklist mise a jour avec succes.': {
    fr: 'Checklist mise a jour avec succes.',
    en: 'Checklist updated successfully.',
    ar: 'تم تحديث قائمة التحقق بنجاح.',
  },
  'checklist creee avec succes.': {
    fr: 'Checklist creee avec succes.',
    en: 'Checklist created successfully.',
    ar: 'تم إنشاء قائمة التحقق بنجاح.',
  },
  'checklist supprimee avec succes.': {
    fr: 'Checklist supprimee avec succes.',
    en: 'Checklist deleted successfully.',
    ar: 'تم حذف قائمة التحقق بنجاح.',
  },
  'checklist status updated': {
    fr: 'Statut de checklist mis a jour.',
    en: 'Checklist status updated.',
    ar: 'تم تحديث حالة قائمة التحقق.',
  },
  'status updated': {
    fr: 'Statut mis a jour.',
    en: 'Status updated.',
    ar: 'تم تحديث الحالة.',
  },
  'item status updated.': {
    fr: 'Statut de l item mis a jour.',
    en: 'Item status updated.',
    ar: 'تم تحديث حالة العنصر.',
  },
  'no test case selected.': {
    fr: 'Aucun cas de test selectionne.',
    en: 'No test case selected.',
    ar: 'لم يتم تحديد حالة اختبار.',
  },
  'base url must be a valid http/https url.': {
    fr: 'L URL de base doit etre une URL http/https valide.',
    en: 'Base URL must be a valid http/https URL.',
    ar: 'يجب أن يكون رابط الأساس http/https صالحا.',
  },
  'failed to queue run.': {
    fr: 'Impossible de mettre l execution en file d attente.',
    en: 'Failed to queue run.',
    ar: 'تعذر وضع التنفيذ في قائمة الانتظار.',
  },
  'la user story a ete archivee avec succes.': {
    fr: 'La user story a ete archivee avec succes.',
    en: 'User story archived successfully.',
    ar: 'تمت أرشفة قصة المستخدم بنجاح.',
  },
  'new version created successfully.': {
    fr: 'Nouvelle version creee avec succes.',
    en: 'New version created successfully.',
    ar: 'تم إنشاء نسخة جديدة بنجاح.',
  },
  'new version created with custom checklist.': {
    fr: 'Nouvelle version creee avec une checklist personnalisee.',
    en: 'New version created with custom checklist.',
    ar: 'تم إنشاء نسخة جديدة بقائمة تحقق مخصصة.',
  },
  'checklist not found': {
    fr: 'Checklist introuvable.',
    en: 'Checklist not found.',
    ar: 'قائمة التحقق غير موجودة.',
  },
  'failed to add comment': {
    fr: 'Impossible d ajouter le commentaire.',
    en: 'Failed to add comment.',
    ar: 'تعذرت إضافة التعليق.',
  },
  'comment added successfully.': {
    fr: 'Commentaire ajoute avec succes.',
    en: 'Comment added successfully.',
    ar: 'تمت إضافة التعليق بنجاح.',
  },
  'version exported successfully.': {
    fr: 'Version exportee avec succes.',
    en: 'Version exported successfully.',
    ar: 'تم تصدير النسخة بنجاح.',
  },
  'project exported successfully.': {
    fr: 'Projet exporte avec succes.',
    en: 'Project exported successfully.',
    ar: 'تم تصدير المشروع بنجاح.',
  },
  'aucun cas de test disponible.': {
    fr: 'Aucun cas de test disponible.',
    en: 'No test cases available.',
    ar: 'لا توجد حالات اختبار متاحة.',
  },
  'tests executes avec succes.': {
    fr: 'Tests executes avec succes.',
    en: 'Tests executed successfully.',
    ar: 'تم تنفيذ الاختبارات بنجاح.',
  },
  'impossible d executer les tests.': {
    fr: 'Impossible d executer les tests.',
    en: 'Unable to execute tests.',
    ar: 'تعذر تنفيذ الاختبارات.',
  },
  'l execution des tests a depasse 10 minutes.': {
    fr: 'L execution des tests a depasse 10 minutes.',
    en: 'Test execution exceeded 10 minutes.',
    ar: 'تجاوز تنفيذ الاختبارات 10 دقائق.',
  },
  'l execution a echoue.': {
    fr: 'L execution a echoue.',
    en: 'Execution failed.',
    ar: 'فشل التنفيذ.',
  },
  'launch the checklist agent? it will reuse approved checklists first, then generate missing coverage.': {
    fr: 'Lancer l agent de checklist ? Il reutilisera les checklists approuvees puis generera la couverture manquante.',
    en: 'Launch the checklist agent? It will reuse approved checklists first, then generate missing coverage.',
    ar: 'تشغيل وكيل قائمة التحقق؟ سيعيد استخدام القوائم المعتمدة ثم ينشئ التغطية الناقصة.',
  },
  'detach this checklist?': {
    fr: 'Detacher cette checklist ?',
    en: 'Detach this checklist?',
    ar: 'فصل قائمة التحقق هذه؟',
  },
  'checklist detachee de la user story.': {
    fr: 'Checklist detachee de la User Story.',
    en: 'Checklist detached from the user story.',
    ar: 'تم فصل قائمة التحقق عن قصة المستخدم.',
  },
  'checklist existante associee a la user story.': {
    fr: 'Checklist existante associee a la User Story.',
    en: 'Existing checklist attached to the user story.',
    ar: 'تم ربط قائمة تحقق موجودة بقصة المستخدم.',
  },
  'checklists existantes associees a la user story.': {
    fr: 'Checklists existantes associees a la User Story.',
    en: 'Existing checklists attached to the user story.',
    ar: 'تم ربط قوائم تحقق موجودة بقصة المستخدم.',
  },
  'checklist approuvee et associee a la user story.': {
    fr: 'Checklist approuvee et associee a la User Story.',
    en: 'Checklist approved and attached to the user story.',
    ar: 'تم اعتماد قائمة التحقق وربطها بقصة المستخدم.',
  },
  'validez le fichier avant import.': {
    fr: 'Validez le fichier avant import.',
    en: 'Validate the file before importing.',
    ar: 'تحقق من الملف قبل الاستيراد.',
  },
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
    message: entry?.message?.[language] || localizeMessage(notification?.message, language),
  }
}

export function localizeNotifications(notifications, language = getCurrentLanguage()) {
  return (Array.isArray(notifications) ? notifications : []).map((item) =>
    localizeNotification(item, language),
  )
}
