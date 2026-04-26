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
        foreach (self::orderedGeneratorNames() as $generatorName) {
            $generator = self::makeByName($generatorName);

            if ($generator && $generator->isAvailable()) {
                return $generator;
            }
        }

        return new FallbackGenerator();
    }

    /**
     * Get generators in configured preference order.
     *
     * @return TestCaseGeneratorInterface[]
     */
    public static function orderedAvailableGenerators(): array
    {
        $generators = [];

        foreach (self::orderedGeneratorNames() as $generatorName) {
            $generator = self::makeByName($generatorName);

            if (!$generator) {
                continue;
            }

            if ($generator instanceof FallbackGenerator || $generator->isAvailable()) {
                $generators[] = $generator;
            }
        }

        return $generators ?: [new FallbackGenerator()];
    }

    private static function orderedGeneratorNames(): array
    {
        $preferred = config('services.test_generation.provider', 'local-llm');
        return array_values(array_unique(array_merge([$preferred], array_keys(self::GENERATORS), ['fallback'])));
    }

    private static function makeByName(string $generatorName): ?TestCaseGeneratorInterface
    {
        if (!isset(self::GENERATORS[$generatorName])) {
            return null;
        }

        $generatorClass = self::GENERATORS[$generatorName];
        return new $generatorClass();
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
