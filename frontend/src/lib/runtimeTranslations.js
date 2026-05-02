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
  common_critical: { en: 'Critical', fr: 'Critique', ar: 'حرج' },
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
  common_back: { en: 'Back', fr: 'Retour', ar: 'رجوع' },
  common_expand: { en: 'Expand', fr: 'Developper', ar: 'توسيع' },
  common_collapse: { en: 'Collapse', fr: 'Reduire', ar: 'طي' },
  common_profile: { en: 'Profile', fr: 'Profil', ar: 'الملف الشخصي' },
  common_navigation: { en: 'Navigation', fr: 'Navigation', ar: 'التنقل' },
  common_project: { en: 'Project', fr: 'Projet', ar: 'مشروع' },
  common_checklist: { en: 'Checklist', fr: 'Checklist', ar: 'قائمة تحقق' },
  common_user: { en: 'User', fr: 'Utilisateur', ar: 'مستخدم' },
  common_dark_canvas_enabled: { en: 'Dark canvas enabled', fr: 'Fond sombre active', ar: 'تم تفعيل الخلفية الداكنة' },
  common_light_canvas_enabled: { en: 'Light canvas enabled', fr: 'Fond clair active', ar: 'تم تفعيل الخلفية الفاتحة' },
  settings_admin_preferences: { en: 'Admin Preferences', fr: 'Preferences Admin', ar: 'تفضيلات الادارة' },
  settings_platform_settings: { en: 'Platform Settings', fr: 'Parametres de la plateforme', ar: 'اعدادات المنصة' },
  settings_admin_description: {
    en: 'Adjust the interface language and appearance for administration work.',
    fr: "Ajustez la langue de l'interface et l'apparence pour le travail d'administration.",
    ar: 'اضبط لغة الواجهة والمظهر من اجل مهام الادارة.',
  },
  settings_execution_preferences: { en: 'Execution Preferences', fr: "Preferences d'execution", ar: 'تفضيلات التنفيذ' },
  settings_tester_settings: { en: 'Tester Settings', fr: 'Parametres testeur', ar: 'اعدادات المختبر' },
  settings_tester_description: {
    en: 'Tune the workspace for faster reading, execution, and day-to-day testing comfort.',
    fr: "Ajustez l'espace de travail pour une lecture, une execution et un confort de test quotidiens.",
    ar: 'اضبط مساحة العمل لقراءة اسرع وتنفيذ اسهل وراحة يومية في الاختبار.',
  },
  settings_workspace_preferences: { en: 'Workspace Preferences', fr: "Preferences de l'espace", ar: 'تفضيلات مساحة العمل' },
  settings_workspace_description: {
    en: 'Configure language and display behavior for your project-management workspace.',
    fr: "Configurez la langue et l'affichage pour votre espace de gestion de projet.",
    ar: 'قم بضبط اللغة وسلوك العرض لمساحة ادارة المشاريع.',
  },
  app_project_command: { en: 'Project Command', fr: 'Pilotage Projet', ar: 'قيادة المشروع' },
  app_admin_control: { en: 'Admin Control', fr: 'Controle Admin', ar: 'تحكم الادارة' },
  app_execution_space: { en: 'Execution Space', fr: 'Espace Execution', ar: 'مساحة التنفيذ' },
  app_workspace: { en: 'Workspace', fr: 'Espace de travail', ar: 'مساحة العمل' },
  app_tests_failed_recently: { en: 'test(s) failed recently', fr: 'test(s) ont echoue recemment', ar: 'اختبار/اختبارات فشلت مؤخرا' },
  app_critical_items_failing: { en: 'critical item(s) are failing', fr: 'element(s) critique(s) en echec', ar: 'عنصر/عناصر حرجة في حالة فشل' },
  app_type_navigation: { en: 'Navigation', fr: 'Navigation', ar: 'التنقل' },
  placeholder_search_checklists: { en: 'Search checklists...', fr: 'Rechercher des checklists...', ar: 'ابحث في قوائم التحقق...' },
  placeholder_filter_checklist_name: { en: 'Filter by checklist name', fr: 'Filtrer par nom de checklist', ar: 'تصفية حسب اسم قائمة التحقق' },
  placeholder_filter_checklist_type: { en: 'Filter by checklist type', fr: 'Filtrer par type de checklist', ar: 'تصفية حسب نوع قائمة التحقق' },
  placeholder_filter_checklist_category: { en: 'Filter by checklist category', fr: 'Filtrer par categorie de checklist', ar: 'تصفية حسب فئة قائمة التحقق' },
  placeholder_checklist_category_example: {
    en: 'e.g., Automation, Security, Performance',
    fr: 'ex. : Automatisation, Securite, Performance',
    ar: 'مثال: اتمتة، امان، اداء',
  },
  placeholder_search_existing_items: {
    en: 'Start typing to search existing items...',
    fr: 'Commencez a taper pour rechercher des items existants...',
    ar: 'ابدأ الكتابة للبحث عن العناصر الموجودة...',
  },
  confirm_delete_checklist: {
    en: 'Are you sure you want to delete this checklist? This action cannot be undone.',
    fr: 'Voulez-vous vraiment supprimer cette checklist ? Cette action est irreversible.',
    ar: 'هل تريد حقا حذف قائمة التحقق هذه؟ لا يمكن التراجع عن هذا الاجراء.',
  },
  placeholder_search_projects: { en: 'Search projects...', fr: 'Rechercher des projets...', ar: 'ابحث في المشاريع...' },
  confirm_delete_project: {
    en: 'Are you sure you want to delete this project?',
    fr: 'Voulez-vous vraiment supprimer ce projet ?',
    ar: 'هل تريد حقا حذف هذا المشروع؟',
  },
  placeholder_filter_project_name: { en: 'Filter by project name', fr: 'Filtrer par nom de projet', ar: 'تصفية حسب اسم المشروع' },
  placeholder_filter_type: { en: 'Filter by type', fr: 'Filtrer par type', ar: 'تصفية حسب النوع' },
  placeholder_filter_category: { en: 'Filter by category', fr: 'Filtrer par categorie', ar: 'تصفية حسب الفئة' },
  placeholder_project_phase: { en: 'e.g., API Testing Phase 1', fr: 'ex. : Phase 1 de test API', ar: 'مثال: مرحلة 1 لاختبار API' },
  placeholder_url_example: { en: 'https://example.com', fr: 'https://example.com', ar: 'https://example.com' },
  placeholder_project_description: { en: 'Add details about this project...', fr: 'Ajoutez des details sur ce projet...', ar: 'اضف تفاصيل عن هذا المشروع...' },
  title_previous_page: { en: 'Go to previous page', fr: 'Aller a la page precedente', ar: 'اذهب الى الصفحة السابقة' },
  title_next_page: { en: 'Go to next page', fr: 'Aller a la page suivante', ar: 'اذهب الى الصفحة التالية' },
  placeholder_story_reference: { en: 'US-LOGIN-01', fr: 'US-CONNEXION-01', ar: 'US-LOGIN-01' },
  placeholder_story_title: { en: 'Connexion avec email et mot de passe', fr: 'Connexion avec email et mot de passe', ar: 'تسجيل الدخول بالبريد الالكتروني وكلمة المرور' },
  placeholder_story_description: {
    en: "En tant que client, je veux me connecter avec mon email afin d'acceder a mon espace personnel.",
    fr: "En tant que client, je veux me connecter avec mon email afin d'acceder a mon espace personnel.",
    ar: 'بصفتي عميلا، اريد تسجيل الدخول بالبريد الالكتروني للوصول الى مساحتي الشخصية.',
  },
  placeholder_story_gwt: {
    en: 'Given valid credentials, When user submits the login form, Then dashboard loads Given invalid credentials, When user submits the form, Then an error message is displayed',
    fr: "Etant donne des identifiants valides, quand l'utilisateur soumet le formulaire, alors le tableau de bord s'ouvre. Etant donne des identifiants invalides, quand l'utilisateur soumet le formulaire, alors un message d'erreur s'affiche.",
    ar: 'عند وجود بيانات اعتماد صحيحة، وعندما يرسل المستخدم النموذج، حينها تفتح لوحة التحكم. وعند وجود بيانات اعتماد غير صحيحة، وعندما يرسل المستخدم النموذج، حينها تظهر رسالة خطأ.',
  },
  confirm_delete_comment: { en: 'Delete this comment?', fr: 'Supprimer ce commentaire ?', ar: 'حذف هذا التعليق؟' },
  placeholder_add_comment: { en: 'Add a comment...', fr: 'Ajouter un commentaire...', ar: 'اضف تعليقا...' },
  placeholder_env_name: { en: 'staging', fr: 'preproduction', ar: 'staging' },
  confirm_delete_story: {
    en: 'Are you sure you want to delete this story ?',
    fr: 'Etes-vous sur de vouloir supprimer cette story ?',
    ar: 'هل تريد حقا حذف هذه القصة؟',
  },
  title_delete: { en: 'Delete', fr: 'Supprimer', ar: 'حذف' },
  confirm_run_generation_agent: {
    en: 'Launch the generation agent? It will reuse approved checklists and generate the missing items.',
    fr: "Lancer l'agent de generation ? Il reutilisera les checklists approuvees puis generera les elements manquants.",
    ar: 'هل تريد تشغيل وكيل التوليد؟ سيعيد استخدام القوائم المعتمدة ويولد العناصر الناقصة.',
  },
  confirm_detach_checklist: {
    en: 'Detach this checklist?',
    fr: 'Detacher cette checklist ?',
    ar: 'فصل قائمة التحقق هذه؟',
  },
  title_detach: { en: 'Detach', fr: 'Detacher', ar: 'فصل' },
  aria_close: { en: 'Close', fr: 'Fermer', ar: 'اغلاق' },
}

