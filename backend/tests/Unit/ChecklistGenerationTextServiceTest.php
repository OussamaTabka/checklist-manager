<?php

namespace Tests\Unit;

use App\Services\ChecklistGenerationTextService;
use PHPUnit\Framework\TestCase;

class ChecklistGenerationTextServiceTest extends TestCase
{
    public function test_it_normalizes_supported_languages(): void
    {
        $service = new ChecklistGenerationTextService();

        $this->assertSame('en', $service->normalizeLanguage('en-US'));
        $this->assertSame('fr', $service->normalizeLanguage('ar'));
    }

    public function test_it_removes_visible_case_prefixes_and_localizes_titles(): void
    {
        $service = new ChecklistGenerationTextService();

        $this->assertSame(
            'Validation des champs pour le paiement',
            $service->localizeCaseName('Critere 01 : Validation des champs pour le paiement', 'fr')
        );

        $this->assertSame(
            'Business rules enforced for Checkout',
            $service->localizeCaseName('Regles metier appliquees pour Checkout', 'en')
        );
    }
}
