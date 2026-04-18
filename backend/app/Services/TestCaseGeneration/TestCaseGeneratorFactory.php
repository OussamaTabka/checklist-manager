<?php

namespace App\Services\TestCaseGeneration;

use App\Models\UserStory;

/**
 * Factory for test case generators
 * Provides abstraction over multiple test generation backends
 */
class TestCaseGeneratorFactory
{
    /**
     * Available generators
     */
    private const GENERATORS = [
        'evomaster' => EvoMasterGenerator::class,
        'local-llm' => LocalLLMGenerator::class,
        'fallback' => FallbackGenerator::class,
    ];

    /**
     * Get the appropriate test case generator
     * Falls back to next available if primary is unavailable
     *
     * @return TestCaseGeneratorInterface
     * @throws \Exception
     */
    public static function make(): TestCaseGeneratorInterface
    {
        $preferred = config('services.test_generation.provider', 'local-llm');
        $order = array_merge([$preferred], array_keys(self::GENERATORS));
        $order = array_unique($order);

        foreach ($order as $generatorName) {
            if (!isset(self::GENERATORS[$generatorName])) {
                continue;
            }

            $generator = new self::GENERATORS[$generatorName]();

            if ($generator->isAvailable()) {
                return $generator;
            }
        }

        // Fallback to basic generator if nothing works
        return new FallbackGenerator();
    }

    /**
     * Get all available generators with their status
     *
     * @return array
     */
    public static function getAvailable(): array
    {
        $available = [];

        foreach (self::GENERATORS as $name => $class) {
            $generator = new $class();
            $available[$name] = [
                'name' => $generator->getName(),
                'available' => $generator->isAvailable(),
            ];
        }

        return $available;
    }
}
