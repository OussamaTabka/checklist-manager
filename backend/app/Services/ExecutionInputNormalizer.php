<?php

namespace App\Services;

class ExecutionInputNormalizer
{
    /**
     * @param  array<string, mixed>  $providedInputs
     * @return array<string, mixed>
     */
    public function normalizeProvidedInputs(array $providedInputs): array
    {
        $normalized = $providedInputs;
        $identityAliases = ['username', 'email', 'user', 'login', 'identifier'];

        $identityValue = null;
        foreach ($identityAliases as $alias) {
            if (array_key_exists($alias, $normalized) && is_string($normalized[$alias])) {
                $identityValue = $normalized[$alias];
                break;
            }
        }

        if ($identityValue !== null) {
            foreach ($identityAliases as $alias) {
                $normalized[$alias] = $identityValue;
            }
        }

        return $normalized;
    }
}
