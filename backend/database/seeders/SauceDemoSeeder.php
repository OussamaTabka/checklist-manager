<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\User;
use App\Models\UserStory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * SauceDemoSeeder
 * ---------------
 * Recharge le referentiel fonctionnel avec un projet unique base sur
 * https://www.saucedemo.com/.
 */
class SauceDemoSeeder extends Seeder
{
    private int $ownerId;

    private Carbon $now;

    public function run(): void
    {
        $this->now = Carbon::now();
        $this->ownerId = $this->resolveOwnerId();

        DB::transaction(function (): void {
            $this->wipeReferentiel();

            $project = $this->createProject();
            $userStories = $this->createUserStories($project->id);
            $checklists = $this->createChecklists($project->id, $userStories);

            $this->attachChecklistsToProject($project, $checklists);
            $this->attachChecklistsToUserStories($userStories, $checklists);
            $this->createTestCases($checklists);
        });
    }

    private function resolveOwnerId(): int
    {
        $owner = DB::table('users')
            ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('roles.name', ['chef', 'admin'])
            ->orderByRaw("CASE WHEN roles.name = 'chef' THEN 0 WHEN roles.name = 'admin' THEN 1 ELSE 2 END")
            ->orderBy('users.id')
            ->value('users.id');

        if ($owner === null) {
            throw new RuntimeException(
                "SauceDemoSeeder: aucun utilisateur avec le role 'chef' ou 'admin' n'a ete trouve."
            );
        }

        return (int) $owner;
    }

