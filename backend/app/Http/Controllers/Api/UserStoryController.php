<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Project;
use App\Models\UserStory;
use App\Services\ChecklistGenerationAgentService;
use App\Services\ChecklistGenerationTextService;
use App\Services\ChecklistRecommendationService;
use App\Services\NotificationService;
use App\Services\TestCaseGenerationService;
use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class UserStoryController extends Controller
{
    private const DUPLICATE_STORY_REFERENCE_MESSAGE = 'Cette référence existe déjà dans ce projet.';

    public function __construct(
        private TestCaseGenerationService $testCaseGenerator,
        private ChecklistRecommendationService $checklistRecommendations,
        private ChecklistGenerationAgentService $checklistGenerator,
        private ChecklistGenerationTextService $generationText,
        private NotificationService $notificationService,
    ) {
    }

    public function index(Project $project)
    {
        $this->authorize('view', $project);

        $userStories = $project->userStories()
            ->with(['creator', 'checklists'])
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($userStories);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'as_a' => 'nullable|string',
            'i_want_that' => 'nullable|string',
            'so_that' => 'nullable|string',
            'acceptance_criteria' => 'nullable|string',
            'business_rules' => 'nullable|array',
            'business_rules.*' => 'string',
            'scenarios' => 'nullable|array',
            'scenarios.*' => 'string',
            'effort_points' => 'nullable|integer|min:0',
            'business_value' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date',
            'target_completion_date' => 'nullable|date',
            'status' => 'nullable|in:backlog,in_progress,ready_for_test,completed',
            'priority' => 'nullable|in:low,medium,high,critical',
            'story_id' => 'nullable|string',
        ]);

        $this->ensureUniqueStoryReference($project, $validated['story_id'] ?? null);

        $validated['project_id'] = $project->id;
        $validated['created_by'] = Auth::id();

        $userStory = UserStory::create($validated);
        $this->notificationService->notifyUserStoryChanged(
            'user_story_created',
            $project,
            $userStory,
            $request->user()
        );

        return response()->json($userStory->load(['creator', 'checklists']), 201);
    }

    public function show(Project $project, UserStory $userStory)
    {
        $this->authorize('view', $project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        return response()->json($this->buildUserStoryPayload($userStory));
    }

    public function update(Request $request, Project $project, UserStory $userStory)
    {
        $this->authorize('update', $project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'as_a' => 'nullable|string',
            'i_want_that' => 'nullable|string',
            'so_that' => 'nullable|string',
            'acceptance_criteria' => 'nullable|string',
            'business_rules' => 'nullable|array',
            'business_rules.*' => 'string',
            'scenarios' => 'nullable|array',
            'scenarios.*' => 'string',
            'effort_points' => 'nullable|integer|min:0',
            'business_value' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date',
            'target_completion_date' => 'nullable|date',
            'status' => 'nullable|in:backlog,in_progress,ready_for_test,completed',
            'priority' => 'nullable|in:low,medium,high,critical',
            'story_id' => 'nullable|string',
        ]);

        $this->ensureUniqueStoryReference($project, $validated['story_id'] ?? null, $userStory);

        $userStory->update($validated);
        $this->notificationService->notifyUserStoryChanged(
            'user_story_updated',
            $project,
            $userStory->fresh(),
            $request->user()
        );

        return response()->json($userStory->load(['creator', 'checklists']));
    }

    public function destroy(Project $project, UserStory $userStory)
    {
        $this->authorize('delete', $project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        $storySnapshot = $userStory->replicate();
        $storySnapshot->id = $userStory->id;
        $userStory->delete();
        $this->notificationService->notifyUserStoryChanged(
            'user_story_deleted',
            $project,
            $storySnapshot,
            Auth::user(),
            ['story_title' => $userStory->title]
        );

        return response()->json(null, 204);
    }

    public function generateChecklistFromArxis(Request $request, Project $project, UserStory $userStory)
    {
        return $this->generateChecklist($request, $project, $userStory);
    }

    public function generateChecklistWithAgent(Request $request, Project $project, UserStory $userStory)
    {
        return $this->generateChecklist($request, $project, $userStory);
    }

    public function generateChecklist(Request $request, Project $project, UserStory $userStory)
    {
        $this->authorize('view', $project);
        $this->ensureExecutionAccess($project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        try {
            $result = $this->checklistGenerator->generateDraftForUserStory(
                $userStory,
                $this->generationText->normalizeLanguage($request->header('X-App-Language'))
            );

            return response()->json([
                ...$result,
                'user_story' => $this->buildUserStoryPayload($userStory->fresh()),
            ], 201);
        } catch (Throwable $e) {
            $status = $this->resolveChecklistGenerationStatus($e);

            return response()->json([
                'error' => $status === 500
                    ? 'Failed to generate checklist'
                    : 'Checklist generation is currently unavailable for this story',
                'message' => $e->getMessage(),
                'available_generators' => $this->testCaseGenerator->getAvailableGenerators(),
            ], $status);
        }
    }

    public function attachChecklist(Request $request, Project $project, UserStory $userStory)
    {
        $this->authorize('view', $project);
        $this->ensureExecutionAccess($project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        $validated = $request->validate([
            'checklist_id' => 'required|exists:checklists,id',
            'reviewed' => 'required|accepted',
        ]);

        if ($userStory->checklists()->where('checklist_id', $validated['checklist_id'])->exists()) {
            return response()->json(['error' => 'Checklist is already attached to this user story'], 409);
        }

        $recommendations = $this->checklistRecommendations->suggestForUserStory($userStory);
        $selectedSuggestion = collect($recommendations['suggestions'] ?? [])
            ->firstWhere('id', (int) $validated['checklist_id']);

        $userStory->checklists()->attach($validated['checklist_id'], [
            'is_generated_from_arxis' => false,
            'relevance_score' => $selectedSuggestion['score'] ?? null,
            'link_type' => 'attached_after_review',
        ]);

        return response()->json($this->buildUserStoryPayload($userStory->fresh()), 201);
    }

    public function approveGeneratedChecklist(Project $project, UserStory $userStory, Checklist $checklist)
    {
        $this->authorize('view', $project);
        $this->ensureExecutionAccess($project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        if ((int) $checklist->source_user_story_id !== (int) $userStory->id) {
            return response()->json(['error' => 'Checklist draft does not belong to this user story'], 422);
        }

        $checklist->update([
            'lifecycle_status' => 'approved',
        ]);

        if (!$userStory->checklists()->where('checklist_id', $checklist->id)->exists()) {
            $userStory->checklists()->attach($checklist->id, [
                'is_generated_from_arxis' => true,
                'relevance_score' => null,
                'link_type' => 'generator_validated_by_chef',
            ]);
        }

        return response()->json([
            'message' => 'Checklist draft approved and attached to the user story.',
            'user_story' => $this->buildUserStoryPayload($userStory->fresh()),
            'approved_checklist' => $checklist->fresh()->load('items'),
        ], 201);
    }

    public function rejectGeneratedChecklist(Project $project, UserStory $userStory, Checklist $checklist)
    {
        $this->authorize('view', $project);
        $this->ensureExecutionAccess($project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        if ((int) $checklist->source_user_story_id !== (int) $userStory->id) {
            return response()->json(['error' => 'Checklist draft does not belong to this user story'], 422);
        }

        $checklist->update([
            'lifecycle_status' => 'archived',
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Checklist draft rejected.',
            'user_story' => $this->buildUserStoryPayload($userStory->fresh()),
            'rejected_checklist_id' => $checklist->id,
        ]);
    }

    public function detachChecklist(Project $project, UserStory $userStory, $checklistId)
    {
        $this->authorize('view', $project);
        $this->ensureExecutionAccess($project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        $userStory->checklists()->detach($checklistId);

        return response()->json($this->buildUserStoryPayload($userStory->fresh()));
    }

    public function getGeneratorsStatus(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json([
            'available_generators' => $this->testCaseGenerator->getAvailableGenerators(),
            'configured_provider' => config('services.test_generation.provider', 'local-llm'),
            'configured_model' => config('services.test_generation.llm_model', 'mistral'),
        ]);
    }

    private function buildUserStoryPayload(UserStory $userStory): UserStory
    {
        $userStory->load(['creator', 'checklists.items']);

        $pendingDrafts = Checklist::query()
            ->with('items')
            ->where('source_user_story_id', $userStory->id)
            ->where('lifecycle_status', 'draft')
            ->whereIn('generated_from', ['ai', 'reuse'])
            ->orderByDesc('created_at')
            ->get();

        $userStory->setAttribute('pending_drafts', $pendingDrafts);

        return $userStory;
    }

    private function ensureExecutionAccess(Project $project): void
    {
        $user = Auth::user();

        abort_unless(
            $user && $user->hasRole('testeur') && $project->testers()->where('users.id', $user->id)->exists(),
            403,
            'Only assigned testers can generate or attach execution checklists.'
        );
    }

    private function resolveChecklistGenerationStatus(Throwable $exception): int
    {
        $message = strtolower($exception->getMessage());

        $expectedGenerationFailures = [
            'generator could not build items',
            'générateur de checklists n’a pas pu construire',
            'générateur de checklists n\'a pas pu construire',
            'no test case generator available',
            'api key is missing',
            'no test cases generated',
            'failed to generate test cases with openai/codex',
        ];

        foreach ($expectedGenerationFailures as $fragment) {
            if (str_contains($message, $fragment)) {
                return 422;
            }
        }

        return 500;
    }

    private function ensureUniqueStoryReference(Project $project, ?string $reference, ?UserStory $ignoredStory = null): void
    {
        $normalizedReference = $this->normalizeStoryReference($reference);

        if ($normalizedReference === null) {
            return;
        }

        $duplicateExists = UserStory::query()
            ->where('project_id', $project->id)
            ->when(
                $ignoredStory,
                fn ($query) => $query->where('id', '!=', $ignoredStory->id)
            )
            ->whereNotNull('story_id')
            ->get(['id', 'story_id'])
            ->contains(fn (UserStory $story) => $this->normalizeStoryReference($story->story_id) === $normalizedReference);

        if ($duplicateExists) {
            $exception = ValidationException::withMessages([
                'story_id' => [self::DUPLICATE_STORY_REFERENCE_MESSAGE],
            ]);

            $exception->response = response()->json([
                'message' => self::DUPLICATE_STORY_REFERENCE_MESSAGE,
                'errors' => [
                    'story_id' => [self::DUPLICATE_STORY_REFERENCE_MESSAGE],
                ],
            ], 422);

            throw $exception;
        }
    }

    private function normalizeStoryReference(?string $reference): ?string
    {
        $trimmedReference = trim((string) $reference);

        if ($trimmedReference === '') {
            return null;
        }

        return mb_strtolower($trimmedReference);
    }
}
