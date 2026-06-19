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
 * PfeDemoSeeder
 *
 * Jeu de données de démonstration PFE pour IntelliTest.
 * Projet : Sauce Demo E-commerce (https://www.saucedemo.com)
 *
 * Peuple les tables suivantes :
 *   users, projects, project_testers,
 *   user_stories, checklists, checklist_items, user_story_checklists,
 *   project_versions, version_items,
 *   test_runs, test_results,
 *   checklist_item_histories, comments
 *
 * Idempotent — peut être rejoué sans créer de doublons.
 * Exécution : php artisan db:seed --class=PfeDemoSeeder
 */
class PfeDemoSeeder extends Seeder
{
    private const PROJECT_NAME = 'Sauce Demo E-commerce';
    private const APP_URL      = 'https://www.saucedemo.com';

    // UUIDs stables pour les test_runs (idempotence)
    private const RUN_IDS = [
        'run1' => 'dd100001-0001-4001-8001-000000000001',
        'run2' => 'dd100002-0002-4002-8002-000000000002',
        'run3' => 'dd100003-0003-4003-8003-000000000003',
        'run4' => 'dd100004-0004-4004-8004-000000000004',
        'run5' => 'dd100005-0005-4005-8005-000000000005',
        'run6' => 'dd100006-0006-4006-8006-000000000006',
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
                'description'     => "Projet de validation qualité de la plateforme e-commerce Sauce Demo.\n"
                    . "Ce projet couvre l'ensemble des fonctionnalités critiques : authentification, "
                    . "catalogue produits, gestion du panier et processus de commande.",
                'test_objectives' => "1. Valider la robustesse des flux utilisateur critiques de Sauce Demo.\n"
                    . "2. Assurer la couverture des scénarios positifs et négatifs pour chaque user story.\n"
                    . "3. Démontrer l'intégration complète du workflow QA sur la plateforme IntelliTest.",
                'app_url'         => self::APP_URL,
                'start_date'      => '2026-05-18',
                'status'          => 'active',
                'created_by'      => $raja->id,
            ]
        );

        $project->testers()->syncWithoutDetaching([$yahia->id, $oussama->id]);

        // ── 3. USER STORIES ───────────────────────────────────────────────────

        $us1 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-PFE-001'],
            [
                'title'                  => "Authentification de l'utilisateur",
                'description'            => "Permettre aux visiteurs de se connecter à Sauce Demo avec leurs identifiants "
                    . "afin d'accéder au catalogue produits sécurisé.",
                'as_a'                   => 'visiteur non authentifié',
                'i_want_that'            => 'je puisse me connecter avec mon identifiant et mon mot de passe',
                'so_that'                => "j'accède au catalogue des produits disponibles à l'achat",
                'acceptance_criteria'    => "- La page de connexion est accessible à l'URL racine\n"
                    . "- La connexion avec standard_user / secret_sauce redirige vers /inventory.html\n"
                    . "- Un message d'erreur explicite s'affiche pour les identifiants invalides\n"
                    . "- Le compte locked_out_user affiche un message de verrouillage approprié",
                'business_rules'         => [
                    "Les comptes verrouillés ne peuvent pas se connecter",
                    "Le mot de passe est masqué lors de la saisie",
                    "Aucune tentative de brute-force ne doit aboutir",
                ],
                'scenarios'              => [
                    ['titre' => 'Connexion réussie',    'etapes' => ['Ouvrir la page de connexion', 'Saisir standard_user / secret_sauce', 'Cliquer Login', 'Vérifier la redirection vers /inventory.html']],
                    ['titre' => 'Compte verrouillé',    'etapes' => ['Saisir locked_out_user / secret_sauce', 'Cliquer Login', 'Vérifier le message de verrouillage']],
                    ['titre' => 'Identifiants invalides','etapes' => ['Saisir un mauvais mot de passe', 'Cliquer Login', 'Vérifier le message d\'erreur']],
                ],
                'effort_points'          => 3,
                'business_value'         => 13,
                'start_date'             => '2026-05-18',
                'target_completion_date' => '2026-05-21',
                'status'                 => 'completed',
                'priority'               => 'critical',
                'created_by'             => $raja->id,
            ]
        );

        $us2 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-PFE-002'],
            [
                'title'                  => 'Consultation du catalogue produits',
                'description'            => "Permettre à l'utilisateur connecté de consulter la liste complète des "
                    . "produits disponibles avec leurs informations essentielles.",
                'as_a'                   => 'utilisateur authentifié',
                'i_want_that'            => 'je visualise l\'ensemble des produits avec leur nom, image, description et prix',
                'so_that'                => "je puisse évaluer les articles disponibles avant de les ajouter au panier",
                'acceptance_criteria'    => "- 6 produits sont affichés sur /inventory.html\n"
                    . "- Chaque produit présente : image, nom, description et prix en dollars\n"
                    . "- Les prix sont formatés avec le symbole $\n"
                    . "- L'accès à la fiche produit est possible depuis le catalogue",
                'business_rules'         => [
                    "Le catalogue est accessible uniquement aux utilisateurs connectés",
                    "Chaque produit doit avoir une image valide et chargeable",
                    "Le prix doit être positif et affiché en USD",
                ],
                'scenarios'              => [
                    ['titre' => 'Affichage du catalogue', 'etapes' => ['Se connecter', 'Vérifier que 6 produits sont affichés', 'Vérifier image, nom, prix pour chaque produit']],
                    ['titre' => 'Accès à la fiche produit', 'etapes' => ['Cliquer sur un produit', 'Vérifier les détails', 'Cliquer Back to products']],
                ],
                'effort_points'          => 2,
                'business_value'         => 8,
                'start_date'             => '2026-05-19',
                'target_completion_date' => '2026-05-23',
                'status'                 => 'completed',
                'priority'               => 'high',
                'created_by'             => $raja->id,
            ]
        );

        $us3 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-PFE-003'],
            [
                'title'                  => "Gestion du panier d'achats",
                'description'            => "Permettre à l'utilisateur d'ajouter et de supprimer des produits dans "
                    . "son panier afin de préparer sa commande.",
                'as_a'                   => 'acheteur potentiel',
                'i_want_that'            => "j'ajoute des produits à mon panier et puisse les supprimer à tout moment",
                'so_that'                => "je constitue ma sélection d'achats avant de passer commande",
                'acceptance_criteria'    => "- Le bouton Add to cart ajoute le produit au panier\n"
                    . "- Le compteur du panier s'incrémente à chaque ajout\n"
                    . "- Le bouton devient Remove après l'ajout\n"
                    . "- La suppression décrémente le compteur\n"
                    . "- Le panier est persisté pendant la session",
                'business_rules'         => [
                    "Un même produit ne peut être ajouté qu'une seule fois",
                    "Le panier est lié à la session utilisateur",
                    "La persistance du panier est maintenue entre les pages",
                ],
                'scenarios'              => [
                    ['titre' => 'Ajout au panier',      'etapes' => ['Cliquer Add to cart', 'Vérifier compteur = 1', 'Vérifier bouton devient Remove']],
                    ['titre' => 'Suppression du panier','etapes' => ['Cliquer Remove', 'Vérifier compteur = 0', 'Vérifier bouton redevient Add to cart']],
                ],
                'effort_points'          => 5,
                'business_value'         => 13,
                'start_date'             => '2026-05-23',
                'target_completion_date' => '2026-05-28',
                'status'                 => 'ready_for_test',
                'priority'               => 'high',
                'created_by'             => $raja->id,
            ]
        );

        $us4 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-PFE-004'],
            [
                'title'                  => 'Finalisation de commande',
                'description'            => "Permettre à l'utilisateur de finaliser son achat en renseignant ses "
                    . "informations de livraison et en confirmant sa commande.",
                'as_a'                   => 'acheteur avec panier non vide',
                'i_want_that'            => "je finalise ma commande en fournissant mes informations et en confirmant le paiement",
                'so_that'                => "je reçoive une confirmation de commande et les produits me soient expédiés",
                'acceptance_criteria'    => "- Le bouton Checkout est accessible depuis la page panier\n"
                    . "- Le formulaire demande prénom, nom et code postal\n"
                    . "- Les champs vides génèrent des messages d'erreur\n"
                    . "- Le récapitulatif affiche le total avec taxes\n"
                    . "- La confirmation affiche \"Thank you for your order!\"",
                'business_rules'         => [
                    "Le checkout n'est possible qu'avec un panier non vide",
                    "Tous les champs du formulaire sont obligatoires",
                    "Le total inclut une taxe de 8% du sous-total",
                ],
                'scenarios'              => [
                    ['titre' => 'Commande réussie',         'etapes' => ['Accéder au panier', 'Cliquer Checkout', 'Remplir prénom / nom / code postal', 'Cliquer Continue', 'Vérifier récapitulatif', 'Cliquer Finish']],
                    ['titre' => 'Validation champs vides',  'etapes' => ['Accéder au checkout', 'Cliquer Continue sans remplir les champs', 'Vérifier les messages d\'erreur']],
                ],
                'effort_points'          => 8,
                'business_value'         => 21,
                'start_date'             => '2026-05-28',
                'target_completion_date' => '2026-06-05',
                'status'                 => 'in_progress',
                'priority'               => 'critical',
                'created_by'             => $raja->id,
            ]
        );

        $us5 = UserStory::updateOrCreate(
            ['project_id' => $project->id, 'story_id' => 'US-PFE-005'],
            [
                'title'                  => 'Tri et filtrage des produits',
                'description'            => "Permettre à l'utilisateur de trier les produits du catalogue selon "
                    . "différents critères pour faciliter la recherche du produit souhaité.",
                'as_a'                   => 'utilisateur souhaitant trouver un produit spécifique',
                'i_want_that'            => 'je filtre et trie les produits par nom ou par prix',
                'so_that'                => "je trouve rapidement l'article correspondant à mes besoins et à mon budget",
                'acceptance_criteria'    => "- Un sélecteur de tri est visible en haut de la page inventaire\n"
                    . "- Les 4 options disponibles : Name (A-Z), Name (Z-A), Price (Low-High), Price (High-Low)\n"
                    . "- Le tri est immédiatement appliqué\n"
                    . "- L'option sélectionnée reste active après navigation",
                'business_rules'         => [
                    "Le tri par défaut est Name (A to Z)",
                    "Le tri est côté client, sans rechargement de page",
                    "Les prix sont triés numériquement (pas alphabétiquement)",
                ],
                'scenarios'              => [
                    ['titre' => 'Tri par prix croissant', 'etapes' => ['Sélectionner Price (Low to High)', 'Vérifier l\'ordre du moins cher au plus cher']],
                    ['titre' => 'Tri par nom Z→A',        'etapes' => ['Sélectionner Name (Z to A)', 'Vérifier l\'ordre alphabétique inversé']],
                ],
                'effort_points'          => 3,
                'business_value'         => 5,
                'start_date'             => '2026-06-02',
                'target_completion_date' => '2026-06-10',
                'status'                 => 'backlog',
                'priority'               => 'medium',
                'created_by'             => $raja->id,
            ]
        );

        // ── 4. CHECKLISTS ─────────────────────────────────────────────────────

        // CL-01 — Connexion : scénarios positifs (manual, US1)
        $cl01 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-01 – Connexion : scénarios positifs'],
            [
                'description'         => "Vérification des cas de connexion valide : identifiants corrects, "
                    . "redirection post-connexion et persistance de session.",
                'category'            => 'Authentification',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'utilisateur standard',
                'i_want_that'         => 'me connecter avec des identifiants valides',
                'so_that'             => "j'accède au catalogue produits",
                'acceptance_criteria' => "- Connexion réussie avec standard_user / secret_sauce\n"
                    . "- Redirection vers /inventory.html\n"
                    . "- Session maintenue après rafraîchissement",
                'priority'            => 'critical',
                'status'              => 'completed',
                'started_at'          => Carbon::parse('2026-05-20 09:00:00'),
                'completed_at'        => Carbon::parse('2026-05-21 17:00:00'),
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'manual',
                'source_user_story_id'=> $us1->id,
            ]
        );

        // CL-02 — Connexion : scénarios négatifs (ai, US1)
        $cl02 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-02 – Connexion : scénarios négatifs'],
            [
                'description'         => "Vérification des cas de connexion invalide : mauvais mot de passe, "
                    . "compte verrouillé, champs vides et tentatives d'injection.",
                'category'            => 'Authentification',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'utilisateur malveillant ou en erreur',
                'i_want_that'         => 'tenter de me connecter avec des identifiants incorrects',
                'so_that'             => "le système rejette l'accès et affiche des messages d'erreur appropriés",
                'acceptance_criteria' => "- Message d'erreur pour identifiants invalides\n"
                    . "- Compte verrouillé correctement géré\n"
                    . "- Injection SQL sans effet sur l'authentification",
                'priority'            => 'high',
                'status'              => 'completed',
                'started_at'          => Carbon::parse('2026-05-21 09:00:00'),
                'completed_at'        => Carbon::parse('2026-05-22 14:00:00'),
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'ai',
                'source_user_story_id'=> $us1->id,
            ]
        );

        // CL-03 — Affichage du catalogue produits (manual, US2)
        $cl03 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-03 – Affichage du catalogue produits'],
            [
                'description'         => "Vérification de l'affichage complet du catalogue : nombre de produits, "
                    . "données affichées, formatage des prix et chargement des images.",
                'category'            => 'Catalogue',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'utilisateur connecté',
                'i_want_that'         => 'visualiser le catalogue complet avec toutes les informations produits',
                'so_that'             => "je puisse choisir un article à acheter",
                'acceptance_criteria' => "- 6 produits visibles sur /inventory.html\n"
                    . "- Chaque produit : image + nom + description + prix\n"
                    . "- Prix formatés avec symbole $",
                'priority'            => 'high',
                'status'              => 'completed',
                'started_at'          => Carbon::parse('2026-05-22 10:00:00'),
                'completed_at'        => Carbon::parse('2026-05-23 16:00:00'),
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'manual',
                'source_user_story_id'=> $us2->id,
            ]
        );

        // CL-04 — Navigation dans le catalogue (reuse, US2)
        $cl04 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-04 – Navigation dans le catalogue'],
            [
                'description'         => "Vérification de la navigation entre la liste et les fiches produits : "
                    . "accès à la fiche, bouton retour et cohérence des URLs.",
                'category'            => 'Navigation',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'utilisateur parcourant le catalogue',
                'i_want_that'         => 'naviguer facilement entre la liste et les fiches produits',
                'so_that'             => "j'obtienne les détails d'un produit sans perdre ma position dans le catalogue",
                'acceptance_criteria' => "- Clic sur un produit ouvre sa fiche\n"
                    . "- Back to products ramène au catalogue\n"
                    . "- L'URL est mise à jour correctement",
                'priority'            => 'medium',
                'status'              => 'in_progress',
                'started_at'          => Carbon::parse('2026-05-23 09:00:00'),
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'reuse',
                'source_user_story_id'=> $us2->id,
            ]
        );

        // CL-05 — Ajout de produits au panier (ai, US3)
        $cl05 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-05 – Ajout de produits au panier'],
            [
                'description'         => "Vérification du mécanisme d'ajout au panier : compteur, changement "
                    . "du bouton, persistance inter-pages et gestion multi-produits.",
                'category'            => 'Panier',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'acheteur',
                'i_want_that'         => "ajouter des produits à mon panier avec confirmation visuelle",
                'so_that'             => "je sache que ma sélection est bien enregistrée",
                'acceptance_criteria' => "- Add to cart incrémente le compteur\n"
                    . "- Bouton devient Remove après ajout\n"
                    . "- Panier persisté entre les pages",
                'priority'            => 'high',
                'status'              => 'ready_for_test',
                'started_at'          => Carbon::parse('2026-05-26 09:00:00'),
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'ai',
                'source_user_story_id'=> $us3->id,
            ]
        );

        // CL-06 — Suppression de produits du panier (manual, US3)
        $cl06 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-06 – Suppression de produits du panier'],
            [
                'description'         => "Vérification du mécanisme de suppression du panier : décrémentation "
                    . "du compteur, panier vide et réinitialisation des boutons.",
                'category'            => 'Panier',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'acheteur souhaitant modifier sa sélection',
                'i_want_that'         => "supprimer des produits de mon panier",
                'so_that'             => "je puisse ajuster ma commande avant de finaliser l'achat",
                'acceptance_criteria' => "- Remove décrémente le compteur\n"
                    . "- Panier vide affiche un message approprié\n"
                    . "- Bouton redevient Add to cart après suppression",
                'priority'            => 'medium',
                'status'              => 'in_progress',
                'started_at'          => Carbon::parse('2026-05-27 14:00:00'),
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'manual',
                'source_user_story_id'=> $us3->id,
            ]
        );

        // CL-07 — Formulaire de commande (manual, US4)
        $cl07 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-07 – Formulaire de commande'],
            [
                'description'         => "Vérification du formulaire Checkout Step One : présence des champs, "
                    . "validation des données saisies et gestion des erreurs.",
                'category'            => 'Commande',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'acheteur finalisant sa commande',
                'i_want_that'         => "renseigner mes informations de livraison dans un formulaire valide",
                'so_that'             => "ma commande soit expédiée à la bonne adresse",
                'acceptance_criteria' => "- Champs prénom, nom et code postal présents\n"
                    . "- Erreur si champs vides\n"
                    . "- Bouton Continue accessible après remplissage valide",
                'priority'            => 'critical',
                'status'              => 'in_progress',
                'started_at'          => Carbon::parse('2026-05-30 09:00:00'),
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'manual',
                'source_user_story_id'=> $us4->id,
            ]
        );

        // CL-08 — Validation du paiement et confirmation (ai, US4)
        $cl08 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-08 – Validation du paiement et confirmation'],
            [
                'description'         => "Vérification du récapitulatif de commande, du calcul des taxes, "
                    . "de la finalisation du paiement et de la page de confirmation.",
                'category'            => 'Paiement',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'acheteur validant sa commande',
                'i_want_that'         => "voir le récapitulatif de mon achat avec le montant total avant de confirmer",
                'so_that'             => "je m'assure que ma commande est correcte avant paiement",
                'acceptance_criteria' => "- Récapitulatif affiche les produits et le total\n"
                    . "- Taxes calculées et affichées\n"
                    . "- Bouton Finish confirme la commande\n"
                    . "- Page de confirmation avec message de succès",
                'priority'            => 'critical',
                'status'              => 'in_progress',
                'started_at'          => Carbon::parse('2026-06-01 09:00:00'),
                'completed_at'        => null,
                'template_scope'      => 'project',
                'lifecycle_status'    => 'approved',
                'generated_from'      => 'ai',
                'source_user_story_id'=> $us4->id,
            ]
        );

        // CL-09 — Tri des produits (manual, US5)
        $cl09 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-09 – Tri des produits'],
            [
                'description'         => "Vérification des options de tri disponibles et de leur application "
                    . "correcte sur la liste des produits.",
                'category'            => 'Filtres & Tri',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $yahia->id,
                'as_a'                => 'utilisateur recherchant un produit',
                'i_want_that'         => 'trier les produits par nom ou par prix',
                'so_that'             => "je localise rapidement le produit que je cherche",
                'acceptance_criteria' => "- Les 4 options de tri sont disponibles\n"
                    . "- Le tri A→Z est le tri par défaut\n"
                    . "- Chaque option applique correctement le tri",
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

        // CL-10 — Accessibilité et persistance du tri (reuse, US5)
        $cl10 = Checklist::updateOrCreate(
            ['project_id' => $project->id, 'name' => 'CL-10 – Accessibilité et persistance du tri'],
            [
                'description'         => "Vérification de l'accessibilité du sélecteur de tri et de la persistance "
                    . "de l'option sélectionnée entre les pages.",
                'category'            => 'Filtres & Tri',
                'is_active'           => true,
                'created_by'          => $raja->id,
                'assigned_to'         => $oussama->id,
                'as_a'                => 'utilisateur naviguant dans le catalogue',
                'i_want_that'         => "retrouver mon option de tri après navigation",
                'so_that'             => "je n'aie pas à re-sélectionner mon tri à chaque page",
                'acceptance_criteria' => "- Sélecteur de tri visible et accessible\n"
                    . "- L'option de tri est maintenue après retour au catalogue\n"
                    . "- L'URL reflète l'option sélectionnée",
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

        // Liaison project_checklists
        $project->checklists()->syncWithoutDetaching(
            collect(array_values($clists))->pluck('id')->toArray()
        );

        // ── 5. CHECKLIST ITEMS ────────────────────────────────────────────────
        // Colonnes : [title, description, priority(capitalized), criticality(capitalized),
        //             status, order, tested_by_id|null, tested_at|null, qa_comment|null]

        // CL-01 — 5 items (tous passed)
        $items01 = $this->seedItems($cl01, [
            [
                'TC-001 | Connexion avec identifiants standards valides',
                "Vérifier qu'avec standard_user / secret_sauce la connexion aboutit et redirige vers /inventory.html.",
                'High', 'Critical', 'passed', 1, $yahia->id, '2026-05-21 10:05:00',
            ],
            [
                'TC-002 | Redirection vers la page inventaire après connexion',
                "S'assurer que l'URL après connexion réussie est exactement /inventory.html.",
                'High', 'Major', 'passed', 2, $yahia->id, '2026-05-21 10:08:00',
            ],
            [
                'TC-003 | Persistance de session après rafraîchissement',
                "Rafraîchir la page /inventory.html et vérifier que l'utilisateur reste connecté sans être redirigé.",
                'Medium', 'Major', 'passed', 3, $yahia->id, '2026-05-21 10:12:00',
            ],
            [
                'TC-004 | Nom d\'utilisateur visible dans le menu après connexion',
                "Ouvrir le menu hamburger et vérifier que le nom du compte connecté est affiché.",
                'Low', 'Minor', 'passed', 4, $yahia->id, '2026-05-21 10:15:00',
            ],
            [
                'TC-005 | Bouton de déconnexion accessible dans le menu latéral',
                "Ouvrir le menu et vérifier la présence et la fonctionnalité du lien Logout.",
                'Low', 'Minor', 'passed', 5, $yahia->id, '2026-05-21 10:18:00',
            ],
        ]);

        // CL-02 — 5 items (2 failed, 2 passed, 1 pending)
        $items02 = $this->seedItems($cl02, [
            [
                'TC-006 | Connexion avec mot de passe incorrect',
                "Saisir standard_user avec un mot de passe erroné et vérifier l'affichage d'un message d'erreur approprié.",
                'High', 'Critical', 'failed', 1, $oussama->id, '2026-05-22 09:20:00',
                "Le message d'erreur affiché ne mentionne pas explicitement le champ en erreur — non conforme aux spécifications.",
            ],
            [
                'TC-007 | Connexion avec le champ identifiant vide',
                'Laisser le champ username vide, cliquer Login et vérifier le message "Username is required".',
                'High', 'Major', 'passed', 2, $oussama->id, '2026-05-22 09:25:00',
            ],
            [
                'TC-008 | Connexion avec le compte locked_out_user',
                "Saisir locked_out_user / secret_sauce et vérifier l'affichage du message de compte verrouillé.",
                'High', 'Critical', 'passed', 3, $oussama->id, '2026-05-22 09:30:00',
            ],
            [
                "TC-009 | Message d'erreur clair et lisible sur identifiants invalides",
                "Vérifier que le message d'erreur est visible, compréhensible et respecte les normes de contraste WCAG AA.",
                'Medium', 'Major', 'failed', 4, $oussama->id, '2026-05-22 09:35:00',
                "La couleur du texte d'erreur présente un contraste insuffisant (ratio 2.8:1 mesuré — WCAG AA exige 4.5:1).",
            ],
            [
                "TC-010 | Résistance à l'injection SQL dans le formulaire de connexion",
                "Saisir une payload SQL (ex : ' OR 1=1 --) dans le champ username et vérifier l'absence de réponse anormale.",
                'Medium', 'Critical', 'pending', 5, null, null,
            ],
        ]);

        // CL-03 — 5 items (4 passed, 1 failed)
        $items03 = $this->seedItems($cl03, [
            [
                'TC-011 | 6 produits affichés sur la page inventaire',
                "Compter les cartes produits affichées sur /inventory.html et vérifier qu'il y en a exactement 6.",
                'High', 'Critical', 'passed', 1, $yahia->id, '2026-05-23 10:05:00',
            ],
            [
                'TC-012 | Chaque produit affiche nom, image et prix',
                "Pour chaque carte produit, vérifier la présence du nom, de l'image et du prix formaté.",
                'High', 'Major', 'passed', 2, $yahia->id, '2026-05-23 10:10:00',
            ],
            [
                'TC-013 | Prix correctement formatés avec symbole $',
                "Vérifier que chaque prix est affiché sous la forme \$X.XX sans déformation ni troncature.",
                'Medium', 'Minor', 'passed', 3, $yahia->id, '2026-05-23 10:14:00',
            ],
            [
                'TC-014 | Images produits chargées sans erreur 404',
                "Vérifier qu'aucune image produit ne retourne une erreur 404 ou ne s'affiche de façon cassée.",
                'High', 'Major', 'passed', 4, $yahia->id, '2026-05-23 10:18:00',
            ],
            [
                'TC-015 | Description produit visible sur la carte catalogue',
                "Vérifier que la description courte du produit est affichée sous le titre sur la carte catalogue.",
                'Low', 'Minor', 'failed', 5, $yahia->id, '2026-05-23 10:22:00',
                "Sur mobile (viewport 375px), la description est tronquée après 1 ligne au lieu de 3 lignes attendues.",
            ],
        ]);

        // CL-04 — 4 items (2 passed, 2 pending)
        $items04 = $this->seedItems($cl04, [
            [
                'TC-016 | Accès à la fiche produit depuis le catalogue',
                "Cliquer sur le nom d'un produit et vérifier l'ouverture de sa fiche détaillée.",
                'High', 'Major', 'passed', 1, $oussama->id, '2026-05-24 09:05:00',
            ],
            [
                'TC-017 | Bouton Back to products fonctionnel',
                "Depuis une fiche produit, cliquer Back to products et vérifier le retour correct au catalogue.",
                'High', 'Major', 'passed', 2, $oussama->id, '2026-05-24 09:10:00',
            ],
            [
                'TC-018 | URL mise à jour lors de la navigation vers une fiche produit',
                "Vérifier que l'URL change lors de l'accès à une fiche produit et contient l'identifiant du produit.",
                'Medium', 'Minor', 'pending', 3, null, null,
            ],
            [
                'TC-019 | Titre de page actualisé sur chaque fiche produit',
                "Vérifier que le <title> de la page change pour refléter le nom du produit consulté.",
                'Low', 'Minor', 'pending', 4, null, null,
            ],
        ]);

        // CL-05 — 6 items (4 passed, 1 failed, 1 blocked)
        $items05 = $this->seedItems($cl05, [
            [
                "TC-020 | Ajout d'un produit depuis le catalogue",
                "Cliquer Add to cart sur un produit depuis /inventory.html et vérifier le compteur panier = 1.",
                'High', 'Critical', 'passed', 1, $yahia->id, '2026-05-29 09:10:00',
            ],
            [
                'TC-021 | Compteur du panier incrémenté après chaque ajout',
                "Ajouter 2 produits distincts et vérifier que le compteur affiché dans l'icône panier est à 2.",
                'High', 'Major', 'passed', 2, $yahia->id, '2026-05-29 09:20:00',
            ],
            [
                'TC-022 | Ajout de plusieurs produits distincts et vérification dans le panier',
                "Ajouter 3 produits différents et vérifier qu'ils apparaissent tous dans la page /cart.html.",
                'High', 'Major', 'passed', 3, $yahia->id, '2026-05-29 09:30:00',
            ],
            [
                "TC-023 | Bouton Add to cart devient Remove immédiatement après ajout",
                "Vérifier que le texte du bouton change en \"Remove\" sans délai perceptible après l'ajout.",
                'Medium', 'Major', 'failed', 4, $yahia->id, '2026-05-29 09:40:00',
                "Délai de 820ms mesuré avant le changement du texte du bouton — l'indicateur visuel n'est pas immédiat.",
            ],
            [
                "TC-024 | Produit présent dans le panier après navigation inter-pages",
                "Ajouter un produit, naviguer sur une fiche produit, revenir et vérifier que le produit est toujours dans le panier.",
                'High', 'Critical', 'passed', 5, $yahia->id, '2026-05-29 09:50:00',
            ],
            [
                'TC-025 | Panier conservé après rafraîchissement de page',
                "Ajouter un produit, rafraîchir la page (/inventory.html) et vérifier la persistance du panier.",
                'High', 'Critical', 'blocked', 6, null, null,
                "Impossible de tester : la session est réinitialisée lors du rafraîchissement dans l'environnement CI. À tester en conditions réelles.",
            ],
        ]);

        // CL-06 — 4 items (2 passed, 2 pending)
        $items06 = $this->seedItems($cl06, [
            [
                "TC-026 | Suppression d'un produit depuis la fiche produit",
                "Depuis la fiche d'un produit ajouté au panier, cliquer Remove et vérifier le décrémentage du compteur.",
                'High', 'Major', 'passed', 1, $oussama->id, '2026-05-28 10:10:00',
            ],
            [
                'TC-027 | Compteur du panier décrémenté après suppression',
                "Vérifier que le compteur passe de N à N-1 après chaque suppression d'un produit.",
                'High', 'Major', 'passed', 2, $oussama->id, '2026-05-28 10:18:00',
            ],
            [
                'TC-028 | Panier vide affiche un message approprié',
                "Supprimer tous les produits du panier et vérifier l'affichage d'un message indiquant que le panier est vide.",
                'Medium', 'Minor', 'pending', 3, null, null,
            ],
            [
                "TC-029 | Suppression de l'ensemble des produits jusqu'à panier vide",
                "Ajouter 3 produits, les supprimer un à un et vérifier que le compteur atteint 0.",
                'Medium', 'Minor', 'pending', 4, null, null,
            ],
        ]);

        // CL-07 — 5 items (1 passed, 3 failed, 1 pending)
        $items07 = $this->seedItems($cl07, [
            [
                'TC-030 | Accès au formulaire checkout depuis la page panier',
                "Depuis la page /cart.html avec des produits, cliquer Checkout et vérifier l'arrivée sur le formulaire.",
                'High', 'Critical', 'failed', 1, $yahia->id, '2026-06-02 09:10:00',
                "Bouton Checkout rendu inactif (disabled) lorsque le panier contient plus de 3 produits — comportement non documenté.",
            ],
            [
                'TC-031 | Présence des champs prénom, nom et code postal',
                "Vérifier la présence et l'accessibilité des 3 champs requis sur le formulaire Checkout Step One.",
                'High', 'Critical', 'failed', 2, $yahia->id, '2026-06-02 09:18:00',
                "Le champ code postal est absent dans la version mobile (viewport < 768px) — régression non documentée.",
            ],
            [
                'TC-032 | Validation des champs obligatoires vides',
                "Cliquer Continue sans remplir les champs et vérifier l'affichage de messages de validation appropriés.",
                'High', 'Major', 'failed', 3, $yahia->id, '2026-06-02 09:26:00',
                "Le message d'erreur pour le code postal apparaît correctement mais celui pour le prénom ne s'affiche pas.",
            ],
            [
                'TC-033 | Soumission réussie avec tous les champs renseignés',
                "Remplir les 3 champs correctement, cliquer Continue et vérifier la redirection vers l'étape 2.",
                'High', 'Critical', 'passed', 4, $yahia->id, '2026-06-02 09:38:00',
            ],
            [
                'TC-034 | Page de confirmation affichée après finalisation',
                "Après le Finish, vérifier l'affichage de la page de confirmation avec le message \"Thank you for your order!\".",
                'High', 'Critical', 'pending', 5, null, null,
            ],
        ]);

        // CL-08 — 4 items (1 passed, 1 failed, 1 blocked, 1 pending)
        $items08 = $this->seedItems($cl08, [
            [
                'TC-035 | Récapitulatif de commande avec sous-total correct',
                "Vérifier que le récapitulatif Checkout Step Two affiche le sous-total des produits ajoutés.",
                'High', 'Major', 'passed', 1, $oussama->id, '2026-06-03 09:10:00',
            ],
            [
                'TC-036 | Taxes calculées et affichées correctement',
                "Vérifier que les taxes correspondent à 8% du sous-total et sont visibles sur la page de récapitulatif.",
                'High', 'Critical', 'blocked', 2, null, null,
                "Spécification du taux de taxe non documentée — impossible de valider sans référence officielle. Escalade vers le Product Owner.",
            ],
            [
                'TC-037 | Bouton Finish finalise la commande et redirige',
                "Cliquer Finish et vérifier la redirection vers /checkout-complete.html avec la page de confirmation.",
                'High', 'Critical', 'failed', 3, $oussama->id, '2026-06-03 09:40:00',
                "La page /checkout-complete.html se charge mais le message de confirmation ne s'affiche pas dans Firefox 125.",
            ],
            [
                'TC-038 | Page de confirmation avec message et icône de succès',
                "Vérifier la présence du texte \"THANK YOU FOR YOUR ORDER\" et de l'icône de confirmation.",
                'High', 'Critical', 'pending', 4, null, null,
            ],
        ]);

        // CL-09 — 4 items (2 passed, 2 pending)
        $items09 = $this->seedItems($cl09, [
            [
                'TC-039 | Tri par nom A→Z fonctionnel',
                "Sélectionner Name (A to Z) et vérifier l'ordre alphabétique de la liste des produits.",
                'Medium', 'Major', 'passed', 1, $yahia->id, '2026-06-04 10:05:00',
            ],
            [
                'TC-040 | Tri par nom Z→A fonctionnel',
                "Sélectionner Name (Z to A) et vérifier l'ordre alphabétique inversé de la liste.",
                'Medium', 'Major', 'passed', 2, $yahia->id, '2026-06-04 10:12:00',
            ],
            [
                'TC-041 | Tri par prix croissant correct',
                "Sélectionner Price (Low to High) et vérifier que les produits sont triés du moins cher au plus cher.",
                'Medium', 'Minor', 'pending', 3, null, null,
            ],
            [
                'TC-042 | Tri par prix décroissant correct',
                "Sélectionner Price (High to Low) et vérifier que les produits sont triés du plus cher au moins cher.",
                'Medium', 'Minor', 'pending', 4, null, null,
            ],
        ]);

        // CL-10 — 4 items (2 passed, 1 failed, 1 pending)
        $items10 = $this->seedItems($cl10, [
            [
                'TC-043 | Sélecteur de tri visible et accessible sur la page inventaire',
                "Vérifier la visibilité et l'accessibilité du menu déroulant de tri en haut de /inventory.html.",
                'Medium', 'Minor', 'passed', 1, $oussama->id, '2026-06-05 09:05:00',
            ],
            [
                'TC-044 | Les 4 options de tri sont disponibles dans la liste',
                "Ouvrir le sélecteur de tri et vérifier la présence des 4 options attendues.",
                'Medium', 'Minor', 'passed', 2, $oussama->id, '2026-06-05 09:10:00',
            ],
            [
                "TC-045 | Option de tri maintenue après retour au catalogue",
                "Naviguer vers une fiche produit, revenir au catalogue et vérifier que l'option de tri est toujours sélectionnée.",
                'Low', 'Minor', 'failed', 3, $oussama->id, '2026-06-05 09:20:00',
                "Le tri est réinitialisé à A→Z lors du retour au catalogue depuis une fiche produit.",
            ],
            [
                "TC-046 | URL reflète l'option de tri sélectionnée",
                "Sélectionner chaque option de tri et vérifier que l'URL est mise à jour avec le paramètre correspondant.",
                'Low', 'Minor', 'pending', 4, null, null,
            ],
        ]);

        // ── 6. LIAISONS USER STORIES <-> CHECKLISTS ──────────────────────────
        $us1->checklists()->syncWithoutDetaching([
            $cl01->id => ['is_generated_from_arxis' => false, 'relevance_score' => 98, 'link_type' => 'attached'],
            $cl02->id => ['is_generated_from_arxis' => true,  'relevance_score' => 95, 'link_type' => 'attached'],
        ]);
        $us2->checklists()->syncWithoutDetaching([
            $cl03->id => ['is_generated_from_arxis' => false, 'relevance_score' => 96, 'link_type' => 'attached'],
            $cl04->id => ['is_generated_from_arxis' => false, 'relevance_score' => 87, 'link_type' => 'attached'],
        ]);
        $us3->checklists()->syncWithoutDetaching([
            $cl05->id => ['is_generated_from_arxis' => true,  'relevance_score' => 94, 'link_type' => 'attached'],
            $cl06->id => ['is_generated_from_arxis' => false, 'relevance_score' => 89, 'link_type' => 'attached'],
        ]);
        $us4->checklists()->syncWithoutDetaching([
            $cl07->id => ['is_generated_from_arxis' => false, 'relevance_score' => 97, 'link_type' => 'attached'],
            $cl08->id => ['is_generated_from_arxis' => true,  'relevance_score' => 92, 'link_type' => 'attached'],
        ]);
        $us5->checklists()->syncWithoutDetaching([
            $cl09->id => ['is_generated_from_arxis' => false, 'relevance_score' => 91, 'link_type' => 'attached'],
            $cl10->id => ['is_generated_from_arxis' => false, 'relevance_score' => 83, 'link_type' => 'attached'],
        ]);

        // ── 7. PROJECT VERSIONS + VERSION ITEMS ───────────────────────────────
        // version 1 — basée sur CL-01 (authentification, completed)
        $v1 = ProjectVersion::updateOrCreate(
            ['project_id' => $project->id, 'version_number' => 1],
            ['checklist_id' => $cl01->id]
        );
        $this->seedVersionItems($v1, $items01, $yahia->id);

        // version 2 — basée sur CL-03 (catalogue, completed)
        $v2 = ProjectVersion::updateOrCreate(
            ['project_id' => $project->id, 'version_number' => 2],
            ['checklist_id' => $cl03->id]
        );
        $this->seedVersionItems($v2, $items03, $yahia->id);

        // version 3 — basée sur CL-05 (panier, ready_for_test)
        $v3 = ProjectVersion::updateOrCreate(
            ['project_id' => $project->id, 'version_number' => 3],
            ['checklist_id' => $cl05->id]
        );
        $this->seedVersionItems($v3, $items05, $yahia->id);

        // ── 8. TEST RUNS + TEST RESULTS ───────────────────────────────────────

        // Run 1 — CL-01 complet : 5 passed
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
                'started_at'         => Carbon::parse('2026-05-21 10:00:00'),
                'finished_at'        => Carbon::parse('2026-05-21 10:20:00'),
                'summary_total'      => 5, 'summary_passed' => 5,
                'summary_failed'     => 0, 'summary_blocked' => 0, 'summary_skipped' => 0,
                'request_payload'    => [
                    'target_type' => 'checklist',
                    'checklist_id' => $cl01->id,
                    'mode'        => 'full-checklist',
                    'base_url'    => self::APP_URL,
                ],
            ]
        );
        foreach ($items01 as $item) {
            TestResult::updateOrCreate(
                ['test_run_id' => $run1->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => 'passed',
                    'duration_ms'     => rand(1500, 4000),
                    'artifacts'       => [],
                    'result_payload'  => ['status' => 'passed'],
                    'executed_at'     => Carbon::parse('2026-05-21 10:02:00')->addMinutes($item->order * 3),
                ]
            );
        }

        // Run 2 — CL-02 complet : 2 passed, 2 failed, 1 skipped
        $run2 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run2']],
            [
                'project_version_id' => null,
                'checklist_id'       => $cl02->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'full-checklist',
                'status'             => 'completed',
                'requested_by'       => $oussama->id,
                'started_at'         => Carbon::parse('2026-05-22 09:10:00'),
                'finished_at'        => Carbon::parse('2026-05-22 09:40:00'),
                'summary_total'      => 5, 'summary_passed' => 2,
                'summary_failed'     => 2, 'summary_blocked' => 0, 'summary_skipped' => 1,
                'request_payload'    => [
                    'target_type' => 'checklist',
                    'checklist_id' => $cl02->id,
                    'base_url'    => self::APP_URL,
                ],
            ]
        );
        // TC-006→failed, TC-007→passed, TC-008→passed, TC-009→failed, TC-010→skipped
        $run2Map = [
            'TC-006' => 'failed',
            'TC-007' => 'passed',
            'TC-008' => 'passed',
            'TC-009' => 'failed',
            'TC-010' => 'skipped',
        ];
        foreach ($items02 as $item) {
            $tcKey  = trim(explode(' | ', $item->title)[0]);
            $status = $run2Map[$tcKey] ?? 'skipped';
            TestResult::updateOrCreate(
                ['test_run_id' => $run2->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => $status,
                    'error_message'   => $status === 'failed' ? "Assertion échouée : contenu du message d'erreur non conforme aux spécifications." : null,
                    'duration_ms'     => rand(2000, 6000),
                    'artifacts'       => [],
                    'result_payload'  => ['status' => $status],
                    'executed_at'     => Carbon::parse('2026-05-22 09:12:00')->addMinutes($item->order * 6),
                ]
            );
        }

        // Run 3 — CL-03 complet : 4 passed, 1 failed
        $run3 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run3']],
            [
                'project_version_id' => $v2->id,
                'checklist_id'       => $cl03->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'full-checklist',
                'status'             => 'completed',
                'requested_by'       => $yahia->id,
                'started_at'         => Carbon::parse('2026-05-23 10:00:00'),
                'finished_at'        => Carbon::parse('2026-05-23 10:25:00'),
                'summary_total'      => 5, 'summary_passed' => 4,
                'summary_failed'     => 1, 'summary_blocked' => 0, 'summary_skipped' => 0,
                'request_payload'    => [
                    'target_type' => 'checklist',
                    'checklist_id' => $cl03->id,
                    'base_url'    => self::APP_URL,
                ],
            ]
        );
        foreach ($items03 as $item) {
            $status = $item->status === 'failed' ? 'failed' : 'passed';
            TestResult::updateOrCreate(
                ['test_run_id' => $run3->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => $status,
                    'error_message'   => $status === 'failed' ? 'Description tronquée sur viewport mobile 375px.' : null,
                    'duration_ms'     => rand(1800, 5000),
                    'artifacts'       => [],
                    'result_payload'  => ['status' => $status],
                    'executed_at'     => Carbon::parse('2026-05-23 10:03:00')->addMinutes($item->order * 5),
                ]
            );
        }

        // Run 4 — CL-05 complet : 4 passed, 1 failed, 1 blocked
        $run4 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run4']],
            [
                'project_version_id' => $v3->id,
                'checklist_id'       => $cl05->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'full-checklist',
                'status'             => 'completed',
                'requested_by'       => $yahia->id,
                'started_at'         => Carbon::parse('2026-05-29 09:00:00'),
                'finished_at'        => Carbon::parse('2026-05-29 09:55:00'),
                'summary_total'      => 6, 'summary_passed' => 4,
                'summary_failed'     => 1, 'summary_blocked' => 1, 'summary_skipped' => 0,
                'request_payload'    => [
                    'target_type' => 'checklist',
                    'checklist_id' => $cl05->id,
                    'base_url'    => self::APP_URL,
                ],
            ]
        );
        foreach ($items05 as $item) {
            $status       = match ($item->status) {
                'failed'  => 'failed',
                'blocked' => 'blocked',
                default   => 'passed',
            };
            $errorMessage = match ($status) {
                'failed'  => "Délai de 820ms mesuré avant le changement du texte du bouton.",
                'blocked' => "Environnement CI ne supporte pas la persistance de session entre rechargements.",
                default   => null,
            };
            TestResult::updateOrCreate(
                ['test_run_id' => $run4->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => $status,
                    'error_message'   => $errorMessage,
                    'duration_ms'     => rand(2500, 8000),
                    'artifacts'       => [],
                    'result_payload'  => ['status' => $status],
                    'executed_at'     => Carbon::parse('2026-05-29 09:05:00')->addMinutes($item->order * 8),
                ]
            );
        }

        // Run 5 — CL-07 : statut failed (3 failed, 1 passed, 1 skipped)
        $run5 = TestRun::updateOrCreate(
            ['run_id' => self::RUN_IDS['run5']],
            [
                'project_version_id' => null,
                'checklist_id'       => $cl07->id,
                'schema_version'     => '1.0',
                'base_url'           => self::APP_URL,
                'mode'               => 'full-checklist',
                'status'             => 'failed',
                'requested_by'       => $yahia->id,
                'started_at'         => Carbon::parse('2026-06-02 09:00:00'),
                'finished_at'        => Carbon::parse('2026-06-02 09:48:00'),
                'summary_total'      => 5, 'summary_passed' => 1,
                'summary_failed'     => 3, 'summary_blocked' => 0, 'summary_skipped' => 1,
                'request_payload'    => [
                    'target_type' => 'checklist',
                    'checklist_id' => $cl07->id,
                    'base_url'    => self::APP_URL,
                ],
            ]
        );
        // order 1,2,3 → failed | order 4 → passed | order 5 → skipped
        $run5Map = [1 => 'failed', 2 => 'failed', 3 => 'failed', 4 => 'passed', 5 => 'skipped'];
        foreach ($items07 as $item) {
            $status = $run5Map[$item->order] ?? 'skipped';
            TestResult::updateOrCreate(
                ['test_run_id' => $run5->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => $status,
                    'error_message'   => $status === 'failed' ? "Échec de l'assertion — comportement non conforme aux spécifications fonctionnelles." : null,
                    'duration_ms'     => rand(3000, 7000),
                    'artifacts'       => [],
                    'result_payload'  => ['status' => $status],
                    'executed_at'     => Carbon::parse('2026-06-02 09:05:00')->addMinutes($item->order * 9),
                ]
            );
        }

        // Run 6 — CL-01 regression : 5 passed
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
                'started_at'         => Carbon::parse('2026-06-05 14:00:00'),
                'finished_at'        => Carbon::parse('2026-06-05 14:18:00'),
                'summary_total'      => 5, 'summary_passed' => 5,
                'summary_failed'     => 0, 'summary_blocked' => 0, 'summary_skipped' => 0,
                'request_payload'    => [
                    'target_type' => 'checklist',
                    'checklist_id' => $cl01->id,
                    'mode'        => 'regression',
                    'base_url'    => self::APP_URL,
                    'notes'       => "Run de régression suite aux corrections apportées sur le module d'authentification.",
                ],
            ]
        );
        foreach ($items01 as $item) {
            TestResult::updateOrCreate(
                ['test_run_id' => $run6->id, 'checklist_item_id' => $item->id],
                [
                    'version_item_id' => null,
                    'status'          => 'passed',
                    'duration_ms'     => rand(1200, 3500),
                    'artifacts'       => [],
                    'result_payload'  => ['status' => 'passed', 'note' => 'Run de régression — tous les cas passent.'],
                    'executed_at'     => Carbon::parse('2026-06-05 14:02:00')->addMinutes($item->order * 3),
                ]
            );
        }

        // ── 9. HISTORIQUE DES CHECKLIST ITEMS ────────────────────────────────
        // DB::table pour contrôler les timestamps ; updateOrInsert évite les doublons.
        $now = Carbon::now();
        $histories = [
            // TC-006 : pending → failed (Oussama, 2026-05-22)
            [$items02[0]->id, $oussama->id, 'status', 'pending', 'failed', 'status_change',
                "Message d'erreur non conforme aux spécifications — item passé en échec.", '2026-05-22 09:22:00'],
            // TC-009 : pending → failed (Oussama, 2026-05-22)
            [$items02[3]->id, $oussama->id, 'status', 'pending', 'failed', 'status_change',
                "Contraste WCAG insuffisant confirmé avec l'outil Colour Contrast Analyser.", '2026-05-22 09:38:00'],
            // TC-015 : pending → failed (Yahia, 2026-05-23)
            [$items03[4]->id, $yahia->id, 'status', 'pending', 'failed', 'status_change',
                "Troncature reproduite systématiquement sur Chrome mobile et Firefox mobile.", '2026-05-23 10:25:00'],
            // TC-023 : pending → failed (Yahia, 2026-05-29)
            [$items05[3]->id, $yahia->id, 'status', 'pending', 'failed', 'status_change',
                "Délai visuel de 820ms mesuré avec DevTools Performance sur Chrome 124.", '2026-05-29 09:42:00'],
            // TC-025 : pending → blocked (Raja, 2026-05-29)
            [$items05[5]->id, $raja->id, 'status', 'pending', 'blocked', 'status_change',
                "Bloqué par l'environnement CI — à rejouer en staging avant la recette finale.", '2026-05-29 11:00:00'],
            // TC-030 : pending → failed (Yahia, 2026-06-02)
            [$items07[0]->id, $yahia->id, 'status', 'pending', 'failed', 'status_change',
                "Checkout désactivé pour paniers > 3 produits — comportement non documenté dans les spécifications.", '2026-06-02 09:12:00'],
            // TC-031 : pending → failed (Yahia, 2026-06-02)
            [$items07[1]->id, $yahia->id, 'status', 'pending', 'failed', 'status_change',
                "Champ code postal absent sur viewport mobile — régression identifiée.", '2026-06-02 09:20:00'],
            // TC-032 : pending → failed (Yahia, 2026-06-02)
            [$items07[2]->id, $yahia->id, 'status', 'pending', 'failed', 'status_change',
                "Validation partielle : message d'erreur pour le prénom absent lors de la soumission.", '2026-06-02 09:30:00'],
            // TC-036 : pending → blocked (Raja, 2026-06-03)
            [$items08[1]->id, $raja->id, 'status', 'pending', 'blocked', 'status_change',
                "Taux de taxe non documenté — escalade vers le Product Owner en cours.", '2026-06-03 09:15:00'],
            // TC-045 : pending → failed (Oussama, 2026-06-05)
            [$items10[2]->id, $oussama->id, 'status', 'pending', 'failed', 'status_change',
                "Le tri est réinitialisé à A→Z lors du retour au catalogue depuis une fiche produit.", '2026-06-05 09:22:00'],
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
        $vi1 = VersionItem::where('project_version_id', $v1->id)->orderBy('order')->get();
        $vi2 = VersionItem::where('project_version_id', $v2->id)->orderBy('order')->get();
        $vi3 = VersionItem::where('project_version_id', $v3->id)->orderBy('order')->get();

        $comments = [
            // v1.0 — authentification (tous passed)
            [
                $vi1->get(0)?->id, $raja->id,
                "Excellent résultat — connexion validée sans régression. Ce cas de test sera intégré dans la suite de régression officielle du projet.",
                '2026-05-21 11:00:00',
            ],
            [
                $vi1->get(2)?->id, $yahia->id,
                "Persistance de session vérifiée sur Chrome 124, Edge 124 et Firefox 125. Comportement conforme aux attentes.",
                '2026-05-21 11:20:00',
            ],
            [
                $vi1->get(4)?->id, $oussama->id,
                "Lien Logout correctement positionné dans le menu latéral. Accessible au clavier (Tab + Entrée). Validé.",
                '2026-05-21 14:00:00',
            ],
            // v1.1 — catalogue (4 passed, 1 failed)
            [
                $vi2->get(4)?->id, $yahia->id,
                "La troncature est reproduite de façon systématique sur un viewport de 375px. Capture d'écran transmise à l'équipe développement.",
                '2026-05-23 10:35:00',
            ],
            [
                $vi2->get(4)?->id, $raja->id,
                "Bug confirmé côté développement — correctif prévu dans le sprint suivant. Priorité Medium. Ticket #DEV-214 créé.",
                '2026-05-23 14:10:00',
            ],
            [
                $vi2->get(1)?->id, $oussama->id,
                "Toutes les images produits se chargent en moins de 300ms sur une connexion 4G simulée — performance acceptable.",
                '2026-05-24 09:15:00',
            ],
            // v1.2 — panier (4 passed, 1 failed, 1 blocked)
            [
                $vi3->get(3)?->id, $yahia->id,
                "Délai de changement du bouton mesuré à 820ms en moyenne sur 5 tentatives. Rapport de performance envoyé à l'équipe front-end.",
                '2026-05-29 10:05:00',
            ],
            [
                $vi3->get(5)?->id, $raja->id,
                "Ce cas est bloqué par l'environnement CI uniquement. À rejouer impérativement en staging avant la recette finale. Noté en retard de sprint.",
                '2026-05-29 11:30:00',
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
        $this->command?->info('╔══════════════════════════════════════════╗');
        $this->command?->info('║        PfeDemoSeeder — Résumé            ║');
        $this->command?->info('╠══════════════════════════════════════════╣');
        $this->command?->info('║  Utilisateurs               :   4        ║');
        $this->command?->info('║  Projet                     :   1        ║');
        $this->command?->info('║  User stories               :   5        ║');
        $this->command?->info('║  Checklists                 :  10        ║');
        $this->command?->info('║  Checklist items            :  46        ║');
        $this->command?->info('║  Project versions           :   3        ║');
        $this->command?->info('║  Version items              :  16        ║');
        $this->command?->info('║  Test runs                  :   6        ║');
        $this->command?->info('║  Test results               :  31        ║');
        $this->command?->info('║  Historiques d\'items        :  10        ║');
        $this->command?->info('║  Commentaires               :   8        ║');
        $this->command?->info('╚══════════════════════════════════════════╝');
        $this->command?->info('  Application cible : ' . self::APP_URL);
        $this->command?->info('');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTHODES HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crée ou récupère un utilisateur avec le rôle indiqué.
     */
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
     * Crée ou met à jour les checklist items d'une checklist.
     *
     * Colonnes de chaque $row :
     *   [0] title           string
     *   [1] description     string
     *   [2] priority        'Low'|'Medium'|'High'  (capitalisé)
     *   [3] criticality     'Minor'|'Major'|'Critical' (capitalisé)
     *   [4] status          'pending'|'passed'|'failed'|'blocked'
     *   [5] order           int
     *   [6] tested_by       int|null
     *   [7] tested_at       string|null  (parsé en Carbon)
     *   [8] qa_comment      string|null  (optionnel)
     *
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
     * Crée ou met à jour les version items d'une project_version
     * en miroir des checklist items fournis.
     *
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
