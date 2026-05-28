<?php

namespace Tests\Unit;

use App\Services\ExecutionInputNormalizer;
use Tests\TestCase;

class ExecutionInputNormalizerTest extends TestCase
{
    public function test_username_aliases_to_all_auth_identity_keys(): void
    {
        $normalized = app(ExecutionInputNormalizer::class)->normalizeProvidedInputs([
            'username' => 'standard_user',
            'password' => 'secret_sauce',
        ]);

        $this->assertSame('standard_user', $normalized['username']);
        $this->assertSame('standard_user', $normalized['email']);
        $this->assertSame('standard_user', $normalized['user']);
        $this->assertSame('standard_user', $normalized['login']);
        $this->assertSame('standard_user', $normalized['identifier']);
        $this->assertSame('secret_sauce', $normalized['password']);
    }

    public function test_email_aliases_to_all_auth_identity_keys(): void
    {
        $normalized = app(ExecutionInputNormalizer::class)->normalizeProvidedInputs([
            'email' => 'qa.user@example.com',
        ]);

        $this->assertSame('qa.user@example.com', $normalized['username']);
        $this->assertSame('qa.user@example.com', $normalized['email']);
        $this->assertSame('qa.user@example.com', $normalized['user']);
        $this->assertSame('qa.user@example.com', $normalized['login']);
        $this->assertSame('qa.user@example.com', $normalized['identifier']);
    }

    public function test_blank_username_is_preserved_across_auth_identity_aliases(): void
    {
        $normalized = app(ExecutionInputNormalizer::class)->normalizeProvidedInputs([
            'username' => '',
            'password' => 'secret_sauce',
        ]);

        $this->assertSame('', $normalized['username']);
        $this->assertSame('', $normalized['email']);
        $this->assertSame('', $normalized['user']);
        $this->assertSame('', $normalized['login']);
        $this->assertSame('', $normalized['identifier']);
        $this->assertSame('secret_sauce', $normalized['password']);
    }
}