    private function wipeReferentiel(): void
    {
        $tables = [
            'test_results',
            'test_runs',
            'item_changes',
            'comments',
            'checklist_item_history',
            'version_items',
            'project_versions',
            'agent_benchmark_cases',
            'project_checklists',
            'user_story_checklists',
            'checklist_items',
            'checklists',
            'user_stories',
            'project_testers',
            'projects',
        ];

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function createProject(): Project
    {
        return Project::updateOrCreate(
            ['name' => 'SauceDemo — Swag Labs'],
            [
                'description' => 'Application de demonstration QA fournie par Sauce Labs. Boutique e-commerce fictive utilisee pour valider les parcours standards et les comportements degrages.',
                'test_objectives' => 'Valider les parcours critiques de bout en bout: authentification, navigation produit, gestion du panier, tunnel de commande et deconnexion.',
                'app_url' => 'https://www.saucedemo.com/',
                'created_by' => $this->ownerId,
                'start_date' => $this->now->toDateString(),
                'status' => 'active',
            ]
        );
    }

    /**
     * @return array<string, int>
     */
    private function createUserStories(int $projectId): array
    {
        $stories = [
            [
                'story_id' => 'US-001',
                'title' => 'Authentification',
                'as_a' => 'Client de Swag Labs',
                'i_want_that' => 'pouvoir me connecter avec mes identifiants',
                'so_that' => 'accéder au catalogue et passer commande',
                'business_rules' => "Le mot de passe par défaut est secret_sauce pour tous les comptes. locked_out_user doit être refusé. Une connexion réussie redirige vers /inventory.html.",
                'priority' => 'critical',
            ],
            [
                'story_id' => 'US-002',
                'title' => 'Catalogue et tri des produits',
                'as_a' => 'Client connecté',
                'i_want_that' => 'consulter le catalogue et trier les produits',
                'so_that' => 'trouver rapidement les articles qui m intéressent',
                'business_rules' => "Le catalogue contient exactement 6 produits. Le tri propose 4 options: Name (A to Z), Name (Z to A), Price (low to high), Price (high to low).",
                'priority' => 'high',
            ],
            [
                'story_id' => 'US-003',
                'title' => 'Gestion du panier',
                'as_a' => 'Client connecté',
                'i_want_that' => 'ajouter et retirer des produits de mon panier',
                'so_that' => 'préparer ma commande avant de la valider',
                'business_rules' => "Le badge du panier affiche le nombre d'articles. Quand le panier est vide, aucun badge n'est visible.",
                'priority' => 'high',
            ],
            [
                'story_id' => 'US-004',
                'title' => 'Tunnel de commande',
                'as_a' => 'Client avec un panier non vide',
                'i_want_that' => 'saisir mes informations de livraison et finaliser ma commande',
                'so_that' => 'recevoir une confirmation de mon achat',
                'business_rules' => "Le tunnel se déroule en 3 étapes: checkout-step-one, checkout-step-two et checkout-complete.",
                'priority' => 'critical',
            ],
            [
                'story_id' => 'US-005',
                'title' => 'Navigation et déconnexion',
                'as_a' => 'Client connecté',
                'i_want_that' => 'accéder au menu latéral et me déconnecter',
                'so_that' => 'quitter ma session en toute sécurité',
                'business_rules' => "Le menu burger propose All Items, About, Logout et Reset App State.",
                'priority' => 'medium',
            ],
            [
                'story_id' => 'US-006',
                'title' => 'Comptes spéciaux et comportements dégradés',
                'as_a' => 'Système de tests',
                'i_want_that' => 'détecter le comportement des comptes spéciaux',
                'so_that' => 'garantir la robustesse et la sécurité de l accès',
                'business_rules' => "Comptes spéciaux: locked_out_user, problem_user, performance_glitch_user, error_user et visual_user.",
                'priority' => 'medium',
            ],
        ];

        $map = [];

        foreach ($stories as $story) {
            $userStory = UserStory::updateOrCreate(
                [
                    'project_id' => $projectId,
                    'story_id' => $story['story_id'],
                ],
                [
                    'title' => $story['title'],
                    'description' => $story['i_want_that'],
                    'as_a' => $story['as_a'],
                    'i_want_that' => $story['i_want_that'],
                    'so_that' => $story['so_that'],
                    'business_rules' => $story['business_rules'],
                    'status' => 'ready_for_test',
                    'priority' => $story['priority'],
                    'created_by' => $this->ownerId,
                ]
            );

            $map[$story['story_id']] = $userStory->id;
        }

        return $map;
    }

    /**
     * @param array<string, int> $userStoryIds
     * @return array<string, int>
     */
    private function createChecklists(int $projectId, array $userStoryIds): array
    {
        $definitions = [
            'CL-001' => [
                'story_id' => 'US-001',
                'name' => 'Authentification — Swag Labs',
                'description' => 'Valide les scénarios de connexion: nominal, refus, champs vides et utilisateur lent.',
                'category' => 'Authentification',
                'priority' => 'critical',
            ],
            'CL-002' => [
                'story_id' => 'US-002',
                'name' => 'Catalogue et tri',
                'description' => 'Vérifie l affichage des 6 produits, les 4 options de tri et l accès au détail.',
                'category' => 'Catalogue',
                'priority' => 'high',
            ],
            'CL-003' => [
                'story_id' => 'US-003',
                'name' => 'Gestion du panier',
                'description' => 'Ajout, retrait, badge, navigation depuis et vers la page panier.',
                'category' => 'Panier',
                'priority' => 'high',
            ],
            'CL-004' => [
                'story_id' => 'US-004',
                'name' => 'Tunnel de commande',
                'description' => 'Parcours complet et validation des champs obligatoires du formulaire de livraison.',
                'category' => 'Commande',
                'priority' => 'critical',
            ],
            'CL-005' => [
                'story_id' => 'US-005',
                'name' => 'Navigation et déconnexion',
                'description' => 'Menu burger, déconnexion, reset de l état applicatif.',
                'category' => 'Navigation',
                'priority' => 'medium',
            ],
            'CL-006' => [
                'story_id' => 'US-006',
                'name' => 'Comptes spéciaux',
                'description' => 'Couvre les comportements des comptes problem_user, performance_glitch_user, error_user et visual_user.',
                'category' => 'Robustesse',
                'priority' => 'medium',
            ],
        ];

        $map = [];

        foreach ($definitions as $code => $definition) {
            $checklist = Checklist::updateOrCreate(
                [
                    'project_id' => $projectId,
                    'name' => $definition['name'],
                ],
                [
                    'description' => $definition['description'],
                    'project_id' => $projectId,
                    'as_a' => $this->storyAttribute($definition['story_id'], 'as_a', $userStoryIds),
                    'i_want_that' => $this->storyAttribute($definition['story_id'], 'i_want_that', $userStoryIds),
                    'so_that' => $this->storyAttribute($definition['story_id'], 'so_that', $userStoryIds),
                    'priority' => $definition['priority'],
                    'status' => 'ready_for_test',
                    'category' => $definition['category'],
                    'is_active' => true,
                    'created_by' => $this->ownerId,
                    'template_scope' => 'project',
                    'lifecycle_status' => 'approved',
                    'generated_from' => 'manual',
                    'source_user_story_id' => $userStoryIds[$definition['story_id']],
                ]
            );

            $map[$code] = $checklist->id;
        }

        return $map;
    }

    /**
     * @param array<string, int> $checklistIds
     */
    private function attachChecklistsToProject(Project $project, array $checklistIds): void
    {
        $project->checklists()->syncWithoutDetaching(array_values($checklistIds));
    }

    /**
     * @param array<string, int> $userStoryIds
     * @param array<string, int> $checklistIds
     */
    private function attachChecklistsToUserStories(array $userStoryIds, array $checklistIds): void
    {
        $pairs = [
            ['US-001', 'CL-001'],
            ['US-002', 'CL-002'],
            ['US-003', 'CL-003'],
            ['US-004', 'CL-004'],
            ['US-005', 'CL-005'],
            ['US-006', 'CL-006'],
        ];

        foreach ($pairs as [$storyCode, $checklistCode]) {
            UserStory::query()
                ->whereKey($userStoryIds[$storyCode])
                ->firstOrFail()
                ->checklists()
                ->syncWithoutDetaching([
                    $checklistIds[$checklistCode] => [
                        'is_generated_from_arxis' => false,
                        'relevance_score' => 100,
                        'link_type' => 'attached',
                    ],
                ]);
        }
    }

    /**
     * @param array<string, int> $checklistIds
     */
    private function createTestCases(array $checklistIds): void
    {
        $allCases = array_merge(
            $this->casesForAuthentication($checklistIds['CL-001']),
            $this->casesForCatalog($checklistIds['CL-002']),
            $this->casesForCart($checklistIds['CL-003']),
            $this->casesForCheckout($checklistIds['CL-004']),
            $this->casesForNavigation($checklistIds['CL-005']),
            $this->casesForSpecialAccounts($checklistIds['CL-006']),
        );

        foreach ($allCases as $case) {
            ChecklistItem::updateOrCreate(
                [
                    'checklist_id' => $case['checklist_id'],
                    'title' => $case['title'],
                ],
                $case
            );
        }
    }

    private function storyAttribute(string $storyId, string $attribute, array $userStoryIds): ?string
    {
        $story = UserStory::find($userStoryIds[$storyId]);

        return $story?->{$attribute};
    }

    private function buildCase(
        int $checklistId,
        string $title,
        string $description,
        string $priority = 'Medium',
        string $criticality = 'Major',
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'criticality' => $criticality,
            'checklist_id' => $checklistId,
            'status' => 'pending',
            'tested_by' => null,
            'tested_at' => null,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ];
    }

