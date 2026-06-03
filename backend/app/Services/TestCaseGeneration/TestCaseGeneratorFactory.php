<?php

namespace App\Services\TestCaseGeneration;

class TestCaseGeneratorFactory
{
    public static function make(): TestCaseGeneratorInterface
    {
        return new OpenAIGenerator();
    }

    public static function orderedAvailableGenerators(): array
    {
        return [new OpenAIGenerator()];
    }

    public static function getAvailable(): array
    {
        $generator = new OpenAIGenerator();

        return [
            'openai' => [
                'name' => $generator->getName(),
                'available' => $generator->isAvailable(),
            ],
        ];
    }
}
