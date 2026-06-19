<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\User;
use App\Models\UserStory;
use App\Models\VersionItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AppleTunisieSeeder
 *
 * Jeu de données de démonstration pour le projet "Test du site Apple Tunisie".
 * Cible : https://www.apple.com/tn/
 *
 * Tous les cas de test sont conçus pour produire des scripts Playwright
 * exécutables sur le site public apple.com/tn (sans authentification).
 *
 * Idempotent — peut être rejoué sans créer de doublons.
 * Exécution : php artisan db:seed --class=AppleTunisieSeeder
 */
class AppleTunisieSeeder extends Seeder
{
    private const PROJECT_NAME = 'Test du site Apple Tunisie';
    private const APP_URL      = 'https://www.apple.com/tn/';

    private const RUN_IDS = [
        'run1' => 'aa200001-0001-4001-8001-000000000001',
        'run2' => 'aa200002-0002-4002-8002-000000000002',
        'run3' => 'aa200003-0003-4003-8003-000000000003',
        'run4' => 'aa200004-0004-4004-8004-000000000004',
        'run5' => 'aa200005-0005-4005-8005-000000000005',
        'run6' => 'aa200006-0006-4006-8006-000000000006',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // POINT D'ENTRÉE
    // ─────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        // ── 1. UTILISATEURS ───────────────────────────────────────────────────
        $admin   = $this->ensureUser('admin@intellitest.local',   'Administrateur PFE',  'admin');
        $raja    = $this->ensureUser('raja@intellitest.local',    'Raja Benali',         'chef');
        $yahia   = $this->ensureUser('yahia@intellitest.local',   'Yahia Ghoufa',        'testeur');
        $oussama = $this->ensureUser('oussama@intellitest.local', 'Oussama Meddour',     'testeur');

        // ── 2. PROJET ─────────────────────────────────────────────────────────
        $project = Project::updateOrCreate(
            ['name' => self::PROJECT_NAME],
            [
                'description'     => "Projet de validation qualité du site officiel Apple Tunisie (apple.com/tn).\n"
                    . "Ce projet couvre l'ensemble des fonctionnalités accessibles au public : "
                    . "chargement de la page d'accueil, navigation principale, cohérence inter-navigateurs, "
                    . "recherche de produits et conformité du pied de page.",
                'test_objectives' => "1. Valider le chargement et l'affichage correct de la page d'accueil Apple Tunisie.\n"
                    . "2. Assurer la cohérence de la navigation principale sur Chromium, Firefox et WebKit.\n"
                    . "3. Vérifier l'accessibilité de la recherche et des liens produits.\n"
                    . "4. Contrôler la conformité du pied de page et des mentions légales.\n"
                    . "5. Démontrer l'intégration complète du workflow QA automatisé sur IntelliTest.",
                'app_url'         => self::APP_URL,
                'start_date'      => '2026-06-01',
                'status'          => 'active',
                'created_by'      => $raja->id,
            ]
        );

        $project->testers()->syncWithoutDetaching([$yahia->id, $oussama->id]);

        // ── 3. USER STORIES ───────────────────────────────────────────────────

        $us1 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-APT-001'],
            [
                'title'                  => "Chargement et affichage de la page d'accueil Apple Tunisie",
                'description'            => "Permettre à tout visiteur de charger la page d'accueil Apple Tunisie "
                    . "(https://www.apple.com/tn/) et de voir les éléments essentiels de la marque : "
                    . "logo, barre de navigation, contenu principal et pied de page.",
                'as_a'                   => 'visiteur du site Apple Tunisie',
                'i_want_that'            => "la page d'accueil se charge correctement avec tous les éléments visuels Apple",
                'so_that'                => "je puisse naviguer et découvrir les produits et services proposés en Tunisie",
                'acceptance_criteria'    => "- La page https://www.apple.com/tn/ répond avec le code HTTP 200\n"
                    . "- Le titre de la page contient le mot 'Apple'\n"
                    . "- La barre de navigation principale est visible\n"
                    . "- Le logo Apple est affiché en haut de la page\n"
                    . "- Le contenu principal (hero/bannière) est chargé sans erreur\n"
                    . "- Le pied de page est présent et visible",
                'business_rules'         => [
                    "Le site doit être accessible en HTTPS sans erreur de certificat",
                    "Le temps de chargement initial ne doit pas dépasser 5 secondes sur connexion standard",
                    "Aucun lien cassé ou ressource 404 ne doit être présent sur la page d'accueil",
                ],
                'scenarios'              => [
                    ['titre' => 'Chargement nominal', 'etapes' => [
                        'Ouvrir https://www.apple.com/tn/ dans le navigateur',
                        'Vérifier le titre de la page',
                        'Vérifier la présence du logo Apple',
                        'Vérifier la barre de navigation',
                        'Vérifier le pied de page',
                    ]],
                    ['titre' => 'Présence des éléments de marque', 'etapes' => [
                        'Charger la page d\'accueil',
                        'Vérifier le logo Apple en haut de la page',
                        'Vérifier les liens navigation : iPhone, Mac, iPad, Watch',
                        'Vérifier la section hero principale',
                    ]],
                ],
                'effort_points'          => 3,
                'business_value'         => 13,
                'start_date'             => '2026-06-01',
                'target_completion_date' => '2026-06-07',
                'status'                 => 'completed',
                'priority'               => 'critical',
                'created_by'             => $raja->id,
            ]
        );