    private function casesForAuthentication(int $checklistId): array
    {
        return [
            $this->buildCase(
                $checklistId,
                'TC-001 — Connexion utilisateur standard',
                "Vérifier qu'un utilisateur standard se connecte avec les identifiants par défaut du projet (standard_user / secret_sauce) et arrive sur la page inventaire (/inventory.html). L'en-tête doit afficher 'Swag Labs' et au moins un produit doit être visible.",
                'High',
                'Major',
            ),
            $this->buildCase(
                $checklistId,
                'TC-002 — Refus utilisateur verrouillé',
                "Vérifier que la connexion avec locked_out_user / secret_sauce est refusée. Le message d'erreur 'Epic sadface: Sorry, this user has been locked out.' doit s'afficher. L'utilisateur ne doit PAS atteindre la page inventaire.",
                'High',
                'Major',
            ),
            $this->buildCase(
                $checklistId,
                'TC-003 — Mot de passe invalide',
                "Vérifier qu'une tentative de connexion avec un mot de passe incorrect (standard_user / wrong_password) affiche le message 'Username and password do not match any user in this service'.",
                'Medium',
                'Minor',
            ),
            $this->buildCase(
                $checklistId,
                'TC-004 — Champs vides',
                "Vérifier que la soumission du formulaire avec les deux champs vides affiche le message d'erreur 'Username is required'.",
                'Low',
                'Minor',
            ),
            $this->buildCase(
                $checklistId,
                'TC-005 — Connexion utilisateur lent',
                "Vérifier que performance_glitch_user finit par se connecter malgré un délai anormalement long (environ 5 secondes).",
                'Medium',
                'Minor',
            ),
        ];
    }

