# Structure des Rôles et Permissions

## Vue d'Ensemble

Le système utilise une hiérarchie de rôles basée sur le **principe du moindre privilège**, avec une **séparation claire des responsabilités** entre l'infrastructure et le métier.

---

## Rôles Définis

### 1. **ADMINISTRATEUR SYSTÈME** (admin)

**Responsabilités :**
- Gestion complète des utilisateurs (création, modification, suppression)
- Gestion des rôles et permissions
- Accès aux logs et audit trail
- Maintenance système
- Configuration générale

**Permissions :**
- ✅ Gérer les utilisateurs (CRUD)
- ✅ Assigner les rôles
- ✅ Consulter les logs/audit
- ❌ Créer/Modifier/Supprimer des checklists
- ❌ Créer/Modifier/Supprimer des projets
- ❌ Exécuter des tests

**Routes Autorisées :**
- GET/POST `/users` - Gestion des utilisateurs
- GET/PUT/DELETE `/users/{id}` - CRUD utilisateurs

**Raison :** Séparation claire entre Infrastructure (Admin) et Métier (Chef)

---

### 2. **CHEF DE PROJET** (chef)

**Responsabilités :**
- Gestion complète des projets
- Gestion complète des templates de checklists
- Gestion des versions
- Assignation des testeurs
- Instanciation des checklists

**Permissions :**
- ✅ Créer/Modifier/Supprimer des templates de checklists
- ✅ Ajouter/Modifier/Supprimer des items
- ✅ Gérer les versions des checklists
- ✅ Créer/Modifier/Supprimer des projets
- ✅ Associer des checklists aux projets
- ✅ Générer des instances de checklists
- ✅ Assigner des testeurs
- ✅ Exécuter les tests manuellement
- ✅ Consulter le dashboard et statistiques
- ❌ Gérer les rôles/permissions
- ❌ Accéder aux logs système

**Routes Autorisées :**
- GET/POST `/checklists` - Gestion des checklists
- GET/PUT/DELETE `/checklists/{id}` - CRUD checklists
- GET/POST `/projects` - Gestion des projets
- GET/PUT/DELETE `/projects/{id}` - CRUD projets
- POST `/projects/{id}/versions` - Créer les versions
- PATCH `/version-items/{id}/status` - Mettre à jour le statut
- GET/POST `/version-items/{id}/comments` - Gérer les commentaires

---

### 3. **ADMINISTRATEUR CONTENUS** (admin_contenus) - *Optionnel*

**Responsabilités :**
- Vérification de la qualité des checklists
- Création de checklists système globales
- Gestion des checklists obsolètes
- Audit des modifications

**Permissions :**
- ✅ Hérite de toutes les permissions du Chef de Projet
- ✅ Créer des checklists système (visibilité globale)
- ✅ Archiver/Désactiver des checklists
- ✅ Consulter les audit trails des checklists
- ✅ Exécuter les tests manuellement
- ❌ Gérer les utilisateurs
- ❌ Gérer les rôles/permissions

**Routes Autorisées :**
- GET/POST `/checklists` - Gestion des checklists
- GET/PUT/DELETE `/checklists/{id}` - CRUD checklists
- PATCH `/version-items/{id}/status` - Mettre à jour le statut
- GET/POST `/version-items/{id}/comments` - Gérer les commentaires

---

### 4. **TESTEUR** (testeur)

**Responsabilités :**
- Exécution des tests
- Documentation des résultats
- Ajout de commentaires

**Permissions :**
- ✅ Visualiser les checklists assignées
- ✅ Exécuter les checklists
- ✅ Marquer les items (Passed/Failed/Pending/Blocked)
- ✅ Ajouter des commentaires
- ✅ Visualiser sa propre progression
- ❌ Créer/Modifier des checklists
- ❌ Gérer les projets
- ❌ Créer des instances
- ❌ Accéder aux statistiques globales

