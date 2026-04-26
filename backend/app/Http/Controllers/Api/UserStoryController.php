<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserStory;
use App\Models\Project;
use App\Services\ChecklistGenerationAgentService;
use App\Services\ChecklistRecommendationService;
use App\Services\TestCaseGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserStoryController extends Controller
{
    private TestCaseGenerationService $testCaseGenerator;
    private ChecklistRecommendationService $checklistRecommendations;
    private ChecklistGenerationAgentService $checklistGenerationAgent;

    public function __construct(
        TestCaseGenerationService $testCaseGenerator,
        ChecklistRecommendationService $checklistRecommendations,
        ChecklistGenerationAgentService $checklistGenerationAgent
    )
    {
        $this->testCaseGenerator = $testCaseGenerator;
        $this->checklistRecommendations = $checklistRecommendations;
        $this->checklistGenerationAgent = $checklistGenerationAgent;
    }

    /**
     * Get all user stories for a project
     */
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

    /**
     * Create a new user story
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'acceptance_criteria' => 'nullable|string',
            'status' => 'nullable|in:backlog,in_progress,ready_for_test,completed',
            'priority' => 'nullable|in:low,medium,high,critical',
            'story_id' => 'nullable|string',
        ]);

        $validated['project_id'] = $project->id;
        $validated['created_by'] = Auth::id();

        $userStory = UserStory::create($validated);

        return response()->json($userStory->load(['creator', 'checklists']), 201);
    }

    /**
     * Get a specific user story
     */
    public function show(Project $project, UserStory $userStory)
    {
        $this->authorize('view', $project);
        
        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        return response()->json($userStory->load(['creator', 'checklists.items']));
    }

    /**
     * Update a user story
     */
    public function update(Request $request, Project $project, UserStory $userStory)
    {
        $this->authorize('update', $project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'acceptance_criteria' => 'nullable|string',
            'status' => 'nullable|in:backlog,in_progress,ready_for_test,completed',
            'priority' => 'nullable|in:low,medium,high,critical',
            'story_id' => 'nullable|string',
        ]);

        $userStory->update($validated);

        return response()->json($userStory->load(['creator', 'checklists']));
    }

    /**
     * Delete a user story
     */
    public function destroy(Project $project, UserStory $userStory)
    {
        $this->authorize('delete', $project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        $userStory->delete();

        return response()->json(null, 204);
    }

    /**
     * Generate checklist from AI/LLM for a user story
     */
    public function generateChecklistFromArxis(Request $request, Project $project, UserStory $userStory)
    {
        return $this->generateChecklistWithAgent($request, $project, $userStory);
    }

    /**
     * Generate a reusable checklist draft using the checklist agent.
     */
    public function generateChecklistWithAgent(Request $request, Project $project, UserStory $userStory)
    {
        $this->authorize('view', $project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        try {
            return response()->json(
                $this->checklistGenerationAgent->generateDraftForUserStory($userStory),
                201
            );
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate checklist with agent',
                'message' => $e->getMessage(),
                'available_generators' => $this->testCaseGenerator->getAvailableGenerators(),
            ], 500);
        }
    }

    /**
     * Attach an existing checklist to a user story
     */
    public function attachChecklist(Request $request, Project $project, UserStory $userStory)
    {
        $this->authorize('view', $project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        $validated = $request->validate([
            'checklist_id' => 'required|exists:checklists,id',
        ]);

        // Check if already attached
        if ($userStory->checklists()->where('checklist_id', $validated['checklist_id'])->exists()) {
            return response()->json(['error' => 'Checklist is already attached to this user story'], 409);
        }

        $recommendations = $this->checklistRecommendations->suggestForUserStory($userStory);
        $selectedSuggestion = collect($recommendations['suggestions'] ?? [])
            ->firstWhere('id', (int) $validated['checklist_id']);

        $userStory->checklists()->attach($validated['checklist_id'], [
            'is_generated_from_arxis' => false,
            'relevance_score' => $selectedSuggestion['score'] ?? null,
            'link_type' => 'attached',
        ]);

        return response()->json($userStory->load(['creator', 'checklists']), 201);
    }

    /**
     * Detach a checklist from a user story
     */
    public function detachChecklist(Project $project, UserStory $userStory, $checklistId)
    {
        $this->authorize('update', $project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        $userStory->checklists()->detach($checklistId);

        return response()->json($userStory->load(['creator', 'checklists']));
    }

    /**
     * Get available test case generators and their status
     */
    public function getGeneratorsStatus(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json([
            'available_generators' => $this->testCaseGenerator->getAvailableGenerators(),
            'configured_provider' => config('services.test_generation.provider', 'local-llm'),
            'configured_model' => config('services.test_generation.llm_model', 'mistral'),
        ]);
    }

    /**
     * Suggest reusable checklists for the given user story before generating a new one.
     */
    public function suggestChecklists(Project $project, UserStory $userStory)
    {
        $this->authorize('view', $project);

        if ($userStory->project_id !== $project->id) {
            return response()->json(['error' => 'User story not found in this project'], 404);
        }

        return response()->json(
            $this->checklistRecommendations->suggestForUserStory($userStory)
        );
    }
}
