export const t = (key, lang = 'fr') => {
  const translations = {
    fr: {
      // Navigation
      'nav.dashboard': 'Tableau de Bord',
      'nav.projects': 'Projets',
      'nav.checklists': 'Listes de Vérification',
      'nav.stories': 'User Stories',
      'nav.users': 'Utilisateurs',
      'nav.settings': 'Paramètres',
      'nav.logout': 'Déconnexion',

      // Settings
      'settings.title': 'Paramètres',
      'settings.appearance': 'Apparence',
      'settings.language': 'Langue',
      'settings.darkMode': 'Mode Sombre',
      'settings.darkModeEnabled': 'Mode sombre activé',
      'settings.darkModeDisabled': 'Mode clair activé',
      'settings.selectLanguage': 'Sélectionner la langue',
      'settings.theme': 'Thème',
      'settings.systemDefault': 'Par défaut du système',

      // Common
      'common.save': 'Enregistrer',
      'common.cancel': 'Annuler',
      'common.delete': 'Supprimer',
      'common.edit': 'Éditer',
      'common.create': 'Créer',
      'common.add': 'Ajouter',
      'common.back': 'Retour',
      'common.search': 'Rechercher',
      'common.loading': 'Chargement...',
      'common.error': 'Erreur',
      'common.success': 'Succès',

      // User Stories
      'stories.title': 'User Stories',
      'stories.new': 'Nouvelle Story',
      'stories.edit': 'Éditer Story',
      'stories.delete': 'Supprimer Story',
      'stories.generate': 'Générer Checklist',
      'stories.generating': 'Génération en cours...',
      'stories.status': 'Statut',
      'stories.priority': 'Priorité',
      'stories.description': 'Description',
      'stories.criteria': 'Critères d\'Acceptation',

      // Checklists
      'checklists.title': 'Listes de Vérification',
      'checklists.new': 'Nouvelle Checklist',
      'checklists.items': 'Articles',
      'checklists.completed': 'Complété',
      'checklists.pending': 'En attente',

      // Projects
      'projects.title': 'Projets',
      'projects.new': 'Nouveau Projet',
      'projects.members': 'Membres',
      'projects.details': 'Détails du Projet',

      // Messages
      'msg.confirmDelete': 'Êtes-vous sûr de vouloir supprimer ?',
      'msg.saved': 'Enregistré avec succès',
      'msg.deleted': 'Supprimé avec succès',
      'msg.loading': 'Chargement...',
      'msg.error': 'Une erreur est survenue',
    },
    en: {
      // Navigation
      'nav.dashboard': 'Dashboard',
      'nav.projects': 'Projects',
      'nav.checklists': 'Checklists',
      'nav.stories': 'User Stories',
      'nav.users': 'Users',
      'nav.settings': 'Settings',
      'nav.logout': 'Logout',

      // Settings
      'settings.title': 'Settings',
      'settings.appearance': 'Appearance',
      'settings.language': 'Language',
      'settings.darkMode': 'Dark Mode',
      'settings.darkModeEnabled': 'Dark mode enabled',
      'settings.darkModeDisabled': 'Light mode enabled',
      'settings.selectLanguage': 'Select Language',
      'settings.theme': 'Theme',
      'settings.systemDefault': 'System Default',

      // Common
      'common.save': 'Save',
      'common.cancel': 'Cancel',
      'common.delete': 'Delete',
      'common.edit': 'Edit',
      'common.create': 'Create',
      'common.add': 'Add',
      'common.back': 'Back',
      'common.search': 'Search',
      'common.loading': 'Loading...',
      'common.error': 'Error',
      'common.success': 'Success',

      // User Stories
      'stories.title': 'User Stories',
      'stories.new': 'New Story',
      'stories.edit': 'Edit Story',
      'stories.delete': 'Delete Story',
      'stories.generate': 'Generate Checklist',
      'stories.generating': 'Generating...',
      'stories.status': 'Status',
      'stories.priority': 'Priority',
      'stories.description': 'Description',
      'stories.criteria': 'Acceptance Criteria',

      // Checklists
      'checklists.title': 'Checklists',
      'checklists.new': 'New Checklist',
      'checklists.items': 'Items',
      'checklists.completed': 'Completed',
      'checklists.pending': 'Pending',

      // Projects
      'projects.title': 'Projects',
      'projects.new': 'New Project',
      'projects.members': 'Members',
      'projects.details': 'Project Details',

      // Messages
      'msg.confirmDelete': 'Are you sure you want to delete?',
      'msg.saved': 'Saved successfully',
      'msg.deleted': 'Deleted successfully',
      'msg.loading': 'Loading...',
      'msg.error': 'An error occurred',
    },
    ar: {
      // Navigation
      'nav.dashboard': 'لوحة التحكم',
      'nav.projects': 'المشاريع',
      'nav.checklists': 'قوائم التحقق',
      'nav.stories': 'قصص المستخدم',
      'nav.users': 'المستخدمون',
      'nav.settings': 'الإعدادات',
      'nav.logout': 'تسجيل الخروج',

      // Settings
      'settings.title': 'الإعدادات',
      'settings.appearance': 'المظهر',
      'settings.language': 'اللغة',
      'settings.darkMode': 'وضع مظلم',
      'settings.darkModeEnabled': 'تم تفعيل الوضع المظلم',
      'settings.darkModeDisabled': 'تم تفعيل الوضع الفاتح',
      'settings.selectLanguage': 'اختر اللغة',
      'settings.theme': 'المظهر',
      'settings.systemDefault': 'الإعدادات الافتراضية للنظام',

      // Common
      'common.save': 'حفظ',
      'common.cancel': 'إلغاء',
      'common.delete': 'حذف',
      'common.edit': 'تحرير',
      'common.create': 'إنشاء',
      'common.add': 'إضافة',
      'common.back': 'رجوع',
      'common.search': 'بحث',
      'common.loading': 'جاري التحميل...',
      'common.error': 'خطأ',
      'common.success': 'نجاح',

      // User Stories
      'stories.title': 'قصص المستخدم',
      'stories.new': 'قصة جديدة',
      'stories.edit': 'تعديل القصة',
      'stories.delete': 'حذف القصة',
      'stories.generate': 'إنشاء قائمة التحقق',
      'stories.generating': 'جاري الإنشاء...',
      'stories.status': 'الحالة',
      'stories.priority': 'الأولوية',
      'stories.description': 'الوصف',
      'stories.criteria': 'معايير القبول',

      // Checklists
      'checklists.title': 'قوائم التحقق',
      'checklists.new': 'قائمة تحقق جديدة',
      'checklists.items': 'العناصر',
      'checklists.completed': 'مكتمل',
      'checklists.pending': 'قيد الانتظار',

      // Projects
      'projects.title': 'المشاريع',
      'projects.new': 'مشروع جديد',
      'projects.members': 'الأعضاء',
      'projects.details': 'تفاصيل المشروع',

      // Messages
      'msg.confirmDelete': 'هل أنت متأكد من أنك تريد الحذف؟',
      'msg.saved': 'تم الحفظ بنجاح',
      'msg.deleted': 'تم الحذف بنجاح',
      'msg.loading': 'جاري التحميل...',
      'msg.error': 'حدث خطأ',
    },
  }

  return translations[lang]?.[key] || key
}

// Helper function to use in components
export function useTranslations(currentLang) {
  return (key) => t(key, currentLang)
}