**Routes Autorisées :**
- GET `/project-versions/{id}` - Visualiser les checklists
- PATCH `/version-items/{id}/status` - Marquer les items
- GET/POST `/version-items/{id}/comments` - Ajouter des commentaires
- GET `/project-versions/{id}/progress` - Voir sa progression

---

## Matrice de Permissions Complète

| Fonctionnalité | Admin | Chef | Admin Contenus | Testeur |
|---|:---:|:---:|:---:|:---:|
| **AUTHENTIFICATION** | | | | |
| Se connecter/déconnecter | ✅ | ✅ | ✅ | ✅ |
| **GESTION UTILISATEURS** | | | | |
| Créer utilisateur | ✅ | ❌ | ❌ | ❌ |
| Modifier utilisateur | ✅ | ❌ | ❌ | ❌ |
| Supprimer utilisateur | ✅ | ❌ | ❌ | ❌ |
| Assigner rôle | ✅ | ❌ | ❌ | ❌ |
| **GESTION RÔLES/PERMISSIONS** | | | | |
| Créer rôle | ✅ | ❌ | ❌ | ❌ |
| Modifier rôle | ✅ | ❌ | ❌ | ❌ |
| Gérer permissions | ✅ | ❌ | ❌ | ❌ |
| **CHECKLISTS** | | | | |
| Créer template | ❌ | ✅ | ✅ | ❌ |
| Modifier template | ❌ | ✅ | ✅ | ❌ |
| Supprimer template | ❌ | ✅ | ✅ | ❌ |
| Ajouter items | ❌ | ✅ | ✅ | ❌ |
| Modifier items | ❌ | ✅ | ✅ | ❌ |
| Supprimer items | ❌ | ✅ | ✅ | ❌ |
| Gérer versions | ❌ | ✅ | ✅ | ❌ |
| Créer checklist système | ❌ | ❌ | ✅ | ❌ |
| **PROJETS** | | | | |
| Créer projet | ❌ | ✅ | ❌ | ❌ |
| Modifier projet | ❌ | ✅ | ❌ | ❌ |
| Supprimer projet | ❌ | ✅ | ❌ | ❌ |
| Associer checklist | ❌ | ✅ | ❌ | ❌ |
| Générer instance | ❌ | ✅ | ❌ | ❌ |
| Assigner testeur | ❌ | ✅ | ❌ | ❌ |
| **EXÉCUTION TESTS** | | | | |
| Visualiser checklist | ❌ | ✅ | ✅ | ✅ |
| Exécuter checklist | ❌ | ✅ | ✅ | ✅ |
| Marquer items | ❌ | ✅ | ✅ | ✅ |
| Ajouter commentaires | ❌ | ✅ | ✅ | ✅ |
| Voir sa progression | ❌ | ✅ | ✅ | ✅ |
| **DASHBOARD/RAPPORTS** | | | | |
| Dashboard global | ✅ | ✅ | ✅ | ❌ |
| Dashboard personnel | ✅ | ✅ | ✅ | ⚠️ Limité |
| Statistiques | ✅ | ✅ | ✅ | ❌ |
| Exporter données | ✅ | ✅ | ✅ | ⚠️ Ses données |
| **AUDIT/LOGS** | | | | |
| Consulter logs | ✅ | ❌ | ⚠️ Checklists | ❌ |
| Audit trail | ✅ | ❌ | ⚠️ Checklists | ❌ |
| **SYSTÈME** | | | | |
| Configuration système | ✅ | ❌ | ❌ | ❌ |

---

## Utilisateurs par Défaut

Lors du seeding initial, les utilisateurs suivants sont créés :

| Email | Nom | Rôle | Mot de passe |
|---|---|---|---|
| admin@test.com | Admin Système | admin | password123 |
| chef@test.com | Chef de Projet | chef | password123 |
| admin_contenus@test.com | Admin Contenus | admin_contenus | password123 |
| testeur@test.com | Testeur | testeur | password123 |

