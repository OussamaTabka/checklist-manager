<?php

namespace App\Services;

class RiskProfileAnalyzer
{
    private const RISK_FACTORS = [
        'security' => ['security', 'password', 'token', 'permission', 'medical', 'sensitive', 'access'],
        'financial' => ['payment', 'paiement', 'refund', 'invoice', 'card', 'cvv'],
        'customer_commitment' => ['appointment', 'rendez', 'booking', 'reservation', 'consultation'],
        'notification_reliability' => ['notification', 'email', 'sms', 'reminder', 'scheduler'],
        'integration_dependency' => ['api', 'provider', 'gateway', 'scheduler', 'service'],
        'data_integrity' => ['duplicate', 'status', 'persist', 'consistency', 'log', 'audit'],
    ];

    public function analyze(array $storyContext): array
    {
        $haystack = strtolower(implode(' ', array_filter([
            $storyContext['title'] ?? '',
            $storyContext['goal'] ?? '',
            $storyContext['benefit'] ?? '',
            implode(' ', $storyContext['acceptance_criteria'] ?? []),
            implode(' ', $storyContext['business_rules'] ?? []),
            implode(' ', $storyContext['scenarios'] ?? []),
            implode(' ', $storyContext['dimensions'] ?? []),
            implode(' ', $storyContext['integration_points'] ?? []),
        ])));

        $factors = [];
        $score = 0;

        foreach (self::RISK_FACTORS as $factor => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $factors[] = $factor;
                    $score += $this->factorWeight($factor);
                    break;
                }
            }
        }

        $priority = strtolower((string) ($storyContext['priority'] ?? 'medium'));
        $priorityBonus = match ($priority) {
            'critical' => 25,
            'high' => 15,
            'medium' => 8,
            default => 0,
        };

        $score += $priorityBonus;
        $requiredDimensions = $this->requiredDimensions($factors, $storyContext);
        $riskLevel = match (true) {
            $score >= 70 => 'critical',
            $score >= 45 => 'high',
            $score >= 20 => 'medium',
            default => 'low',
        };

        return [
            'level' => $riskLevel,
            'score' => min(100, $score),
            'factors' => array_values(array_unique($factors)),
            'required_dimensions' => $requiredDimensions,
            'business_criticality' => $priority,
        ];
    }

    private function factorWeight(string $factor): int
    {
        return match ($factor) {
            'security', 'financial' => 20,
            'customer_commitment', 'integration_dependency' => 15,
            'notification_reliability', 'data_integrity' => 12,
            default => 8,
        };
    }

    private function requiredDimensions(array $factors, array $storyContext): array
    {
        $dimensions = $storyContext['dimensions'] ?? [];

        if (in_array('security', $factors, true)) {
            $dimensions[] = 'security';
        }

        if (in_array('integration_dependency', $factors, true)) {
            $dimensions[] = 'integrations';
            $dimensions[] = 'error_handling';
        }

        if (in_array('notification_reliability', $factors, true)) {
            $dimensions[] = 'notifications';
            $dimensions[] = 'auditability';
        }

        if (in_array('customer_commitment', $factors, true)) {
            $dimensions[] = 'business_rules';
            $dimensions[] = 'data_integrity';
        }

        return array_values(array_unique($dimensions));
    }
}
