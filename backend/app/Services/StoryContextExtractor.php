<?php

namespace App\Services;

use App\Models\UserStory;
use Illuminate\Support\Collection;

class StoryContextExtractor
{
    private const GENERIC_STOP_WORDS = [
        'user', 'client', 'customer', 'patient', 'system', 'application', 'project',
        'story', 'feature', 'screen', 'page', 'data', 'details', 'information',
        'valid', 'invalid', 'error', 'message', 'required', 'should', 'with',
        'when', 'then', 'given', 'that', 'this', 'from', 'into', 'after', 'before',
    ];

    private const DIMENSION_KEYWORDS = [
        'happy_path' => ['complete', 'success', 'successful', 'confirm', 'accepted', 'finalize', 'book', 'reserve', 'pay', 'send'],
        'validation' => ['validate', 'validation', 'required', 'invalid', 'format', 'missing', 'field', 'cvv', 'email'],
        'business_rules' => ['only', 'must', 'cannot', 'should not', 'rule', 'unique', 'future', 'available'],
        'error_handling' => ['error', 'fail', 'failed', 'declined', 'reject', 'unavailable', 'timeout'],
        'notifications' => ['email', 'sms', 'notification', 'alert', 'confirmation', 'reminder', 'rappel'],
        'data_integrity' => ['stock', 'inventory', 'duplicate', 'consistency', 'persist', 'update', 'status'],
        'security' => ['card', 'payment', 'password', 'token', 'authorization', 'permission', 'access', 'medical'],
        'usability' => ['clear', 'display', 'visible', 'responsive', 'accessible'],
        'concurrency' => ['double', 'twice', 'simultaneous', 'already', 'once'],
        'integrations' => ['provider', 'gateway', 'service', 'api', 'email', 'sms', 'scheduler'],
        'auditability' => ['log', 'logged', 'journal', 'trace', 'audit'],
    ];

    private const DOMAIN_KEYWORDS = [
        'authentication' => ['auth', 'authentication', 'login', 'logout', 'password', 'credential', 'session'],
        'payment' => ['payment', 'paiement', 'card', 'carte', 'checkout', 'invoice', 'facture', 'cvv'],
        'booking' => ['appointment', 'booking', 'rendez', 'reservation', 'slot', 'creneau', 'doctor', 'medecin', 'consultation'],
        'notification' => ['notification', 'notify', 'email', 'sms', 'alert', 'reminder', 'rappel'],
        'profile' => ['profile', 'profil', 'account', 'compte', 'preferences'],
        'api' => ['api', 'endpoint', 'request', 'response', 'json', 'bearer'],
        'admin' => ['admin', 'dashboard', 'role', 'permission', 'user management'],
        'reporting' => ['report', 'kpi', 'metric', 'dashboard', 'analytics'],
    ];

    private const INTENT_KEYWORDS = [
        'create_booking' => ['book', 'booking', 'reserve', 'reservation', 'prendre', 'creer', 'create'],
        'cancel_booking' => ['cancel', 'annul', 'annulation', 'supprimer', 'remove'],
        'reschedule_booking' => ['reschedule', 'replan', 'report', 'move', 'modifier', 'changer'],
        'reminder_notification' => ['reminder', 'rappel', 'scheduler', 'notification', 'notify'],
        'authentication_access' => ['login', 'logout', 'sign in', 'password reset', 'authenticate'],
        'payment_processing' => ['payment', 'paiement', 'refund', 'checkout'],
    ];

    private const ENTITY_KEYWORDS = [
        'patient' => ['patient'],
        'doctor' => ['doctor', 'medecin', 'practitioner'],
        'appointment' => ['appointment', 'rendez', 'reservation', 'booking'],
        'slot' => ['slot', 'creneau'],
        'notification' => ['notification', 'email', 'sms', 'reminder', 'rappel'],
        'payment' => ['payment', 'paiement', 'invoice', 'facture', 'card', 'carte'],
    ];

    public function extract(UserStory $userStory): array
    {
        $acceptanceCriteria = $this->splitTextList((string) $userStory->acceptance_criteria);
        $businessRules = $this->splitTextList($this->stringifyList($userStory->business_rules));
        $scenarios = $this->splitTextList($this->stringifyList($userStory->scenarios));

        $fullText = implode(' ', array_filter([
            $userStory->title,
            $userStory->description,
            $userStory->as_a,
            $userStory->i_want_that,
            $userStory->so_that,
            implode(' ', $acceptanceCriteria),
            implode(' ', $businessRules),
            implode(' ', $scenarios),
        ]));

        $tokens = $this->tokenize($fullText);
        $dimensions = $this->extractDimensions($fullText, $acceptanceCriteria, $businessRules, $scenarios);
        $domains = $this->detectDomains($fullText);
        $intent = $this->detectIntent($fullText, $domains);
        $entities = $this->detectEntities($fullText);
        $integrationPoints = $this->detectIntegrationPoints($fullText);
        $workflowStage = $this->detectWorkflowStage($intent);

        return [
            'story_id' => $userStory->id,
            'title' => trim((string) $userStory->title),
            'project_name' => trim((string) optional($userStory->project)->name),
            'project_id' => $userStory->project_id,
            'actor' => $this->firstMeaningfulText([$userStory->as_a, $this->extractActorFromDescription((string) $userStory->description)]),
            'goal' => $this->firstMeaningfulText([$userStory->i_want_that, (string) $userStory->title]),
            'benefit' => $this->firstMeaningfulText([$userStory->so_that]),
            'priority' => (string) ($userStory->priority ?? 'medium'),
            'acceptance_criteria' => $acceptanceCriteria,
            'business_rules' => $businessRules,
            'scenarios' => $scenarios,
            'tokens' => $tokens,
            'dimensions' => $dimensions,
            'domains' => $domains,
            'primary_domain' => $domains[0] ?? 'general',
            'intent' => $intent,
            'entities' => $entities,
            'integration_points' => $integrationPoints,
            'workflow_stage' => $workflowStage,
            'project_context' => [
                'name' => trim((string) optional($userStory->project)->name),
                'same_project_preferred' => true,
            ],
        ];
    }