**⚠️ Important :** Changez ces mots de passe en production !

---

## Implémentation

### Backend (Laravel)

**Seeder : `database/seeders/RolesAndAdminSeeder.php`**
```php
Role::firstOrCreate(['name' => 'admin']);
Role::firstOrCreate(['name' => 'chef']);
Role::firstOrCreate(['name' => 'admin_contenus']);
Role::firstOrCreate(['name' => 'testeur']);
```

**Routes : `routes/api.php`**
```php
// Admin only
Route::middleware('role:admin')->group(function () {
    Route::resource('users', UserController::class);
});

// Chef & Admin Contenus
Route::middleware('role:chef|admin_contenus')->group(function () {
    Route::resource('checklists', ChecklistController::class);
});

// Chef only
Route::middleware('role:chef')->group(function () {
    Route::resource('projects', ProjectController::class);
});

// All authenticated
Route::middleware('role:chef|admin_contenus|testeur')->group(function () {
    // View project versions and manage execution statuses
    Route::get('/project-versions/{id}', [ProjectVersionController::class, 'show']);
    Route::patch('/version-items/{id}/status', [VersionItemController::class, 'updateStatus']);
});
```

### Frontend (Vue.js)

**Auth Store : `src/stores/auth.js`**
```javascript
// Vérifier le rôle
isSystemAdmin // true si utilisateur est admin
isProjectManager // true si utilisateur est chef
isContentAdmin // true si utilisateur est admin_contenus
isTester // true si utilisateur est testeur

// Vérifier les permissions
canManageProjects // true pour chef uniquement
canManageChecklists // true pour chef et admin_contenus
canManageUsers // true pour admin uniquement
canTest // true pour chef, admin_contenus et testeur
```

**Router : `src/router/index.js`**
```javascript
{
  path: '/checklists',
  name: 'checklists',
  component: ChecklistsView,
  meta: { requiresAuth: true, roles: ['chef', 'admin_contenus'] }
}
```

---

## Workflow Typiques par Rôle

### Admin Système
1. Créer les utilisateurs
2. Assigner les rôles
3. Consulter les logs et audit trails
4. Configurer le système

### Chef de Projet
1. Créer les projets
2. Créer les templates de checklists
3. Définir les versions
4. Assigner les testeurs
5. Exécuter les tests
6. Consulter les résultats

### Admin Contenus
1. Vérifier la qualité des checklists
2. Créer des checklists système globales
3. Archiver les checklists obsolètes
4. Exécuter les tests pour validation

### Testeur
1. Consulter les checklists assignées
2. Exécuter les tests
3. Marquer les items (Pass/Fail/etc)
4. Ajouter des commentaires
5. Consulter sa progression

---

## Évolution Future

Pour ajouter de nouveaux rôles :

1. **Créer le rôle dans le seeder :**
   ```php
   Role::firstOrCreate(['name' => 'nouveau_role']);
   ```

2. **Ajouter les permissions associées :**
   ```php
   $role->givePermissionTo(['permission1', 'permission2']);
   ```

3. **Mettre à jour les routes backend :**
   ```php
   Route::middleware('role:nouveau_role')->group(function () {
       // routes
   });
   ```

4. **Ajouter les computed properties frontend :**
   ```javascript
   const isNewRole = computed(() => roles.value.includes('nouveau_role'))
   ```

5. **Mettre à jour le router frontend :**
   ```javascript
   meta: { roles: ['nouveau_role'] }
   ```

---

## Références

- **Frontend Auth Store** : `frontend/src/stores/auth.js`
- **Backend Routes** : `backend/routes/api.php`
- **Backend Seeder** : `backend/database/seeders/RolesAndAdminSeeder.php`
- **UserController** : `backend/app/Http/Controllers/Api/UserController.php`
- **Frontend Router** : `frontend/src/router/index.js`
- **Users View** : `frontend/src/views/UsersView.vue`
