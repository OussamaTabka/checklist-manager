<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use App\Models\UserStory;
use Illuminate\Database\Seeder;

class ProjectUserStorySamplesSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::where('email', 'admin@test.com')->first()
            ?? User::where('email', 'admin@example.com')->first()
            ?? User::first();

        if (!$creator) {
            $this->command?->error('Aucun utilisateur disponible pour creer des projets de demo.');
            return;
        }

        $projects = [
            [
                'name' => 'Portail Client Assurance',
                'description' => 'Plateforme client pour consulter les contrats, declarer des sinistres et suivre les remboursements.',
                'test_objectives' => 'Valider les parcours client critiques et la fiabilite des operations self-service.',
                'app_url' => 'http://localhost:5173',
                'stories' => [
                    [
                        'story_id' => 'INS-US-001',
                        'title' => 'Declaration de sinistre en ligne',
                        'description' => 'En tant que client, je veux declarer un sinistre en ligne afin d accelerer le traitement de ma demande.',
                        'acceptance_criteria' => "- Le formulaire de declaration est accessible\n- Les pieces jointes sont accepteees\n- Un recapitulatif est affiche avant confirmation",
                        'priority' => 'critical',
                        'status' => 'backlog',
                    ],
                    [
                        'story_id' => 'INS-US-002',
                        'title' => 'Suivi du statut de remboursement',
                        'description' => 'En tant que client, je veux suivre l avancement de mon remboursement afin de connaitre son etat en temps reel.',
                        'acceptance_criteria' => "- Le statut courant est visible\n- L historique des etapes est disponible\n- Les dates de mise a jour sont affichees",
                        'priority' => 'high',
                        'status' => 'backlog',
                    ],
                    [
                        'story_id' => 'INS-US-003',
                        'title' => 'Telechargement des attestations',
                        'description' => 'En tant que client, je veux telecharger mes attestations afin de les partager rapidement.',
                        'acceptance_criteria' => "- Les attestations disponibles sont listees\n- Le telechargement fonctionne\n- Le format du fichier est correct",
                        'priority' => 'medium',
                        'status' => 'backlog',
                    ],
                ],
            ],
            [
                'name' => 'Backoffice E-Commerce',
                'description' => 'Console d administration pour gerer catalogues, commandes et promotions.',
                'test_objectives' => 'Verifier la gestion operationnelle du catalogue et la stabilite des parcours d administration.',
                'app_url' => 'http://localhost:5173',
                'stories' => [
                    [
                        'story_id' => 'ECOM-US-001',
                        'title' => 'Creation de produit',
                        'description' => 'En tant qu administrateur, je veux creer un produit afin de l ajouter au catalogue.',
                        'acceptance_criteria' => "- Les champs obligatoires sont controles\n- Le produit est enregistre\n- Le produit apparait dans la liste",
                        'priority' => 'critical',
                        'status' => 'in_progress',
                    ],
                    [
                        'story_id' => 'ECOM-US-002',
                        'title' => 'Activation d une promotion',
                        'description' => 'En tant que gestionnaire marketing, je veux activer une promotion afin d appliquer une reduction temporaire.',
                        'acceptance_criteria' => "- Les dates de promotion sont configurees\n- La promotion est visible sur le produit\n- Le calcul du prix est coherent",
                        'priority' => 'high',
                        'status' => 'backlog',
                    ],
                ],
            ],
            [
                'name' => 'Application RH Interne',
                'description' => 'Outil interne pour conges, demandes administratives et suivi collaborateurs.',
                'test_objectives' => 'Securiser les workflows RH et verifier la bonne execution des validations.',
                'app_url' => 'http://localhost:5173',
                'stories' => [
                    [
                        'story_id' => 'HR-US-001',
                        'title' => 'Demande de conge',
                        'description' => 'En tant que collaborateur, je veux soumettre une demande de conge afin d obtenir une validation managere.',
                        'acceptance_criteria' => "- Les soldes sont affiches\n- La demande est soumise\n- Le manager recoit une notification",
                        'priority' => 'critical',
                        'status' => 'ready_for_test',
                    ],
                    [
                        'story_id' => 'HR-US-002',
                        'title' => 'Validation managere',
                        'description' => 'En tant que manager, je veux approuver ou refuser une demande afin de piloter la disponibilite de mon equipe.',
                        'acceptance_criteria' => "- Les actions approuver et refuser sont disponibles\n- Le commentaire est conserve\n- Le demandeur voit la decision",
                        'priority' => 'high',
                        'status' => 'backlog',
                    ],
                    [
                        'story_id' => 'HR-US-003',
                        'title' => 'Export des absences',
                        'description' => 'En tant que RH, je veux exporter les absences afin d alimenter le reporting mensuel.',
                        'acceptance_criteria' => "- Le filtre par periode fonctionne\n- Le fichier exporte est genere\n- Les colonnes attendues sont presentes",
                        'priority' => 'medium',
                        'status' => 'backlog',
                    ],
                ],
            ],
            [
                'name' => 'Plateforme Support Client',
                'description' => 'Espace agents pour le traitement des tickets et l escalation vers les equipes techniques.',
                'test_objectives' => 'Valider la creation, l affectation et la resolution des tickets de support.',
                'app_url' => 'http://localhost:5173',
                'stories' => [
                    [
                        'story_id' => 'SUP-US-001',
                        'title' => 'Creation de ticket',
                        'description' => 'En tant qu agent, je veux creer un ticket afin d enregistrer une demande client.',
                        'acceptance_criteria' => "- Le formulaire est valide\n- Le ticket recoit une reference unique\n- Le statut initial est coherent",
                        'priority' => 'critical',
                        'status' => 'backlog',
                    ],
                    [
                        'story_id' => 'SUP-US-002',
                        'title' => 'Affectation a une equipe',
                        'description' => 'En tant que superviseur, je veux affecter un ticket afin de l orienter vers la bonne equipe.',
                        'acceptance_criteria' => "- La liste des equipes est disponible\n- L affectation est enregistree\n- L historique du ticket est mis a jour",
                        'priority' => 'high',
                        'status' => 'in_progress',
                    ],
                ],
            ],
            [
                'name' => 'Dashboard Analytics SaaS',
                'description' => 'Produit SaaS pour tableaux de bord, filtres dynamiques et partage de rapports.',
                'test_objectives' => 'Verifier les calculs de KPI et la robustesse des interactions analytiques.',
                'app_url' => 'http://localhost:5173',
                'stories' => [
                    [
                        'story_id' => 'BI-US-001',
                        'title' => 'Filtrage par periode',
                        'description' => 'En tant qu utilisateur, je veux filtrer les indicateurs par periode afin d analyser une plage temporelle precise.',
                        'acceptance_criteria' => "- Le selecteur de dates est disponible\n- Les graphiques se rafraichissent\n- Les totaux sont recalcules",
                        'priority' => 'critical',
                        'status' => 'ready_for_test',
                    ],
                    [
                        'story_id' => 'BI-US-002',
                        'title' => 'Partage de rapport',
                        'description' => 'En tant qu utilisateur, je veux partager un rapport afin de collaborer avec mon equipe.',
                        'acceptance_criteria' => "- Le partage par lien est possible\n- Les droits sont respectes\n- Le destinataire accede au bon rapport",
                        'priority' => 'high',
                        'status' => 'backlog',
                    ],
                    [
                        'story_id' => 'BI-US-003',
                        'title' => 'Export CSV des indicateurs',
                        'description' => 'En tant qu utilisateur, je veux exporter les indicateurs en CSV afin de faire des analyses complementaires.',
                        'acceptance_criteria' => "- Le bouton export est accessible\n- Le fichier CSV est telecharge\n- Les en-tetes sont corrects",
                        'priority' => 'medium',
                        'status' => 'backlog',
                    ],
                ],
            ],
        ];

        $projectCount = 0;
        $storyCount = 0;

        foreach ($projects as $projectData) {
            $stories = $projectData['stories'];
            unset($projectData['stories']);

            $project = Project::updateOrCreate(
                ['name' => $projectData['name']],
                [
                    ...$projectData,
                    'created_by' => $creator->id,
                ]
            );

            $projectCount++;

            foreach (array_slice($stories, 0, 3) as $storyData) {
                UserStory::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'story_id' => $storyData['story_id'],
                    ],
                    [
                        'title' => $storyData['title'],
                        'description' => $storyData['description'],
                        'acceptance_criteria' => $storyData['acceptance_criteria'],
                        'priority' => $storyData['priority'],
                        'status' => $storyData['status'],
                        'created_by' => $creator->id,
                    ]
                );

                $storyCount++;
            }
        }

        $this->command?->info(sprintf(
            '%d projets de demo crees ou mis a jour avec %d user stories au total.',
            $projectCount,
            $storyCount
        ));
    }
}
