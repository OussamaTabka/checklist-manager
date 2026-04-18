<?php

namespace App\Services;

use App\Models\UserStory;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Exception;

class ArxisService
{
    private string $apiBaseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->apiBaseUrl = config('services.arxis.base_url', 'https://api.arxis.io/v1');
        $this->apiKey = config('services.arxis.api_key');

        if (!$this->apiKey) {
            throw new Exception('Arxis API key is not configured. Please set ARXIS_API_KEY in your environment.');
        }
    }

    /**
     * Generate a checklist from a user story using Arxis API
     *
     * @param UserStory $userStory
     * @return Checklist
     * @throws Exception
     */
    public function generateChecklistFromUserStory(UserStory $userStory): Checklist
    {
        // Call Arxis API to generate test cases
        $arxisResponse = $this->callArxisAPI($userStory);

        if (!$arxisResponse || empty($arxisResponse['test_cases'])) {
            throw new Exception('No test cases generated from Arxis');
        }

        // Create a new checklist with the generated items
        $checklist = Checklist::create([
            'name' => "Auto-generated: {$userStory->title}",
            'description' => "Generated from user story: {$userStory->title}\n\nAcceptance Criteria:\n{$userStory->acceptance_criteria}",
            'category' => 'auto-generated',
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        // Add items to the checklist
        foreach ($arxisResponse['test_cases'] as $index => $testCase) {
            ChecklistItem::create([
                'checklist_id' => $checklist->id,
                'name' => $testCase['name'] ?? 'Test Case ' . ($index + 1),
                'description' => $testCase['description'] ?? '',
                'expected_result' => $testCase['expected_result'] ?? '',
                'criticality' => $this->mapArxisCriticality($testCase['severity'] ?? 'medium'),
                'order' => $index + 1,
            ]);
        }

        return $checklist;
    }

    /**
     * Call Arxis API to generate test cases
     *
     * @param UserStory $userStory
     * @return array|null
     * @throws Exception
     */
    private function callArxisAPI(UserStory $userStory): ?array
    {
        try {
            $payload = [
                'title' => $userStory->title,
                'description' => $userStory->description,
                'acceptance_criteria' => $userStory->acceptance_criteria,
                'project_name' => $userStory->project->name,
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$this->apiBaseUrl}/generate/test-cases", $payload);

            if (!$response->successful()) {
                throw new Exception("Arxis API error: {$response->status()} - {$response->body()}");
            }

            return $response->json();
        } catch (Exception $e) {
            throw new Exception("Failed to call Arxis API: {$e->getMessage()}");
        }
    }

    /**
     * Map Arxis severity to criticality
     *
     * @param string $severity
     * @return string
     */
    private function mapArxisCriticality(string $severity): string
    {
        $mapping = [
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
        ];

        return $mapping[strtolower($severity)] ?? 'Medium';
    }

    /**
     * Validate Arxis API connection
     *
     * @return bool
     */
    public function validateConnection(): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->get("{$this->apiBaseUrl}/health");

            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }
}