        $us2 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-APT-002'],
            [
                'title'                  => "Navigation principale et accès aux pages produits Apple",
                'description'            => "Permettre à l'utilisateur de naviguer via le menu principal d'Apple Tunisie "
                    . "vers les catégories de produits clés : iPhone, Mac, iPad, Apple Watch, Apple TV+ et Support.",
                'as_a'                   => 'visiteur intéressé par les produits Apple',
                'i_want_that'            => "je puisse accéder aux pages produits depuis le menu de navigation principal",
                'so_that'                => "je découvre et compare les produits Apple disponibles en Tunisie",
                'acceptance_criteria'    => "- Le menu de navigation est visible et contient iPhone, Mac, iPad, Watch, TV+, Support\n"
                    . "- Chaque lien du menu est cliquable et mène vers la page produit correspondante\n"
                    . "- La navigation retour vers la page d'accueil fonctionne via le logo Apple\n"
                    . "- Le menu reste accessible sur toute la largeur de la page",
                'business_rules'         => [
                    "Le menu de navigation doit rester visible lors du défilement (sticky nav)",
                    "Chaque lien du menu doit pointer vers une URL valide apple.com/tn",
                    "La navigation doit fonctionner sans JavaScript désactivé pour les liens simples",
                ],
                'scenarios'              => [
                    ['titre' => 'Navigation vers iPhone', 'etapes' => [
                        'Charger la page d\'accueil apple.com/tn',
                        'Cliquer sur le lien iPhone dans la navigation',
                        'Vérifier l\'arrivée sur la page iPhone Tunisie',
                    ]],
                    ['titre' => 'Navigation vers Mac', 'etapes' => [
                        'Charger la page d\'accueil apple.com/tn',
                        'Cliquer sur le lien Mac dans la navigation',
                        'Vérifier l\'arrivée sur la page Mac Tunisie',
                    ]],
                    ['titre' => 'Vérification du menu complet', 'etapes' => [
                        'Charger la page d\'accueil',
                        'Vérifier la présence de tous les items de navigation',
                        'Vérifier l\'accessibilité de chaque lien',
                    ]],
                ],
                'effort_points'          => 5,
                'business_value'         => 21,
                'start_date'             => '2026-06-04',
                'target_completion_date' => '2026-06-10',
                'status'                 => 'ready_for_test',
                'priority'               => 'high',
                'created_by'             => $raja->id,
            ]
        );

        $us3 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-APT-003'],
            [
                'title'                  => "Cohérence inter-navigateurs de la page d'accueil Apple Tunisie",
                'description'            => "Garantir que la page d'accueil Apple Tunisie s'affiche de façon cohérente "
                    . "entre les navigateurs Chromium (Chrome), Firefox et WebKit (Safari), "
                    . "notamment pour le titre de page, la barre de navigation principale et les éléments visuels clés.",
                'as_a'                   => 'utilisateur accédant à Apple Tunisie depuis n\'importe quel navigateur',
                'i_want_that'            => "l'expérience visuelle et fonctionnelle soit identique quel que soit mon navigateur",
                'so_that'                => "je bénéficie de la même qualité de navigation sur Chrome, Firefox ou Safari",
                'acceptance_criteria'    => "- Le titre de la page est identique dans Chromium, Firefox et WebKit\n"
                    . "- La barre de navigation principale affiche les mêmes items dans les trois navigateurs\n"
                    . "- Le logo Apple est visible dans les trois navigateurs\n"
                    . "- Les liens de navigation sont fonctionnels dans Chromium, Firefox et WebKit\n"
                    . "- Aucun élément visuel majeur n'est absent ou cassé dans l'un des navigateurs",
                'business_rules'         => [
                    "La cohérence doit être vérifiée sur les 3 moteurs de rendu majeurs : Blink, Gecko, WebKit",
                    "Les tests cross-browser doivent être exécutés en mode headless pour la CI",
                    "Tout écart visuel bloquant entre navigateurs doit être remonté comme bug critique",
                ],
                'scenarios'              => [
                    ['titre' => 'Titre identique inter-navigateurs', 'etapes' => [
                        'Charger apple.com/tn dans Chromium — noter le titre',
                        'Charger apple.com/tn dans Firefox — comparer le titre',
                        'Charger apple.com/tn dans WebKit — comparer le titre',
                    ]],
                    ['titre' => 'Navigation cohérente cross-browser', 'etapes' => [
                        'Vérifier le menu dans Chromium',
                        'Vérifier le menu dans Firefox',
                        'Vérifier le menu dans WebKit',
                        'Confirmer l\'absence de différences bloquantes',
                    ]],
                ],
                'effort_points'          => 8,
                'business_value'         => 13,
                'start_date'             => '2026-06-08',
                'target_completion_date' => '2026-06-14',
                'status'                 => 'in_progress',
                'priority'               => 'critical',
                'created_by'             => $raja->id,
            ]
        );

        $us4 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-APT-004'],
            [
                'title'                  => "Recherche et découverte de produits sur Apple Tunisie",
                'description'            => "Permettre à l'utilisateur de rechercher des produits ou contenus Apple "
                    . "depuis la page d'accueil d'Apple Tunisie via l'icône de recherche présente dans la navigation.",
                'as_a'                   => 'visiteur cherchant un produit Apple spécifique',
                'i_want_that'            => "j'accède à la fonction de recherche et obtienne des résultats pertinents",
                'so_that'                => "je trouve rapidement le produit Apple qui correspond à mes besoins",
                'acceptance_criteria'    => "- L'icône ou le bouton de recherche est visible dans la navigation\n"
                    . "- Un clic sur le bouton de recherche ouvre l'interface de recherche\n"
                    . "- L'interface de recherche est accessible et répond aux interactions\n"
                    . "- Le bouton de recherche est présent sur la page d'accueil",
                'business_rules'         => [
                    "La recherche doit être accessible sans authentification",
                    "Le bouton de recherche doit être visible et cliquable",
                    "L'interface de recherche doit s'ouvrir sans rechargement de page",
                ],
                'scenarios'              => [
                    ['titre' => 'Accès à la recherche', 'etapes' => [
                        'Charger la page d\'accueil apple.com/tn',
                        'Localiser le bouton/icône de recherche',
                        'Cliquer sur le bouton de recherche',
                        'Vérifier l\'ouverture de l\'interface de recherche',
                    ]],
                ],
                'effort_points'          => 3,
                'business_value'         => 8,
                'start_date'             => '2026-06-10',
                'target_completion_date' => '2026-06-16',
                'status'                 => 'backlog',
                'priority'               => 'medium',
                'created_by'             => $raja->id,
            ]
        );

        $us5 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-APT-005'],
            [
                'title'                  => "Pied de page, mentions légales et liens de support Apple Tunisie",
                'description'            => "Vérifier que le pied de page d'Apple Tunisie contient les informations légales "
                    . "obligatoires, les liens de support, la politique de confidentialité et les mentions de copyright.",
                'as_a'                   => 'visiteur du site souhaitant accéder aux informations légales ou au support',
                'i_want_that'            => "je trouve facilement les liens légaux, la politique de confidentialité et le support Apple",
                'so_that'                => "je sois informé de mes droits et puisse contacter Apple si nécessaire",
                'acceptance_criteria'    => "- Le pied de page est visible en bas de la page d'accueil\n"
                    . "- Le copyright Apple avec l'année en cours est affiché\n"
                    . "- Les liens Politique de confidentialité et Conditions d'utilisation sont présents\n"
                    . "- Les liens du pied de page sont cliquables et fonctionnels\n"
                    . "- Un lien vers le support Apple est accessible",
                'business_rules'         => [
                    "Les mentions légales sont obligatoires pour tout site commercial opérant en Tunisie",
                    "Le copyright doit être mis à jour chaque année",
                    "Tous les liens du footer doivent pointer vers des pages valides",
                ],
                'scenarios'              => [
                    ['titre' => 'Pied de page complet', 'etapes' => [
                        'Charger la page d\'accueil apple.com/tn',
                        'Faire défiler jusqu\'au pied de page',
                        'Vérifier la présence du copyright',
                        'Vérifier les liens Politique de confidentialité et Conditions',
                    ]],
                    ['titre' => 'Liens du footer fonctionnels', 'etapes' => [
                        'Cliquer sur le lien Politique de confidentialité dans le footer',
                        'Vérifier l\'arrivée sur la page correspondante',
                    ]],
                ],
                'effort_points'          => 2,
                'business_value'         => 5,
                'start_date'             => '2026-06-12',
                'target_completion_date' => '2026-06-18',
                'status'                 => 'backlog',
                'priority'               => 'medium',
                'created_by'             => $raja->id,
            ]
        );

        // ── 4. CHECKLISTS ─────────────────────────────────────────────────────

        // CL-APT-01 — Chargement de la page d'accueil (US1)
        $cl01 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-01 – Chargement de la page d'accueil Apple Tunisie"],
            [
                'description'         => "Vérification du chargement correct de la page d'accueil https://www.apple.com/tn/ : "
                    . "accessibilité HTTPS, titre de page, contenu principal et absence d'erreurs.",
                'category'            => 'Chargement',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'visiteur du site Apple Tunisie',
                'i_want_that'         => "la page d'accueil se charge sans erreur et affiche le contenu attendu",
                'so_that'             => "j'aie accès aux produits et services Apple disponibles en Tunisie",
                'acceptance_criteria' => "- Page accessible via HTTPS\n- Titre valide\n- Corps de page visible",
                'priority'            => 'critical',
                'status'              => 'completed',
                'started_at'          => Carbon::parse('2026-06-02 09:00:00'),
                'completed_at'        => Carbon::parse('2026-06-03 17:00:00'),
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'manual',
                'source_user_story_id'=> $us1->id,
            ]
        );

        // CL-APT-02 — Présence des éléments visuels de marque (US1)
        $cl02 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-02 – Présence des éléments de marque Apple"],
            [
                'description'         => "Vérification de la présence et de la visibilité des éléments de marque Apple "
                    . "sur la page d'accueil : logo, navigation, bannière hero et structure globale.",
                'category'            => 'Identité visuelle',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'visiteur du site Apple Tunisie',
                'i_want_that'         => "voir les éléments de marque Apple dès l'arrivée sur la page d'accueil",
                'so_that'             => "j'aie confiance en l'authenticité du site officiel Apple",
                'acceptance_criteria' => "- Logo Apple visible\n- Navigation Apple présente\n- Bannière principale affichée",
                'priority'            => 'high',
                'status'              => 'completed',
                'started_at'          => Carbon::parse('2026-06-03 09:00:00'),
                'completed_at'        => Carbon::parse('2026-06-04 16:00:00'),
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'manual',
                'source_user_story_id'=> $us1->id,
            ]
        );

        // CL-APT-03 — Navigation vers les produits phares (US2)
        $cl03 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-03 – Navigation vers les produits phares Apple"],
            [
                'description'         => "Vérification de la navigation depuis le menu principal d'Apple Tunisie "
                    . "vers les pages produits iPhone, Mac, iPad et Apple Watch.",
                'category'            => 'Navigation produits',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'visiteur intéressé par les produits Apple',
                'i_want_that'         => "accéder aux pages produits iPhone, Mac et iPad depuis le menu de navigation",
                'so_that'             => "je puisse consulter les offres Apple disponibles en Tunisie",
                'acceptance_criteria' => "- Lien iPhone fonctionnel dans le menu\n- Lien Mac fonctionnel\n- Lien iPad fonctionnel",
                'priority'            => 'high',
                'status'              => 'in_progress',
                'started_at'          => Carbon::parse('2026-06-05 09:00:00'),
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'ai',
                'source_user_story_id'=> $us2->id,
            ]
        );

        // CL-APT-04 — Structure du menu principal (US2)
        $cl04 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-04 – Structure et accessibilité du menu principal"],
            [
                'description'         => "Vérification de la structure complète du menu de navigation Apple Tunisie : "
                    . "présence de tous les items attendus, accessibilité et comportement des liens.",
                'category'            => 'Navigation',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'visiteur naviguant sur Apple Tunisie',
                'i_want_that'         => "trouver facilement chaque catégorie de produit dans le menu principal",
                'so_that'             => "je navigue efficacement vers le produit ou service recherché",
                'acceptance_criteria' => "- Tous les liens de navigation sont présents et visibles\n- Liens accessibles au clic",
                'priority'            => 'high',
                'status'              => 'ready_for_test',
                'started_at'          => Carbon::parse('2026-06-06 09:00:00'),
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'manual',
                'source_user_story_id'=> $us2->id,
            ]
        );

        // CL-APT-05 — Cohérence titre et navigation inter-navigateurs (US3)
        // TC-01 de cette checklist = le TC-04 visible dans la capture d'écran
        $cl05 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-05 – Cohérence inter-navigateurs titre et navigation"],
            [
                'description'         => "Vérification de la cohérence du titre de page et du menu de navigation "
                    . "d'Apple Tunisie entre les navigateurs Chromium, Firefox et WebKit.",
                'category'            => 'Cross-browser',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'testeur QA cross-browser',
                'i_want_that'         => "vérifier que le titre et la navigation s'affichent identiquement sur tous les navigateurs",
                'so_that'             => "tous les utilisateurs bénéficient de la même expérience quel que soit leur navigateur",
                'acceptance_criteria' => "- Titre identique sur Chromium, Firefox, WebKit\n- Menu nav identique sur les 3 navigateurs",
                'priority'            => 'critical',
                'status'              => 'in_progress',
                'started_at'          => Carbon::parse('2026-06-08 09:00:00'),
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'ai',
                'source_user_story_id'=> $us3->id,
            ]
        );

        // CL-APT-06 — Rendu visuel multi-navigateurs (US3)
        $cl06 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-06 – Rendu visuel cross-browser des éléments clés"],
            [
                'description'         => "Vérification du rendu visuel des éléments clés de la page d'accueil "
                    . "Apple Tunisie sur Chromium, Firefox et WebKit : logo, hero, footer.",
                'category'            => 'Cross-browser',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'testeur QA spécialiste compatibilité navigateurs',
                'i_want_that'         => "confirmer que tous les éléments visuels majeurs s'affichent correctement sur chaque navigateur",
                'so_that'             => "aucun utilisateur ne soit pénalisé par son choix de navigateur",
                'acceptance_criteria' => "- Logo visible dans Chromium, Firefox et WebKit\n- Footer présent dans les 3 navigateurs",
                'priority'            => 'high',
                'status'              => 'in_progress',
                'started_at'          => Carbon::parse('2026-06-09 09:00:00'),
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'ai',
                'source_user_story_id'=> $us3->id,
            ]
        );

        // CL-APT-07 — Accès à la fonctionnalité de recherche (US4)
        $cl07 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-07 – Accès au bouton de recherche Apple"],
            [
                'description'         => "Vérification de la présence et de l'accessibilité du bouton de recherche "
                    . "dans la barre de navigation principale d'Apple Tunisie.",
                'category'            => 'Recherche',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'visiteur souhaitant rechercher un produit Apple',
                'i_want_that'         => "trouver et cliquer facilement sur le bouton de recherche depuis la navigation",
                'so_that'             => "j'accède rapidement à l'interface de recherche pour trouver un produit",
                'acceptance_criteria' => "- Bouton de recherche visible dans le menu\n- Bouton cliquable et accessible",
                'priority'            => 'medium',
                'status'              => 'backlog',
                'started_at'          => null,
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'manual',
                'source_user_story_id'=> $us4->id,
            ]
        );

        // CL-APT-08 — Interface de recherche (US4)
        $cl08 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-08 – Interface et interaction de la recherche Apple"],
            [
                'description'         => "Vérification du comportement de l'interface de recherche Apple Tunisie "
                    . "après activation : ouverture du panneau, accessibilité du champ de saisie.",
                'category'            => 'Recherche',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'visiteur utilisant la recherche Apple',
                'i_want_that'         => "l'interface de recherche s'ouvre et me permette de saisir une requête",
                'so_that'             => "je trouve le produit ou contenu Apple que je cherche",
                'acceptance_criteria' => "- Interface de recherche s'ouvre au clic\n- Champ de saisie accessible",
                'priority'            => 'medium',
                'status'              => 'backlog',
                'started_at'          => null,
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'ai',
                'source_user_story_id'=> $us4->id,
            ]
        );

        // CL-APT-09 — Pied de page Apple Tunisie (US5)
        $cl09 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-09 – Pied de page et mentions légales Apple Tunisie"],
            [
                'description'         => "Vérification du pied de page d'Apple Tunisie : présence du copyright, "
                    . "des liens légaux (Politique de confidentialité, Conditions d'utilisation) "
                    . "et des informations Apple Tunisie.",
                'category'            => 'Footer & Légal',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'visiteur cherchant les informations légales Apple',
                'i_want_that'         => "accéder aux mentions légales et aux liens de conformité depuis le pied de page",
                'so_that'             => "je sois informé de mes droits et des conditions d'utilisation du site",
                'acceptance_criteria' => "- Footer visible\n- Copyright Apple présent\n- Liens légaux accessibles",
                'priority'            => 'medium',
                'status'              => 'backlog',
                'started_at'          => null,
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'manual',
                'source_user_story_id'=> $us5->id,
            ]
        );

        // CL-APT-10 — Conformité et liens du footer (US5)
        $cl10 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => "CL-APT-10 – Conformité et fonctionnalité des liens du footer"],
            [
                'description'         => "Vérification de la fonctionnalité des liens présents dans le pied de page "
                    . "d'Apple Tunisie : liens vers le support, la confidentialité, les conditions et le store.",
                'category'            => 'Footer & Légal',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'visiteur accédant au support ou aux informations légales',
                'i_want_that'         => "tous les liens du pied de page soient fonctionnels et mènent aux pages correctes",
                'so_that'             => "je puisse accéder au support Apple et aux informations légales sans obstacle",
                'acceptance_criteria' => "- Tous les liens du footer sont cliquables\n- Liens menant vers les pages correctes",
                'priority'            => 'low',
                'status'              => 'backlog',
                'started_at'          => null,
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'reuse',
                'source_user_story_id'=> $us5->id,
            ]
        );

        $clists = [
            'cl01' => $cl01, 'cl02' => $cl02, 'cl03' => $cl03, 'cl04' => $cl04, 'cl05' => $cl05,
            'cl06' => $cl06, 'cl07' => $cl07, 'cl08' => $cl08, 'cl09' => $cl09, 'cl10' => $cl10,
        ];

        $project->checklists()->syncWithoutDetaching(
            collect(array_values($clists))->pluck('id')->toArray()
        );

        // ── 5. CHECKLIST ITEMS ────────────────────────────────────────────────
        // Les descriptions sont rédigées pour produire des plans Playwright
        // exécutables sur https://www.apple.com/tn/ sans authentification.
        //
        // Stratégie de couverture par type :
        //   navigation  → mention de "navigation", "menu", "lien" → goto + click a[href] + assert body
        //   generic_ui  → description générale sans mots-clés spécifiques → goto + wait body + assert
        //   button_action → mention de "bouton", "clic" → goto + click button + assert
        //   cross-browser → mention explicite de "Chromium, Firefox et WebKit" → 3 navigateurs

        // ── CL-APT-01 : Chargement de la page d'accueil (5 items) ────────────
        $items01 = $this->seedItems($cl01, [
            [
                "TC-APT-01 | Accessibilité HTTPS de la page d'accueil Apple Tunisie",
                "Ouvrir https://www.apple.com/tn/ et vérifier que la page se charge correctement sans erreur "
                    . "de certificat ni redirection HTTP. Le corps de la page doit être visible après chargement complet.",
                'High', 'Critical', 'passed', 1, $yahia->id, '2026-06-02 10:05:00',
            ],
            [
                "TC-APT-02 | Titre de la page d'accueil contient 'Apple'",
                "Charger https://www.apple.com/tn/ et vérifier que le titre de la page (balise <title>) "
                    . "contient le mot 'Apple'. Le titre doit être visible dans l'onglet du navigateur.",
                'High', 'Major', 'passed', 2, $yahia->id, '2026-06-02 10:10:00',
            ],
            [
                "TC-APT-03 | Contenu principal de la page d'accueil visible après chargement",
                "Charger la page d'accueil Apple Tunisie et vérifier que le contenu principal est visible. "
                    . "La page doit afficher au moins une bannière ou section hero au-dessus de la ligne de flottaison.",
                'High', 'Major', 'passed', 3, $yahia->id, '2026-06-02 10:15:00',
            ],
            [
                "TC-APT-04 | Lien de navigation principal visible sur la page d'accueil",
                "Charger apple.com/tn et vérifier que le menu de navigation principal contient au moins un lien "
                    . "accessible. La barre de navigation avec ses liens doit être visible en haut de la page.",
                'Medium', 'Major', 'passed', 4, $yahia->id, '2026-06-02 10:20:00',
            ],
            [
                "TC-APT-05 | Pied de page visible sur la page d'accueil Apple Tunisie",
                "Charger la page d'accueil https://www.apple.com/tn/ et faire défiler jusqu'au bas de la page. "
                    . "Vérifier que le pied de page Apple est présent et contient des liens de navigation.",
                'Low', 'Minor', 'passed', 5, $yahia->id, '2026-06-02 10:25:00',
            ],
        ]);

        // ── CL-APT-02 : Éléments de marque Apple (5 items) ───────────────────
        $items02 = $this->seedItems($cl02, [
            [
                "TC-APT-06 | Logo Apple visible dans la barre de navigation",
                "Charger https://www.apple.com/tn/ et vérifier que le logo Apple (image ou SVG avec alt='Apple' "
                    . "ou role correspondant) est visible dans la barre de navigation en haut de la page.",
                'High', 'Critical', 'passed', 1, $oussama->id, '2026-06-03 09:05:00',
            ],
            [
                "TC-APT-07 | Barre de navigation Apple structurée et visible",
                "Vérifier que la barre de navigation principale d'Apple Tunisie est visible et structurée "
                    . "avec des liens de navigation accessibles. La balise nav ou header doit contenir des liens a[href].",
                'High', 'Major', 'passed', 2, $oussama->id, '2026-06-03 09:12:00',
            ],
            [
                "TC-APT-08 | Lien iPhone présent dans la navigation principale",
                "Charger apple.com/tn et vérifier que le menu de navigation contient un lien vers la page iPhone. "
                    . "Le lien doit être visible et accessible dans la barre de navigation principale.",
                'High', 'Major', 'passed', 3, $oussama->id, '2026-06-03 09:18:00',
            ],
            [
                "TC-APT-09 | Lien Mac accessible dans la barre de navigation Apple Tunisie",
                "Vérifier que le menu de navigation d'Apple Tunisie contient un lien vers la page Mac. "
                    . "Ce lien doit être présent dans le header ou la navigation principale de la page d'accueil.",
                'High', 'Major', 'passed', 4, $oussama->id, '2026-06-03 09:24:00',
            ],
            [
                "TC-APT-10 | Section bannière hero de la page d'accueil Apple Tunisie",
                "Charger la page d'accueil Apple Tunisie et vérifier qu'une section principale (hero/bannière) "
                    . "est visible avec du contenu affiché. La section doit être présente dans le viewport initial.",
                'Medium', 'Minor', 'passed', 5, $oussama->id, '2026-06-03 09:30:00',
            ],
        ]);

        // ── CL-APT-03 : Navigation vers les produits (5 items) ────────────────
        $items03 = $this->seedItems($cl03, [
            [
                "TC-APT-11 | Navigation vers la page iPhone via le menu Apple Tunisie",
                "Depuis la page d'accueil https://www.apple.com/tn/, cliquer sur le lien 'iPhone' dans la barre "
                    . "de navigation principale. Vérifier que la navigation mène vers une page iPhone valide.",
                'High', 'Critical', 'passed', 1, $yahia->id, '2026-06-05 10:05:00',
            ],
            [
                "TC-APT-12 | Navigation vers la page Mac depuis le menu de navigation Apple",
                "Depuis la page d'accueil apple.com/tn, cliquer sur le lien 'Mac' dans la navigation principale. "
                    . "Vérifier que la page Mac Apple Tunisie se charge correctement après clic sur le lien.",
                'High', 'Major', 'passed', 2, $yahia->id, '2026-06-05 10:12:00',
            ],
            [
                "TC-APT-13 | Navigation vers la page iPad via le menu Apple Tunisie",
                "Charger apple.com/tn et cliquer sur le lien 'iPad' dans la barre de navigation principale. "
                    . "Vérifier que la page iPad se charge et que le corps de la page est visible.",
                'Medium', 'Major', 'pending', 3, null, null,
            ],
            [
                "TC-APT-14 | Accès à la page Apple Watch depuis le menu de navigation",
                "Depuis la page d'accueil Apple Tunisie, cliquer sur le lien 'Watch' dans la navigation. "
                    . "Vérifier que la page Apple Watch Tunisie se charge et affiche du contenu.",
                'Medium', 'Minor', 'pending', 4, null, null,
            ],
            [
                "TC-APT-15 | Lien Support Apple accessible depuis le menu principal",
                "Charger la page d'accueil apple.com/tn et cliquer sur le lien 'Support' dans le menu principal. "
                    . "Vérifier que la page de support Apple se charge correctement.",
                'Low', 'Minor', 'pending', 5, null, null,
            ],
        ]);

        // ── CL-APT-04 : Structure du menu (4 items) ───────────────────────────
        $items04 = $this->seedItems($cl04, [
            [
                "TC-APT-16 | Présence des items clés dans la navigation principale Apple",
                "Charger https://www.apple.com/tn/ et vérifier que la barre de navigation contient des liens "
                    . "vers les catégories principales Apple. Au moins un lien de navigation doit être présent dans le header.",
                'High', 'Critical', 'passed', 1, $oussama->id, '2026-06-06 09:05:00',
            ],
            [
                "TC-APT-17 | Logo Apple dans la navigation est un lien vers l'accueil",
                "Vérifier que le logo Apple présent dans la barre de navigation est un lien cliquable. "
                    . "Cliquer sur le logo Apple dans la navigation et vérifier le retour à la page d'accueil.",
                'High', 'Major', 'passed', 2, $oussama->id, '2026-06-06 09:12:00',
            ],
            [
                "TC-APT-18 | Menu de navigation contient le lien vers la page Store Apple",
                "Depuis la page d'accueil Apple Tunisie, vérifier que le menu de navigation contient un lien "
                    . "vers le Store Apple. Ce lien de navigation doit être accessible et visible.",
                'Medium', 'Minor', 'pending', 3, null, null,
            ],
            [
                "TC-APT-19 | Menu de navigation TV+ accessible depuis apple.com/tn",
                "Charger la page d'accueil Apple Tunisie et vérifier que le menu de navigation principal "
                    . "contient un lien vers Apple TV+. Le lien de navigation TV+ doit être présent et visible.",
                'Low', 'Minor', 'pending', 4, null, null,
            ],
        ]);

        // ── CL-APT-05 : Cohérence cross-browser titre/nav (4 items) ──────────
        // TC-APT-20 est le TC-04 visible dans la capture d'écran (cross-browser)
        $items05 = $this->seedItems($cl05, [
            [
                "TC-APT-20 | Cohérence inter-navigateurs du titre et du menu principal",
                "Comparer l'affichage de la page d'accueil d'Apple Tunisie entre Chromium, Firefox et WebKit "
                    . "après chargement complet. Contrôler que le titre de la page et la barre de navigation principale "
                    . "s'affichent de façon identique dans les trois navigateurs. "
                    . "Vérifier la présence des liens iPhone, Mac, iPad dans Chromium, Firefox et WebKit.",
                'High', 'Critical', 'pending', 1, null, null,
            ],
            [
                "TC-APT-21 | Menu de navigation identique dans Chrome et Firefox",
                "Charger la page d'accueil Apple Tunisie dans Chromium et dans Firefox. "
                    . "Vérifier que la barre de navigation principale contient les mêmes liens de navigation "
                    . "dans les deux navigateurs. Les liens iPhone, Mac et iPad doivent être identiques.",
                'High', 'Major', 'pending', 2, null, null,
            ],
            [
                "TC-APT-22 | Titre de la page Apple Tunisie cohérent sur Chromium, Firefox et WebKit",
                "Exécuter le test dans Chromium, Firefox et WebKit. Dans chaque navigateur, "
                    . "charger https://www.apple.com/tn/ et vérifier que le titre de la page est identique "
                    . "et contient le texte 'Apple'. Aucune divergence de titre ne doit être observée.",
                'High', 'Major', 'pending', 3, null, null,
            ],
            [
                "TC-APT-23 | Navigation fonctionnelle dans Firefox et WebKit sur Apple Tunisie",
                "Vérifier dans Firefox et WebKit que les liens de navigation du menu principal d'Apple Tunisie "
                    . "sont cliquables et fonctionnels. Cliquer sur un lien de navigation dans chaque navigateur "
                    . "et confirmer que la page de destination se charge correctement.",
                'Medium', 'Major', 'pending', 4, null, null,
            ],
        ]);

        // ── CL-APT-06 : Rendu cross-browser éléments visuels (4 items) ────────
        $items06 = $this->seedItems($cl06, [
            [
                "TC-APT-24 | Page d'accueil Apple Tunisie chargée dans Chromium, Firefox et WebKit",
                "Charger https://www.apple.com/tn/ simultanément dans Chromium, Firefox et WebKit. "
                    . "Vérifier que le corps de la page est visible et que le contenu principal est chargé "
                    . "sans erreur dans les trois navigateurs.",
                'High', 'Critical', 'pending', 1, null, null,
            ],
            [
                "TC-APT-25 | Logo Apple visible dans Chromium, Firefox et WebKit",
                "Charger la page d'accueil Apple Tunisie dans Chromium, Firefox et WebKit. "
                    . "Vérifier dans chaque navigateur que le logo Apple est présent et visible "
                    . "dans la barre de navigation principale.",
                'High', 'Major', 'pending', 2, null, null,
            ],
            [
                "TC-APT-26 | Lien iPhone présent dans Chrome, Firefox et Safari/WebKit",
                "Exécuter dans Chromium, Firefox et WebKit : charger apple.com/tn et vérifier "
                    . "que le lien de navigation iPhone est présent et accessible dans chaque navigateur. "
                    . "Le lien doit être visible dans les trois environnements.",
                'Medium', 'Major', 'pending', 3, null, null,
            ],
            [
                "TC-APT-27 | Footer Apple Tunisie visible dans Chromium, Firefox et WebKit",
                "Charger https://www.apple.com/tn/ dans Chromium, Firefox et WebKit. "
                    . "Faire défiler jusqu'au bas de la page dans chaque navigateur et vérifier "
                    . "que le pied de page Apple est visible et contient des liens.",
                'Low', 'Minor', 'pending', 4, null, null,
            ],
        ]);

        // ── CL-APT-07 : Accès au bouton de recherche (4 items) ────────────────
        $items07 = $this->seedItems($cl07, [
            [
                "TC-APT-28 | Bouton de recherche présent dans la navigation Apple Tunisie",
                "Charger https://www.apple.com/tn/ et vérifier que le bouton ou l'icône de recherche "
                    . "est visible et accessible dans la barre de navigation principale Apple. "
                    . "Le bouton de recherche doit être présent dans le header de la page.",
                'Medium', 'Major', 'pending', 1, null, null,
            ],
            [
                "TC-APT-29 | Clic sur le bouton de recherche ouvre l'interface",
                "Depuis la page d'accueil Apple Tunisie, cliquer sur le bouton de recherche "
                    . "dans la barre de navigation. Vérifier qu'une action visible se produit "
                    . "après le clic sur ce bouton (ouverture d'un panneau ou d'une interface).",
                'Medium', 'Major', 'pending', 2, null, null,
            ],
            [
                "TC-APT-30 | Bouton de recherche accessible depuis le menu de navigation",
                "Charger apple.com/tn et vérifier que le bouton de recherche "
                    . "est facilement accessible dans la barre de navigation. "
                    . "Le bouton ou le lien de recherche doit être visible dans le menu principal.",
                'Low', 'Minor', 'pending', 3, null, null,
            ],
            [
                "TC-APT-31 | Recherche accessible sur la page d'accueil Apple Tunisie",
                "Vérifier la présence d'un lien ou bouton permettant d'accéder à la recherche "
                    . "depuis la page d'accueil https://www.apple.com/tn/. "
                    . "La fonctionnalité de recherche doit être accessible via un bouton ou un lien visible.",
                'Low', 'Minor', 'pending', 4, null, null,
            ],
        ]);

        // ── CL-APT-08 : Interface de recherche (4 items) ──────────────────────
        $items08 = $this->seedItems($cl08, [
            [
                "TC-APT-32 | Interface de recherche s'ouvre au clic sur le bouton Apple",
                "Charger https://www.apple.com/tn/ et localiser le bouton de recherche dans la navigation. "
                    . "Cliquer sur ce bouton et vérifier qu'un panneau ou une interface apparaît "
                    . "pour permettre la saisie d'une requête de recherche.",
                'Medium', 'Major', 'pending', 1, null, null,
            ],
            [
                "TC-APT-33 | Zone de recherche Apple accessible après ouverture",
                "Depuis la page d'accueil Apple Tunisie, activer le bouton de recherche "
                    . "et vérifier que l'interface de recherche est affichée et prête à recevoir une saisie. "
                    . "La zone de saisie doit être visible après clic sur le bouton.",
                'Medium', 'Minor', 'pending', 2, null, null,
            ],
            [
                "TC-APT-34 | Fermeture de l'interface de recherche Apple",
                "Ouvrir l'interface de recherche depuis la page d'accueil Apple Tunisie "
                    . "puis cliquer sur le bouton de fermeture ou appuyer sur Échap. "
                    . "Vérifier que l'interface de recherche se ferme et que la page revient à l'état initial.",
                'Low', 'Minor', 'pending', 3, null, null,
            ],
            [
                "TC-APT-35 | Suggestions de recherche disponibles sur Apple Tunisie",
                "Ouvrir la recherche Apple Tunisie et cliquer dans la zone de recherche. "
                    . "Vérifier que des suggestions ou des tendances de recherche s'affichent "
                    . "sous la zone de saisie sans avoir tapé de texte.",
                'Low', 'Minor', 'pending', 4, null, null,
            ],
        ]);

        // ── CL-APT-09 : Pied de page et mentions légales (5 items) ───────────
        $items09 = $this->seedItems($cl09, [
            [
                "TC-APT-36 | Pied de page visible sur la page d'accueil Apple Tunisie",
                "Charger https://www.apple.com/tn/ et faire défiler jusqu'en bas de la page. "
                    . "Vérifier que le pied de page Apple est visible et contient du contenu "
                    . "incluant des liens de navigation ou des mentions légales.",
                'Medium', 'Major', 'pending', 1, null, null,
            ],
            [
                "TC-APT-37 | Copyright Apple présent dans le pied de page",
                "Charger la page d'accueil Apple Tunisie et faire défiler jusqu'au footer. "
                    . "Vérifier que la mention de copyright Apple (© Apple Inc.) est présente "
                    . "et lisible dans le pied de page de la page.",
                'Medium', 'Minor', 'pending', 2, null, null,
            ],
            [
                "TC-APT-38 | Lien Politique de confidentialité présent dans le footer Apple",
                "Charger apple.com/tn et faire défiler jusqu'au pied de page. "
                    . "Vérifier que le lien vers la Politique de confidentialité Apple "
                    . "est présent et cliquable dans le footer de la page.",
                'Medium', 'Minor', 'pending', 3, null, null,
            ],
            [
                "TC-APT-39 | Lien vers les Conditions d'utilisation dans le footer Apple Tunisie",
                "Charger la page d'accueil Apple Tunisie et naviguer vers le bas de la page. "
                    . "Vérifier que le lien vers les Conditions d'utilisation ou d'une page légale "
                    . "est présent dans le pied de page et accessible au clic.",
                'Low', 'Minor', 'pending', 4, null, null,
            ],
            [
                "TC-APT-40 | Liens du pied de page Apple Tunisie fonctionnels",
                "Depuis la page d'accueil Apple Tunisie, naviguer jusqu'au pied de page "
                    . "et cliquer sur l'un des liens présents dans le footer. "
                    . "Vérifier que la page de destination se charge sans erreur.",
                'Low', 'Minor', 'pending', 5, null, null,
            ],
        ]);

        // ── CL-APT-10 : Conformité et fonctionnalité footer (4 items) ─────────
        $items10 = $this->seedItems($cl10, [
            [
                "TC-APT-41 | Lien vers le Support Apple accessible dans le footer",
                "Charger https://www.apple.com/tn/ et naviguer jusqu'au pied de page. "
                    . "Vérifier qu'un lien vers le support Apple est présent et accessible "
                    . "dans la section footer ou dans les liens de navigation secondaires.",
                'Low', 'Minor', 'pending', 1, null, null,
            ],
            [
                "TC-APT-42 | Liens de navigation secondaires présents dans le footer Apple",
                "Depuis la page d'accueil Apple Tunisie, faire défiler jusqu'au pied de page "
                    . "et vérifier la présence de liens de navigation secondaires (produits, services, compte). "
                    . "Les liens du footer doivent être visibles et accessibles.",
                'Low', 'Minor', 'pending', 2, null, null,
            ],
            [
                "TC-APT-43 | Footer Apple Tunisie contient les informations sur Apple Inc.",
                "Charger la page d'accueil apple.com/tn et consulter le pied de page. "
                    . "Vérifier que le footer contient des informations sur Apple Inc. "
                    . "incluant le copyright et au moins un lien vers une page légale ou de contact.",
                'Low', 'Minor', 'pending', 3, null, null,
            ],
            [
                "TC-APT-44 | Navigation vers la page de support Apple depuis le footer",
                "Depuis la page d'accueil Apple Tunisie, naviguer jusqu'au pied de page "
                    . "et cliquer sur un lien de support ou de contact Apple. "
                    . "Vérifier que la page de destination (support Apple) se charge correctement.",
                'Low', 'Minor', 'pending', 4, null, null,
            ],
        ]);

        // ── 6. LIAISONS USER STORIES <-> CHECKLISTS ───────────────────────────
        $us1->checklists()->syncWithoutDetaching([
            $cl01->id => ['is_generated_from_arxis' => false, 'relevance_score' => 98, 'link_type' => 'attached'],
            $cl02->id => ['is_generated_from_arxis' => false, 'relevance_score' => 95, 'link_type' => 'attached'],
        ]);
        $us2->checklists()->syncWithoutDetaching([
            $cl03->id => ['is_generated_from_arxis' => true,  'relevance_score' => 96, 'link_type' => 'attached'],
            $cl04->id => ['is_generated_from_arxis' => false, 'relevance_score' => 90, 'link_type' => 'attached'],
        ]);
        $us3->checklists()->syncWithoutDetaching([
            $cl05->id => ['is_generated_from_arxis' => true,  'relevance_score' => 99, 'link_type' => 'attached'],
            $cl06->id => ['is_generated_from_arxis' => true,  'relevance_score' => 94, 'link_type' => 'attached'],
        ]);
        $us4->checklists()->syncWithoutDetaching([
            $cl07->id => ['is_generated_from_arxis' => false, 'relevance_score' => 88, 'link_type' => 'attached'],
            $cl08->id => ['is_generated_from_arxis' => true,  'relevance_score' => 85, 'link_type' => 'attached'],
        ]);
        $us5->checklists()->syncWithoutDetaching([
            $cl09->id => ['is_generated_from_arxis' => false, 'relevance_score' => 92, 'link_type' => 'attached'],
            $cl10->id => ['is_generated_from_arxis' => false, 'relevance_score' => 87, 'link_type' => 'attached'],
        ]);

        // ── 7. PROJECT VERSIONS + VERSION ITEMS ───────────────────────────────

        $v1 = ProjectVersion::updateOrCreate(
            ['project_id' => $project->id, 'version_number' => 1],
            ['checklist_id' => $cl01->id]
        );
        $this->seedVersionItems($v1, $items01, $yahia->id);

        $v2 = ProjectVersion::updateOrCreate(
            ['project_id' => $project->id, 'version_number' => 2],
            ['checklist_id' => $cl02->id]
        );
        $this->seedVersionItems($v2, $items02, $oussama->id);

        $v3 = ProjectVersion::updateOrCreate(
            ['project_id' => $project->id, 'version_number' => 3],
            ['checklist_id' => $cl05->id]
        );
        $this->seedVersionItems($v3, $items05, $yahia->id);

        // ── 8. TEST RUNS + TEST RESULTS ───────────────────────────────────────

        // Run 1 — CL-APT-01 complet : 5 passed (chargement page d'accueil)
        $run1 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run1']],
            [
                'project_version_id' => $v1->id,
                'checklist_id'       => $cl01->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'full-checklist',
                'status'             => 'completed',
                'requested_by'       => $yahia->id,
                'started_at'         => Carbon::parse('2026-06-02 10:00:00'),
                'finished_at'        => Carbon::parse('2026-06-02 10:28:00'),
                'summary_total'      => 5, 'summary_passed' => 5,
                'summary_failed'     => 0, 'summary_blocked' => 0, 'summary_skipped' => 0,
                'request_payload'    => [
                    'target_type'  => 'checklist',
                    'checklist_id' => $cl01->id,
                    'mode'         => 'full-checklist',
                    'base_url'     => self::APP_URL,
                ],
            ]
        );
        foreach ($items01 as $item) {
            TestResult::updateOrCreate(
                ['test_run_id' => $run1->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => 'passed',
                    'duration_ms'     => rand(2000, 5500),
                    'artifacts'       => [],
                    'result_payload'  => ['status' => 'passed'],
                    'executed_at'     => Carbon::parse('2026-06-02 10:03:00')->addMinutes($item->order * 5),
                ]
            );
        }

        // Run 2 — CL-APT-02 complet : 5 passed (éléments de marque)
        $run2 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run2']],
            [
                'project_version_id' => $v2->id,
                'checklist_id'       => $cl02->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'full-checklist',
                'status'             => 'completed',
                'requested_by'       => $oussama->id,
                'started_at'         => Carbon::parse('2026-06-03 09:00:00'),
                'finished_at'        => Carbon::parse('2026-06-03 09:35:00'),
                'summary_total'      => 5, 'summary_passed' => 5,
                'summary_failed'     => 0, 'summary_blocked' => 0, 'summary_skipped' => 0,
                'request_payload'    => [
                    'target_type'  => 'checklist',
                    'checklist_id' => $cl02->id,
                    'base_url'     => self::APP_URL,
                ],
            ]
        );
        foreach ($items02 as $item) {
            TestResult::updateOrCreate(
                ['test_run_id' => $run2->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => 'passed',
                    'duration_ms'     => rand(2500, 6000),
                    'artifacts'       => [],
                    'result_payload'  => ['status' => 'passed'],
                    'executed_at'     => Carbon::parse('2026-06-03 09:03:00')->addMinutes($item->order * 6),
                ]
            );
        }

        // Run 3 — CL-APT-03 partiel : 2 passed, 3 skipped (navigation produits)
        $run3 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run3']],
            [
                'project_version_id' => null,
                'checklist_id'       => $cl03->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'full-checklist',
                'status'             => 'completed',
                'requested_by'       => $yahia->id,
                'started_at'         => Carbon::parse('2026-06-05 10:00:00'),
                'finished_at'        => Carbon::parse('2026-06-05 10:20:00'),
                'summary_total'      => 5, 'summary_passed' => 2,
                'summary_failed'     => 0, 'summary_blocked' => 0, 'summary_skipped' => 3,
                'request_payload'    => [
                    'target_type'  => 'checklist',
                    'checklist_id' => $cl03->id,
                    'base_url'     => self::APP_URL,
                ],
            ]
        );
        foreach ($items03 as $item) {
            $status = $item->status === 'passed' ? 'passed' : 'skipped';
            TestResult::updateOrCreate(
                ['test_run_id' => $run3->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => $status,
                    'duration_ms'     => $status === 'passed' ? rand(3000, 7000) : null,
                    'artifacts'       => [],
                    'result_payload'  => ['status' => $status],
                    'executed_at'     => Carbon::parse('2026-06-05 10:03:00')->addMinutes($item->order * 4),
                ]
            );
        }

        // Run 4 — CL-APT-04 partiel : 2 passed, 2 skipped (menu structure)
        $run4 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run4']],
            [
                'project_version_id' => null,
                'checklist_id'       => $cl04->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'full-checklist',
                'status'             => 'completed',
                'requested_by'       => $oussama->id,
                'started_at'         => Carbon::parse('2026-06-06 09:00:00'),
                'finished_at'        => Carbon::parse('2026-06-06 09:18:00'),
                'summary_total'      => 4, 'summary_passed' => 2,
                'summary_failed'     => 0, 'summary_blocked' => 0, 'summary_skipped' => 2,
                'request_payload'    => [
                    'target_type'  => 'checklist',
                    'checklist_id' => $cl04->id,
                    'base_url'     => self::APP_URL,
                ],
            ]
        );
        foreach ($items04 as $item) {
            $status = $item->status === 'passed' ? 'passed' : 'skipped';
            TestResult::updateOrCreate(
                ['test_run_id' => $run4->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => $status,
                    'duration_ms'     => $status === 'passed' ? rand(2000, 5000) : null,
                    'artifacts'       => [],
                    'result_payload'  => ['status' => $status],
                    'executed_at'     => Carbon::parse('2026-06-06 09:03:00')->addMinutes($item->order * 4),
                ]
            );
        }

        // Run 5 — CL-APT-05 : en cours (cross-browser, 0 résultats)
        $run5 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run5']],
            [
                'project_version_id' => $v3->id,
                'checklist_id'       => $cl05->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'full-checklist',
                'status'             => 'running',
                'requested_by'       => $yahia->id,
                'started_at'         => Carbon::parse('2026-06-19 17:52:00'),
                'finished_at'        => null,
                'summary_total'      => 4, 'summary_passed' => 0,
                'summary_failed'     => 0, 'summary_blocked' => 0, 'summary_skipped' => 0,
                'request_payload'    => [
                    'target_type'  => 'checklist',
                    'checklist_id' => $cl05->id,
                    'base_url'     => self::APP_URL,
                ],
            ]
        );

        // Run 6 — CL-APT-01 régression : 5 passed
        $run6 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run6']],
            [
                'project_version_id' => $v1->id,
                'checklist_id'       => $cl01->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'regression',
                'status'             => 'completed',
                'requested_by'       => $raja->id,
                'started_at'         => Carbon::parse('2026-06-10 14:00:00'),
                'finished_at'        => Carbon::parse('2026-06-10 14:22:00'),
                'summary_total'      => 5, 'summary_passed' => 5,
                'summary_failed'     => 0, 'summary_blocked' => 0, 'summary_skipped' => 0,
                'request_payload'    => [
                    'target_type'  => 'checklist',
                    'checklist_id' => $cl01->id,
                    'mode'         => 'regression',
                    'base_url'     => self::APP_URL,
                    'notes'        => "Run de régression post-déploiement — validation du chargement de la page d'accueil Apple Tunisie.",
                ],
            ]
        );
        foreach ($items01 as $item) {
            TestResult::updateOrCreate(
                ['test_run_id' => $run6->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => 'passed',
                    'duration_ms'     => rand(1800, 4500),
                    'artifacts'       => [],
                    'result_payload'  => ['status' => 'passed', 'note' => 'Régression — tous les cas passent.'],
                    'executed_at'     => Carbon::parse('2026-06-10 14:03:00')->addMinutes($item->order * 4),
                ]
            );
        }

        // ── 9. HISTORIQUES ────────────────────────────────────────────────────
        $histories = [
            [
                $items01[0]->id, $yahia->id, 'status', 'pending', 'passed', 'status_change',
                "Page d'accueil Apple Tunisie accessible en HTTPS — aucune erreur de certificat.",
                '2026-06-02 10:07:00',
            ],
            [
                $items01[1]->id, $yahia->id, 'status', 'pending', 'passed', 'status_change',
                "Titre de page : 'Apple (Tunisie)' — conforme aux attentes.",
                '2026-06-02 10:12:00',
            ],
            [
                $items02[0]->id, $oussama->id, 'status', 'pending', 'passed', 'status_change',
                "Logo Apple visible dans le header — SVG avec aria-label='Apple'.",
                '2026-06-03 09:07:00',
            ],
            [
                $items02[2]->id, $oussama->id, 'status', 'pending', 'passed', 'status_change',
                "Lien iPhone présent dans la navigation — href='/tn/iphone/'.",
                '2026-06-03 09:20:00',
            ],
            [
                $items03[0]->id, $yahia->id, 'status', 'pending', 'passed', 'status_change',
                "Navigation vers la page iPhone réussie — URL /tn/iphone/ chargée correctement.",
                '2026-06-05 10:07:00',
            ],
            [
                $items03[1]->id, $yahia->id, 'status', 'pending', 'passed', 'status_change',
                "Navigation vers la page Mac réussie — URL /tn/mac/ accessible.",
                '2026-06-05 10:14:00',
            ],
            [
                $items04[0]->id, $oussama->id, 'status', 'pending', 'passed', 'status_change',
                "Navigation principale Apple Tunisie : iPhone, Mac, iPad, Watch, TV+, Support tous présents.",
                '2026-06-06 09:07:00',
            ],
            [
                $items04[1]->id, $oussama->id, 'status', 'pending', 'passed', 'status_change',
                "Logo Apple cliquable — redirige vers /tn/ depuis toutes les pages internes.",
                '2026-06-06 09:14:00',
            ],
        ];

        foreach ($histories as [$itemId, $userId, $field, $old, $new, $type, $notes, $date]) {
            DB::table('checklist_item_histories')->updateOrInsert(
                [
                    'checklist_item_id' => $itemId,
                    'changed_by'        => $userId,
                    'old_value'         => $old,
                    'new_value'         => $new,
                    'change_type'       => $type,
                ],
                [
                    'field_name'  => $field,
                    'notes'       => $notes,
                    'created_at'  => Carbon::parse($date),
                    'updated_at'  => Carbon::parse($date),
                ]
            );
        }

        // ── 10. COMMENTAIRES SUR LES VERSION ITEMS ────────────────────────────
        $vi1 = \App\Models\VersionItem::where('project_version_id', $v1->id)->orderBy('order')->get();
        $vi2 = \App\Models\VersionItem::where('project_version_id', $v2->id)->orderBy('order')->get();
        $vi3 = \App\Models\VersionItem::where('project_version_id', $v3->id)->orderBy('order')->get();

        $comments = [
            [
                $vi1->get(0)?->id, $raja->id,
                "Validation du chargement HTTPS confirmée. La page apple.com/tn répond en 200ms en moyenne. "
                    . "Ce cas de test sera intégré dans la suite de régression continue.",
                '2026-06-02 11:00:00',
            ],
            [
                $vi1->get(1)?->id, $yahia->id,
                "Titre de page validé : 'Apple (Tunisie)'. Conforme pour toutes les locales testées (fr-TN, ar-TN).",
                '2026-06-02 11:20:00',
            ],
            [
                $vi2->get(0)?->id, $oussama->id,
                "Logo Apple visible dans tous les viewports testés : 1920px, 1280px, 768px. "
                    . "SVG avec aria-label='Apple' — accessible aux lecteurs d'écran.",
                '2026-06-03 10:00:00',
            ],
            [
                $vi2->get(2)?->id, $raja->id,
                "Lien iPhone href='/tn/iphone/' présent et fonctionnel. "
                    . "Test réussi sur Chrome 124, Edge 124 et Firefox 125.",
                '2026-06-03 14:30:00',
            ],
            [
                $vi3->get(0)?->id, $yahia->id,
                "TC-04 en cours d'exécution sur Chromium, Firefox et WebKit. "
                    . "Premier run cross-browser sur apple.com/tn — résultats attendus dans 15 minutes.",
                '2026-06-19 17:55:00',
            ],
        ];

        foreach ($comments as [$vItemId, $userId, $content, $date]) {
            if ($vItemId === null) {
                continue;
            }
            DB::table('comments')->updateOrInsert(
                [
                    'version_item_id' => $vItemId,
                    'user_id'         => $userId,
                    'content'         => $content,
                ],
                [
                    'file_path'  => null,
                    'file_name'  => null,
                    'file_size'  => null,
                    'created_at' => Carbon::parse($date),
                    'updated_at' => Carbon::parse($date),
                ]
            );
        }

        // ── RAPPORT CONSOLE ───────────────────────────────────────────────────
        $this->command?->info('');
        $this->command?->info('╔══════════════════════════════════════════════════╗');
        $this->command?->info('║        AppleTunisieSeeder — Résumé               ║');
        $this->command?->info('╠══════════════════════════════════════════════════╣');
        $this->command?->info('║  Utilisateurs               :   4                ║');
        $this->command?->info('║  Projet                     :   1                ║');
        $this->command?->info('║  URL cible                  : apple.com/tn       ║');
        $this->command?->info('║  User stories               :   5                ║');
        $this->command?->info('║  Checklists                 :  10                ║');
        $this->command?->info('║  Checklist items (TC)       :  44                ║');
        $this->command?->info('║  Project versions           :   3                ║');
        $this->command?->info('║  Test runs                  :   6                ║');
        $this->command?->info('║  Historiques d\'items        :   8                ║');
        $this->command?->info('║  Commentaires               :   5                ║');
        $this->command?->info('╠══════════════════════════════════════════════════╣');
        $this->command?->info('║  Items automation-ready     :  44                ║');
        $this->command?->info('║  Items cross-browser        :  12 (Chromium/FF/WK)║');
        $this->command?->info('║  Items navigation           :  20                ║');
        $this->command?->info('║  Items generic_ui           :  12                ║');
        $this->command?->info('╚══════════════════════════════════════════════════╝');
        $this->command?->info('  Application cible : ' . self::APP_URL);
        $this->command?->info('');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTHODES HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function ensureUser(string $email, string $name, string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'           => $name,
                'password'       => Hash::make('password123'),
                'account_status' => 'active',
                'activated_at'   => now(),
            ]
        );

        if (method_exists($user, 'syncRoles')) {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    /**
     * @return ChecklistItem[]
     */
    private function seedItems(Checklist $checklist, array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            [$title, $desc, $priority, $criticality, $status, $order] = $row;

            $testedById = $row[6] ?? null;
            $testedAt   = (isset($row[7]) && $row[7] !== null) ? Carbon::parse($row[7]) : null;
            $qaComment  = $row[8] ?? null;

            $item = ChecklistItem::updateOrCreate(
                ['checklist_id' => $checklist->id, 'title' => $title],
                [
                    'description' => $desc,
                    'priority'    => $priority,
                    'criticality' => $criticality,
                    'status'      => $status,
                    'order'       => $order,
                    'qa_comment'  => $qaComment,
                    'tested_by'   => $testedById,
                    'tested_at'   => $testedAt,
                    'last_run_at' => $testedAt,
                    'run_count'   => $testedAt ? 1 : 0,
                ]
            );

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param  ChecklistItem[]  $checklistItems
     */
    private function seedVersionItems(ProjectVersion $version, array $checklistItems, int $defaultTesterId): void
    {
        foreach ($checklistItems as $item) {
            $vStatus = match ($item->status) {
                'passed'  => 'Passed',
                'failed'  => 'Failed',
                'blocked' => 'Blocked',
                default   => 'Not Tested',
            };

            $testedBy = in_array($vStatus, ['Passed', 'Failed']) ? $defaultTesterId : null;
            $testedAt = in_array($vStatus, ['Passed', 'Failed']) ? $item->tested_at  : null;

            VersionItem::updateOrCreate(
                ['project_version_id' => $version->id, 'title' => $item->title],
                [
                    'description'       => $item->description,
                    'priority'          => $item->priority,
                    'criticality'       => $item->criticality,
                    'order'             => $item->order,
                    'status'            => $vStatus,
                    'tested_by'         => $testedBy,
                    'tested_at'         => $testedAt,
                    'execution_profile' => [],
                ]
            );
        }
    }
}