Object.assign(PHRASES, {
  common_administrator: { en: 'Administrator', fr: 'Administrateur', ar: 'مدير النظام' },
  common_project_manager: { en: 'Project manager', fr: 'Chef de projet', ar: 'مدير المشروع' },
  common_tester: { en: 'Tester', fr: 'Testeur', ar: 'مختبر' },
  common_administration: { en: 'Administration', fr: 'Administration', ar: 'الادارة' },
  common_execution_space: { en: 'Execution Space', fr: 'Espace Execution', ar: 'مساحة التنفيذ' },
  common_notifications: { en: 'Notifications', fr: 'Notifications', ar: 'الاشعارات' },
  common_profile_updated_successfully: { en: 'Profile updated successfully', fr: 'Profil mis a jour avec succes', ar: 'تم تحديث الملف الشخصي بنجاح' },
  common_unable_to_update_profile: { en: 'Unable to update profile', fr: 'Impossible de mettre a jour le profil', ar: 'تعذر تحديث الملف الشخصي' },
  common_update_visible_identity: { en: 'Update your visible identity in the workspace.', fr: 'Mettez a jour votre identite visible dans l espace de travail.', ar: 'قم بتحديث هويتك الظاهرة في مساحة العمل.' },
  common_change_photo: { en: 'Change photo', fr: 'Changer la photo', ar: 'تغيير الصورة' },
  common_remove_photo: { en: 'Remove photo', fr: 'Retirer la photo', ar: 'ازالة الصورة' },
  common_full_name: { en: 'Full name', fr: 'Nom complet', ar: 'الاسم الكامل' },
  common_your_name: { en: 'Your name', fr: 'Votre nom', ar: 'اسمك' },
  common_save_profile: { en: 'Save profile', fr: 'Enregistrer le profil', ar: 'حفظ الملف الشخصي' },
  common_saving: { en: 'Saving...', fr: 'Enregistrement...', ar: 'جار الحفظ...' },
  common_refresh_page: { en: 'Refresh page', fr: 'Actualiser la page', ar: 'تحديث الصفحة' },
  common_back_to_signin: { en: 'Back to sign in', fr: 'Retour a la connexion', ar: 'العودة الى تسجيل الدخول' },
  auth_setup_account: { en: 'Set up your account', fr: 'Configurer votre compte', ar: 'اعداد حسابك' },
  auth_choose_password: { en: 'Choose your password to activate your account.', fr: 'Choisissez votre mot de passe pour activer votre compte.', ar: 'اختر كلمة المرور لتفعيل حسابك.' },
  auth_validating_invitation: { en: 'Validating invitation link...', fr: 'Validation du lien d invitation...', ar: 'جار التحقق من رابط الدعوة...' },
  auth_invitation_for: { en: 'Invitation for', fr: 'Invitation pour', ar: 'دعوة من اجل' },
  auth_new_password: { en: 'New password', fr: 'Nouveau mot de passe', ar: 'كلمة مرور جديدة' },
  auth_confirm_password: { en: 'Confirm password', fr: 'Confirmer le mot de passe', ar: 'تأكيد كلمة المرور' },
  auth_reset_password: { en: 'Reset password', fr: 'Reinitialiser le mot de passe', ar: 'اعادة تعيين كلمة المرور' },
  auth_reset_password_help: { en: 'Enter your account email and choose a new password.', fr: 'Saisissez votre email et choisissez un nouveau mot de passe.', ar: 'ادخل بريدك الالكتروني واختر كلمة مرور جديدة.' },
  common_role_label: { en: 'Role:', fr: 'Role :', ar: 'الدور:' },
  app_brand_subtitle: { en: 'QA workflow studio', fr: 'Studio de workflow QA', ar: 'استوديو سير عمل QA' },
  projects_create: { en: 'Create a project', fr: 'Creer un projet', ar: 'انشاء مشروع' },
  projects_search: { en: 'Search a project...', fr: 'Rechercher un projet...', ar: 'ابحث عن مشروع...' },
  projects_show_filters: { en: 'Show filters', fr: 'Afficher les filtres', ar: 'اظهار عوامل التصفية' },
  projects_hide_filters: { en: 'Hide filters', fr: 'Masquer les filtres', ar: 'اخفاء عوامل التصفية' },
  projects_applied_filters: { en: 'Applied filters :', fr: 'Filtres appliques :', ar: 'عوامل التصفية المطبقة:' },
  projects_loading: { en: 'Loading projects...', fr: 'Chargement des projets...', ar: 'جار تحميل المشاريع...' },
  projects_no_match: { en: 'No project matches the selected filters.', fr: 'Aucun projet ne correspond aux filtres selectionnes.', ar: 'لا يوجد مشروع يطابق عوامل التصفية المحددة.' },
  stories_import_title: { en: 'Import User Stories', fr: 'Importer des User Stories', ar: 'استيراد قصص المستخدم' },
  stories_import_help: { en: 'Import CSV, XLSX or JSON with preview, validation, and error reporting.', fr: 'Import CSV, XLSX ou JSON avec apercu, validation et rapport d erreurs.', ar: 'استورد CSV او XLSX او JSON مع معاينة وتحقق وتقرير اخطاء.' },
  stories_form_workspace: { en: 'User Story Workspace', fr: 'Espace User Story', ar: 'مساحة قصة المستخدم' },
  stories_form_new: { en: 'New user story', fr: 'Nouvelle user story', ar: 'قصة مستخدم جديدة' },
  stories_form_edit: { en: 'Edit user story', fr: 'Editer la user story', ar: 'تعديل قصة المستخدم' },
})

