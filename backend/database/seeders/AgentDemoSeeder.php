<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\User;
use App\Models\UserStory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AgentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $chef = $this->user('chef@test.com', 'Chef de Projet', 'chef');
        $tester = $this->user('testeur@test.com', 'Testeur', 'testeur');
        $templates = $this->seedChecklistTemplates($chef, $tester);
        $projects = $this->seedProjects($chef);

        foreach ($projects as $project) {
            $project->testers()->syncWithoutDetaching([$tester->id]);
        }

        $projects['commerce']->checklists()->syncWithoutDetaching([
            $templates['auth']->id,
            $templates['checkout']->id,
            $templates['ui']->id,
        ]);

        $projects['banking']->checklists()->syncWithoutDetaching([
            $templates['api']->id,
            $templates['auth']->id,
        ]);

        $projects['qa']->checklists()->syncWithoutDetaching([
            $templates['checklist-management']->id,
            $templates['ui']->id,
        ]);

        $this->seedUserStories($projects, $chef, $templates);

        $this->command?->info('Agent demo data seeded: 3 projects, approved reusable checklist templates, and user stories ready for agent tests.');
        $this->command?->info('Try: E-Commerce Checkout > "Guest checkout applies promo code and completes payment" for strong checklist reuse.');
        $this->command?->info('Login users: chef@test.com / testeur@test.com with password password123.');
    }

    private function user(string $email, string $name, string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password123'),
            ]
        );

        if (method_exists($user, 'syncRoles')) {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    private function seedChecklistTemplates(User $chef, User $tester): array
    {
        $templates = [
            'auth' => [
                'creator' => $chef,
                'name' => 'Reusable Login and Authentication Checklist',
                'description' => 'Approved template for login, logout, session, password, and authorization testing.',
                'category' => 'Authentication',
                'items' => [
                    ['title' => 'Valid login redirects to the correct dashboard', 'description' => 'Verify successful authentication with valid email and password, including the correct landing page.', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'Invalid login shows a clear error message', 'description' => 'Verify invalid credentials do not authenticate and produce a safe, understandable message.', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'Required login fields are validated', 'description' => 'Verify empty email and password fields show validation without submitting the form.', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'Session expires after inactivity', 'description' => 'Verify inactive users are logged out and redirected to login.', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'Role based access is enforced after login', 'description' => 'Verify users cannot access screens outside their assigned role.', 'priority' => 'High', 'criticality' => 'Critical'],
                ],
            ],
            'checkout' => [
                'creator' => $tester,
                'name' => 'Reusable E-Commerce Checkout Checklist',
                'description' => 'Approved regression checklist for cart, promo code, payment, order confirmation, and inventory checks.',
                'category' => 'E-Commerce',
                'items' => [
                    ['title' => 'Cart total updates after quantity changes', 'description' => 'Verify subtotal, tax, shipping, and total are recalculated when item quantities change.', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Promo code applies the expected discount', 'description' => 'Verify valid promotion codes update the cart total and invalid codes show a clear error.', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Guest checkout captures required customer details', 'description' => 'Verify guest name, email, billing, and shipping fields are required and persisted.', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'Payment failure keeps order unconfirmed', 'description' => 'Verify failed payment does not create a confirmed order and keeps the cart recoverable.', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'Successful payment creates order confirmation', 'description' => 'Verify order number, receipt, customer email, and inventory updates after payment success.', 'priority' => 'High', 'criticality' => 'Critical'],
                ],
            ],
            'api' => [
                'creator' => $chef,
                'name' => 'Reusable API Contract and Security Checklist',
                'description' => 'Approved API testing checklist for request validation, authentication, response schema, and error handling.',
                'category' => 'API',
                'items' => [
                    ['title' => 'Bearer token is required for protected endpoints', 'description' => 'Verify protected routes reject missing, expired, or malformed tokens.', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'Request body schema is validated', 'description' => 'Verify invalid or missing JSON fields return deterministic validation errors.', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Successful response follows documented schema', 'description' => 'Verify status code, JSON keys, data types, pagination, and metadata.', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Rate limiting protects write endpoints', 'description' => 'Verify repeated requests return rate limit responses without data corruption.', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'API error responses do not expose internals', 'description' => 'Verify stack traces, SQL details, and secrets are not returned to clients.', 'priority' => 'High', 'criticality' => 'Critical'],
                ],
            ],
            'ui' => [
                'creator' => $tester,
                'name' => 'Reusable Web UI and Accessibility Checklist',
                'description' => 'Approved checklist for forms, navigation, responsive layout, browser coverage, and accessibility.',
                'category' => 'UI/UX',
                'items' => [
                    ['title' => 'Form controls have labels and visible focus states', 'description' => 'Verify keyboard focus, labels, placeholders, and error states are accessible.', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'Responsive layout works on mobile, tablet, and desktop', 'description' => 'Verify no clipped text, overlap, or horizontal scroll in common viewports.', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Navigation preserves user context', 'description' => 'Verify links, back buttons, filters, and query parameters keep the current project context.', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'Empty and loading states are understandable', 'description' => 'Verify loading, empty, and error states guide the user to the next action.', 'priority' => 'Medium', 'criticality' => 'Minor'],
                ],
            ],
            'checklist-management' => [
                'creator' => $chef,
                'name' => 'Reusable Checklist Management Checklist',
                'description' => 'Approved workflow checklist for creating, editing, approving, archiving, and reusing test checklists.',
                'category' => 'Test Management',
                'items' => [
                    ['title' => 'Checklist can be created with required metadata', 'description' => 'Verify name, description, category, status, and items are saved correctly.', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Checklist items can be reordered and edited', 'description' => 'Verify title, description, priority, criticality, and order changes persist.', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'Draft checklist is not treated as approved template', 'description' => 'Verify draft lifecycle status keeps generated content out of approved recommendations.', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'Approved checklist appears in reuse suggestions', 'description' => 'Verify approved active checklists can be suggested for relevant user stories.', 'priority' => 'High', 'criticality' => 'Critical'],
                ],
            ],
        ];

        $seeded = [];

        foreach ($templates as $key => $template) {
            $checklist = Checklist::updateOrCreate(
                ['name' => $template['name']],
                [
                    'description' => $template['description'],
                    'category' => $template['category'],
                    'is_active' => true,
                    'created_by' => $template['creator']->id,
                    'template_scope' => 'global',
                    'lifecycle_status' => 'approved',
                    'generated_from' => 'manual',
                    'source_user_story_id' => null,
                ]
            );

            $checklist->items()->delete();

            foreach ($template['items'] as $index => $item) {
                ChecklistItem::create([
                    'checklist_id' => $checklist->id,
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'priority' => $item['priority'],
                    'criticality' => $item['criticality'],
                    'order' => $index + 1,
                ]);
            }

            $seeded[$key] = $checklist;
        }

        return $seeded;
    }

    private function seedProjects(User $chef): array
    {
        $projects = [
            'commerce' => [
                'name' => 'Agent Demo - E-Commerce Checkout',
                'description' => 'Demo project for checkout, login, cart, payment, promo code, and responsive UI testing.',
                'app_url' => 'http://localhost:5173/shop',
            ],
            'banking' => [
                'name' => 'Agent Demo - Banking API',
                'description' => 'Demo project for API authentication, transfers, validation, audit logs, and error contracts.',
                'app_url' => 'http://localhost:8000/api/docs',
            ],
            'qa' => [
                'name' => 'Agent Demo - QA Platform',
                'description' => 'Demo project for checklist governance, user story workflow, agent generation, and test execution.',
                'app_url' => 'http://localhost:5173',
            ],
        ];

        return collect($projects)
            ->mapWithKeys(fn (array $project, string $key) => [
                $key => Project::updateOrCreate(
                    ['name' => $project['name']],
                    [
                        'description' => $project['description'],
                        'app_url' => $project['app_url'],
                        'created_by' => $chef->id,
                    ]
                ),
            ])
            ->all();
    }

    private function seedUserStories(array $projects, User $chef, array $templates): void
    {
        $stories = [
            [
                'project' => 'commerce',
                'story_id' => 'US-CHECKOUT-001',
                'title' => 'Guest checkout applies promo code and completes payment',
                'description' => 'As a guest customer, I want to apply a promo code during checkout and pay successfully so that I can complete an order without creating an account.',
                'acceptance_criteria' => "Given a guest customer has products in the cart, When they apply a valid promo code, Then the cart total shows the expected discount\nGiven the guest customer enters billing and shipping details, When they continue to payment, Then required customer details are validated\nGiven payment is accepted, When the order is submitted, Then an order confirmation number and email receipt are created",
                'status' => 'ready_for_test',
                'priority' => 'critical',
                'checklists' => ['checkout'],
                'score' => 92,
            ],
            [
                'project' => 'commerce',
                'story_id' => 'US-AUTH-002',
                'title' => 'Customer login blocks invalid credentials and preserves session',
                'description' => 'As a returning customer, I want secure login validation and session handling so that my account stays protected.',
                'acceptance_criteria' => "Given valid email and password, When the customer submits the login form, Then the account dashboard is displayed\nGiven invalid credentials, When the customer submits the login form, Then an error message is shown and no session is created\nGiven a logged in customer is inactive, When the session expires, Then the customer is redirected to login",
                'status' => 'ready_for_test',
                'priority' => 'high',
                'checklists' => ['auth'],
                'score' => 88,
            ],
            [
                'project' => 'banking',
                'story_id' => 'US-API-TRANSFER-001',
                'title' => 'Authenticated API creates a money transfer with validation errors',
                'description' => 'As a banking API client, I want transfer creation to validate token, payload, balance, and response schema so integrations are reliable.',
                'acceptance_criteria' => "Given a valid bearer token and transfer payload, When the API creates a transfer, Then the response follows the documented schema\nGiven the bearer token is missing, When the client calls the transfer endpoint, Then the API returns an unauthorized error\nGiven the transfer amount exceeds balance, When the request is submitted, Then the API returns a validation error without creating a transfer",
                'status' => 'ready_for_test',
                'priority' => 'critical',
                'checklists' => ['api'],
                'score' => 90,
            ],
            [
                'project' => 'qa',
                'story_id' => 'US-QA-AGENT-001',
                'title' => 'Agent generates a draft checklist from reusable templates',
                'description' => 'As a chef de projet, I want the checklist agent to reuse approved checklist items before generating missing tests so our checklist library stays centralized.',
                'acceptance_criteria' => "Given approved checklist templates exist, When the chef runs the generation agent for a matching user story, Then reusable checklist items are copied into a draft\nGiven the story has acceptance criteria not covered by templates, When generation completes, Then missing test cases are generated and added to the draft\nGiven the draft is created, When the chef reviews it, Then it remains draft until approved",
                'status' => 'in_progress',
                'priority' => 'high',
                'checklists' => ['checklist-management'],
                'score' => 85,
            ],
            [
                'project' => 'qa',
                'story_id' => 'US-QA-UI-002',
                'title' => 'User story form preserves project context and test readiness',
                'description' => 'As a chef de projet, I want the user story form to keep the active project linked and guide me toward testable criteria.',
                'acceptance_criteria' => "Given a project is selected, When the chef creates a user story, Then the story is attached to that project\nGiven the chef fills Given-When-Then criteria, When the story is saved, Then the detail page opens with the same project context\nGiven the form is viewed on mobile, When fields are edited, Then controls remain readable and usable",
                'status' => 'ready_for_test',
                'priority' => 'medium',
                'checklists' => ['ui'],
                'score' => 78,
            ],
            [
                'project' => 'commerce',
                'story_id' => 'US-WISHLIST-NEW',
                'title' => 'Customer saves favorite products to wishlist',
                'description' => 'As a customer, I want to save products to a wishlist so that I can return later and buy them.',
                'acceptance_criteria' => "Given a logged in customer views a product, When they click add to wishlist, Then the product is saved to their wishlist\nGiven a product is already in the wishlist, When the customer clicks add again, Then the system prevents a duplicate\nGiven the customer opens the wishlist, When saved products are displayed, Then product name, price, stock state, and remove action are visible",
                'status' => 'backlog',
                'priority' => 'medium',
                'checklists' => [],
                'score' => null,
            ],
        ];

        foreach ($stories as $storyData) {
            $project = $projects[$storyData['project']];

            $story = UserStory::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'story_id' => $storyData['story_id'],
                ],
                [
                    'title' => $storyData['title'],
                    'description' => $storyData['description'],
                    'acceptance_criteria' => $storyData['acceptance_criteria'],
                    'status' => $storyData['status'],
                    'priority' => $storyData['priority'],
                    'created_by' => $chef->id,
                ]
            );

            foreach ($storyData['checklists'] as $templateKey) {
                $story->checklists()->syncWithoutDetaching([
                    $templates[$templateKey]->id => [
                        'is_generated_from_arxis' => false,
                        'relevance_score' => $storyData['score'],
                        'link_type' => 'seeded_reference',
                    ],
                ]);
            }
        }
    }
}