    private function casesForCatalog(int $checklistId): array
    {
        return [
            $this->buildCase($checklistId, 'TC-006 — Affichage des 6 produits', "Après connexion standard, vérifier que la page inventaire affiche exactement 6 produits.", 'Medium', 'Major'),
            $this->buildCase($checklistId, 'TC-007 — Tri par nom croissant (A → Z)', "Sélectionner l'option 'Name (A to Z)' dans le menu de tri.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-008 — Tri par nom décroissant (Z → A)', "Sélectionner l'option 'Name (Z to A)' dans le menu de tri.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-009 — Tri par prix croissant', "Sélectionner l'option 'Price (low to high)' dans le menu de tri.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-010 — Tri par prix décroissant', "Sélectionner l'option 'Price (high to low)' dans le menu de tri.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, "TC-011 — Accès au détail d'un produit", "Cliquer sur le nom du produit 'Sauce Labs Backpack'.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-012 — Retour depuis le détail produit', "Depuis la page de détail d'un produit, cliquer sur le bouton 'Back to products'.", 'Low', 'Minor'),
        ];
    }

    private function casesForCart(int $checklistId): array
    {
        return [
            $this->buildCase($checklistId, "TC-013 — Ajout d'un produit au panier", "Depuis la page inventaire, cliquer sur 'Add to cart' pour le produit 'Sauce Labs Backpack'.", 'High', 'Major'),
            $this->buildCase($checklistId, 'TC-014 — Ajout de plusieurs produits', 'Ajouter au panier 3 produits différents depuis la page inventaire.', 'Medium', 'Major'),
            $this->buildCase($checklistId, "TC-015 — Retrait d'un produit depuis l'inventaire", "Ajouter le produit 'Sauce Labs Backpack', puis cliquer sur 'Remove'.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-016 — Accès à la page panier', "Ajouter un produit, puis cliquer sur l'icône panier en haut à droite.", 'High', 'Major'),
            $this->buildCase($checklistId, 'TC-017 — Retrait depuis la page panier', "Avec un produit dans le panier, accéder à /cart.html et cliquer sur le bouton 'Remove'.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-018 — Continuer les achats', "Depuis la page panier, cliquer sur le bouton 'Continue Shopping'.", 'Low', 'Minor'),
        ];
    }

    private function casesForCheckout(int $checklistId): array
    {
        return [
            $this->buildCase($checklistId, 'TC-019 — Tunnel de commande complet (parcours nominal)', "Avec un produit dans le panier, remplir le formulaire de livraison puis cliquer 'Finish'.", 'High', 'Major'),
            $this->buildCase($checklistId, 'TC-020 — Validation du champ First Name obligatoire', 'Soumettre le formulaire en laissant First Name vide.', 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-021 — Validation du champ Last Name obligatoire', 'Soumettre le formulaire en laissant Last Name vide.', 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-022 — Validation du champ Postal Code obligatoire', 'Soumettre le formulaire en laissant Postal Code vide.', 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-023 — Vérification de la page Overview', "Atteindre /checkout-step-two.html après avoir saisi des informations valides.", 'Medium', 'Major'),
            $this->buildCase($checklistId, 'TC-024 — Annulation du checkout', "Depuis /checkout-step-one.html, cliquer sur le bouton 'Cancel'.", 'Low', 'Minor'),
            $this->buildCase($checklistId, 'TC-025 — Bouton Back Home après confirmation', "Après avoir finalisé une commande, cliquer sur le bouton 'Back Home'.", 'Low', 'Minor'),
        ];
    }

    private function casesForNavigation(int $checklistId): array
    {
        return [
            $this->buildCase($checklistId, 'TC-026 — Ouverture du menu burger', "Cliquer sur l'icône du menu burger en haut à gauche.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-027 — Déconnexion', "Ouvrir le menu burger et cliquer sur 'Logout'.", 'High', 'Major'),
            $this->buildCase($checklistId, 'TC-028 — Reset App State', "Ajouter 2 produits au panier, ouvrir le menu burger et cliquer sur 'Reset App State'.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-029 — Navigation All Items', "Depuis /cart.html, ouvrir le menu burger et cliquer sur 'All Items'.", 'Low', 'Minor'),
            $this->buildCase($checklistId, 'TC-030 — Présence du lien About', "Après connexion, ouvrir le menu burger et vérifier que l'entrée 'About' est présente.", 'Low', 'Minor'),
        ];
    }

    private function casesForSpecialAccounts(int $checklistId): array
    {
        return [
            $this->buildCase($checklistId, 'TC-031 — problem_user — images de produits cassées', "Se connecter avec problem_user / secret_sauce et vérifier que les images pointent vers une URL contenant 'sl-404'.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-032 — problem_user — Last Name non éditable au checkout', "Se connecter avec problem_user, ajouter un produit et atteindre /checkout-step-one.html.", 'Medium', 'Minor'),
            $this->buildCase($checklistId, 'TC-033 — error_user — erreur sur le retrait du panier', "Se connecter avec error_user, ajouter 'Sauce Labs Backpack' au panier puis cliquer sur 'Remove'.", 'Low', 'Minor'),
            $this->buildCase($checklistId, 'TC-034 — visual_user — décalage visuel détectable', "Se connecter avec visual_user et vérifier qu'au moins un élément visuel présente un décalage par rapport au rendu attendu.", 'Low', 'Minor'),
        ];
    }
}