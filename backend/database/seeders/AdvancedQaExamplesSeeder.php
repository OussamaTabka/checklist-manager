<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\User;
use App\Models\UserStory;
use Illuminate\Database\Seeder;

class AdvancedQaExamplesSeeder extends Seeder
{
    public function run(): void
    {
        $chef = User::where('email', 'chef@test.com')->first() ?? User::first();
        $tester = User::where('email', 'testeur@test.com')->first();

        if (!$chef) {
            $this->command?->error('No users available for AdvancedQaExamplesSeeder.');
            return;
        }

        $project = Project::updateOrCreate(
            ['name' => 'Advanced QA Checklist Manager'],
            [
                'description' => 'Demo project aligned with advanced QA examples using standardized user stories, checklists, and test cases.',
                'app_url' => 'http://localhost:5173',
                'created_by' => $chef->id,
            ]
        );

        if ($tester) {
            $project->testers()->syncWithoutDetaching([$tester->id]);
        }

        $criteria = [
            'Fonctionnement correct avec donnees valides',
            'Gestion des erreurs',
            'Respect UI/UX',
            'Securite respectee',
        ];

        $stories = [
            'US-001' => 'Authentification utilisateur',
            'US-002' => 'Inscription utilisateur',
            'US-003' => 'Reinitialisation mot de passe',
            'US-004' => 'Gestion profil',
            'US-005' => 'Creation projet',
            'US-006' => 'Modification projet',
            'US-007' => 'Suppression projet',
            'US-008' => 'Ajout checklist',
            'US-009' => 'Modification checklist',
            'US-010' => 'Suppression checklist',
            'US-011' => 'Assignation testeurs',
            'US-012' => 'Execution tests',
            'US-013' => 'Ajout commentaires',
            'US-014' => 'Upload fichiers',
            'US-015' => 'Dashboard KPI',
            'US-016' => 'Recherche',
            'US-017' => 'Filtrage',
            'US-018' => 'Notifications',
            'US-019' => 'Gestion roles',
            'US-020' => 'Historique actions',
        ];

        foreach ($stories as $storyId => $title) {
            $userStory = UserStory::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'story_id' => $storyId,
                ],
                [
                    'title' => $title,
                    'description' => "En tant qu'utilisateur, je veux {$this->lowercase($title)} afin d'assurer le bon fonctionnement du systeme.",
                    'as_a' => 'utilisateur',
                    'i_want_that' => $this->lowercase($title),
                    'so_that' => 'assurer le bon fonctionnement du systeme',
                    'acceptance_criteria' => collect($criteria)->map(fn (string $item) => "- {$item}")->implode("\n"),
                    'business_rules' => $criteria,
                    'scenarios' => ['Cas nominal', 'Cas erreur'],
                    'effort_points' => 3,
                    'business_value' => 8,
                    'status' => 'backlog',
                    'priority' => 'critical',
                    'created_by' => $chef->id,
                ]
            );

            $checklists = [
                [
                    'name' => "{$storyId} | CL-01 | Test principal",
                    'description' => 'Verifier fonctionnement',
                    'category' => 'Fonctionnel',
                    'creator_id' => $chef->id,
                    'relevance_score' => 100,
                ],
                [
                    'name' => "{$storyId} | CL-02 | Validation",
                    'description' => 'Tester erreurs',
                    'category' => 'Validation',
                    'creator_id' => $chef->id,
                    'relevance_score' => 96,
                ],
            ];

            foreach ($checklists as $checklistData) {
                $checklist = Checklist::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'name' => $checklistData['name'],
                    ],
                    [
                        'description' => $checklistData['description'],
                        'category' => $checklistData['category'],
                        'is_active' => true,
                        'created_by' => $checklistData['creator_id'],
                        'project_id' => $project->id,
                        'as_a' => 'utilisateur',
                        'i_want_that' => $this->lowercase($title),
                        'so_that' => 'assurer le bon fonctionnement du systeme',
                        'acceptance_criteria' => collect($criteria)->map(fn (string $item) => "- {$item}")->implode("\n"),
                        'business_rules' => $criteria,
                        'priority' => 'critical',
                        'status' => 'backlog',
                        'template_scope' => 'project',
                        'lifecycle_status' => 'approved',
                        'generated_from' => 'manual',
                        'source_user_story_id' => $userStory->id,
                    ]
                );

                $checklist->items()->delete();

                $items = [
                    [
                        'title' => 'TC-01 | Cas nominal',
                        'description' => 'Verifier fonctionnement correct avec des donnees valides.',
                        'priority' => 'High',
                        'criticality' => 'Critical',
                        'status' => 'pending',
                    ],
                    [
                        'title' => 'TC-02 | Cas erreur',
                        'description' => 'Verifier la gestion des erreurs et la robustesse du parcours.',
                        'priority' => 'High',
                        'criticality' => 'Major',
                        'status' => 'pending',
                    ],
                ];

                foreach ($items as $index => $item) {
                    ChecklistItem::create([
                        'checklist_id' => $checklist->id,
                        'title' => $item['title'],
                        'description' => $item['description'],
                        'priority' => $item['priority'],
                        'criticality' => $item['criticality'],
                        'status' => $item['status'],
                        'order' => $index + 1,
                    ]);
                }

                $project->checklists()->syncWithoutDetaching([$checklist->id]);

                $userStory->checklists()->syncWithoutDetaching([
                    $checklist->id => [
                        'is_generated_from_arxis' => false,
                        'relevance_score' => $checklistData['relevance_score'],
                        'link_type' => 'seeded_reference',
                    ],
                ]);
            }
        }

        $this->command?->info('Advanced QA examples seeded: 20 user stories, 40 checklists, 80 test items.');
    }

    private function lowercase(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }
}
