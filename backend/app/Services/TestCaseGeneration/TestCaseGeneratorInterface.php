<?php

namespace App\Services\TestCaseGeneration;

use App\Models\UserStory;
use App\Models\Checklist;

/**
 * Interface for test case generation services
 * Allows multiple backends (EvoMaster, Local LLM, etc.)
 */
interface TestCaseGeneratorInterface
{
    /**
     * Generate test cases for a user story
     *
     * @param UserStory $userStory
     * @param array $context Additional generation context such as coverage gaps or reusable assets
     * @return array Array of test cases with keys: name, description, expected_result, severity
     */
    public function generateTestCases(UserStory $userStory, array $context = []): array;

    /**
     * Check if the service is available/configured
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Get the service name
     *
     * @return string
     */
    public function getName(): string;
}