const PATTERN_TRANSLATORS = [
  {
    test: /^page (\d+) sur (\d+)$/i,
    translate: (m, language) => ({
      en: `Page ${m[1]} of ${m[2]}`,
      fr: `Page ${m[1]} sur ${m[2]}`,
      ar: `الصفحة ${m[1]} من ${m[2]}`,
    })[language],
  },
  {
    test: /^(\d+) checklist\(s\)$/i,
    translate: (m, language) => ({
      en: `${m[1]} checklist(s)`,
      fr: `${m[1]} checklist(s)`,
      ar: `${m[1]} قائمة تحقق`,
    })[language],
  },
  {
    test: /^(\d+) test\(s\) ont echoue recemment$/i,
    translate: (m, language) => ({
      en: `${m[1]} test(s) failed recently`,
      fr: `${m[1]} test(s) ont echoue recemment`,
      ar: `${m[1]} اختبار/اختبارات فشلت مؤخرا`,
    })[language],
  },
  {
    test: /^(\d+) element\(s\) critique\(s\) sont en echec$/i,
    translate: (m, language) => ({
      en: `${m[1]} critical item(s) are failing`,
      fr: `${m[1]} element(s) critique(s) sont en echec`,
      ar: `${m[1]} عنصر/عناصر حرجة في حالة فشل`,
    })[language],
  },
  {
    test: /^(\d+) user stor(?:y|ies) importee(?:s)?$/i,
    translate: (m, language) => ({
      en: `${m[1]} imported user stor${m[1] === '1' ? 'y' : 'ies'}`,
      fr: `${m[1]} User Story${m[1] === '1' ? '' : 'ies'} importee${m[1] === '1' ? '' : 's'}`,
      ar: `${m[1]} قصة مستخدم مستوردة`,
    })[language],
  },
  {
    test: /^(\d+) ligne\(s\) ignoree\(s\)$/i,
    translate: (m, language) => ({
      en: `${m[1]} ignored line(s)`,
      fr: `${m[1]} ligne(s) ignoree(s)`,
      ar: `${m[1]} سطر/اسطر تم تجاهلها`,
    })[language],
  },
]

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
    const normalizedSource = normalize(sourceText)

    for (const pattern of PATTERN_TRANSLATORS) {
      const match = normalizedSource.match(pattern.test)
      if (match) {
        return pattern.translate(match, language) || sourceText
      }
    }

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
  const attrs = ['placeholder', 'title', 'aria-label', 'alt']

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
    document.documentElement.lang = settings.language
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
    characterData: true,
    attributes: true,
    attributeFilter: ['placeholder', 'title', 'aria-label', 'alt', 'value'],
  })
}

export function translatePhrase(sourceText, language) {
  return translateKnownPhrase(sourceText, language)
}

export function translateCurrentPhrase(sourceText) {
  const lang =
    (typeof document !== 'undefined' && document.documentElement?.lang) ||
    'fr'

  return translateKnownPhrase(sourceText, lang)
}