    private function extractDimensions(string $fullText, array $acceptanceCriteria, array $businessRules, array $scenarios): array
    {
        $normalized = $this->normalizeText($fullText);
        $dimensions = [];

        foreach (self::DIMENSION_KEYWORDS as $dimension => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $this->normalizeText($keyword))) {
                    $dimensions[] = $dimension;
                    break;
                }
            }
        }

        if ($acceptanceCriteria !== []) {
            $dimensions[] = 'happy_path';
            $dimensions[] = 'validation';
        }

        if ($businessRules !== []) {
            $dimensions[] = 'business_rules';
        }

        if ($scenarios !== []) {
            $dimensions[] = 'error_handling';
        }

        return array_values(array_unique($dimensions));
    }

    private function detectDomains(string $fullText): array
    {
        $normalized = $this->normalizeText($fullText);
        $domains = [];

        foreach (self::DOMAIN_KEYWORDS as $domain => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $this->normalizeText($keyword))) {
                    $domains[] = $domain;
                    break;
                }
            }
        }

        return array_values(array_unique($domains));
    }

    private function detectIntent(string $fullText, array $domains): string
    {
        $normalized = $this->normalizeText($fullText);

        foreach (self::INTENT_KEYWORDS as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $this->normalizeText($keyword))) {
                    return $intent;
                }
            }
        }

        if (in_array('notification', $domains, true)) {
            return 'notification_flow';
        }

        if (in_array('booking', $domains, true)) {
            return 'booking_flow';
        }

        return 'generic_flow';
    }

    private function detectEntities(string $fullText): array
    {
        $normalized = $this->normalizeText($fullText);
        $entities = [];

        foreach (self::ENTITY_KEYWORDS as $entity => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $this->normalizeText($keyword))) {
                    $entities[] = $entity;
                    break;
                }
            }
        }

        return array_values(array_unique($entities));
    }

    private function detectIntegrationPoints(string $fullText): array
    {
        $normalized = $this->normalizeText($fullText);
        $points = [];

        foreach (['email', 'sms', 'api', 'scheduler', 'gateway', 'provider', 'calendar'] as $point) {
            if (str_contains($normalized, $point)) {
                $points[] = $point;
            }
        }

        return array_values(array_unique($points));
    }

    private function detectWorkflowStage(string $intent): string
    {
        return match ($intent) {
            'create_booking' => 'transaction_start',
            'cancel_booking', 'reschedule_booking' => 'transaction_update',
            'reminder_notification' => 'post_booking_notification',
            'authentication_access' => 'access_control',
            'payment_processing' => 'payment_execution',
            default => 'generic_workflow',
        };
    }

    private function tokenize(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/', $this->normalizeText($value)) ?: [];

        return Collection::make($parts)
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => strlen($part) >= 4)
            ->reject(fn (string $part) => in_array($part, self::GENERIC_STOP_WORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    private function splitTextList(string $value): array
    {
        return Collection::make(preg_split('/\r\n|\r|\n|\|/', $value) ?: [])
            ->map(fn (string $item) => trim(preg_replace('/^\d+\.\s*/', '', $item) ?? $item))
            ->filter(fn (string $item) => $item !== '')
            ->values()
            ->all();
    }

    private function stringifyList(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\n", array_map(function ($item) {
                if (!is_array($item)) {
                    return trim((string) $item);
                }
                // Flatten nested arrays (e.g. scenario objects: {titre: '...', etapes: [...]})
                $parts = [];
                foreach ($item as $v) {
                    $parts[] = is_array($v)
                        ? implode(', ', array_map('strval', $v))
                        : trim((string) $v);
                }
                return implode(' - ', array_filter($parts));
            }, $value));
        }

        return trim((string) ($value ?? ''));
    }

    private function firstMeaningfulText(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function extractActorFromDescription(string $description): string
    {
        if (preg_match('/en tant que\s+([^,]+),/i', $description, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function normalizeText(string $value): string
    {
        $normalized = strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        return $transliterated !== false ? $transliterated : $normalized;
    }
}
