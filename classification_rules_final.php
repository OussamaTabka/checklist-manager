<?php
/**
 * RÈGLE DE CLASSIFICATION FINALE - Test Case Categorization
 *
 * Stratégie : Analyse du TITRE EN PRIORITÉ, puis description si neutre
 * Précédence : NÉGATIF > EDGE-CASE > POSITIF
 * Fallback : POSITIF par défaut si aucune catégorie ne matche
 */

class TestCaseClassifier
{
    // ==================== PATTERNS ====================

    // POSITIF : mots qui signalent un SUCCÈS NOMINAL explicite
    private const POSITIF_PATTERNS = [
        // Succès explicite
        'réussit', 'réussi', 'réussis', 'successful', 'success',
        // Progression
        'avance', 'advance', 'proceed', 'progression', 'procède',
        // Redirection intentionnelle (contexte de succès)
        'redirection vers', 'redirige vers', 'redirects to',
        // Données valides (contexte succès)
        'avec identifiants valides', 'with valid', 'avec données valides',
        // Happy path
        'happy path', 'nominal', 'normal',
    ];

    // NÉGATIF : mots qui signalent un REFUS/BLOCAGE/ERREUR
    private const NEGATIF_PATTERNS = [
        // Erreurs/Invalide
        'erreur', 'error', 'failed', 'fails', 'invalid', 'invalide',
        'incorrec', // incorrec* (incorrect, incorrectement)
        // Refus/Blocage
        'refuse', 'refus', 'reject', 'rejected', 'denied', 'blocked', 'block',
        'prevent', 'prevents', 'empêch', // empêch* (empêche, empêcher)
        'absent', 'missing',
        // Non autorisé/Pas d'accès
        'unauthorized', 'forbidden', 'not allowed', 'sans permission',
        'non autorisé', 'pas autorisé',
    ];

    // EDGE-CASE : déclencheurs raffinés (vrais cas limites)
    private const EDGECASE_PATTERNS = [
        // Valeur extrême
        'limite', 'limit', 'boundary', 'dépassement', 'overflow',
        'maximum', 'minimum', 'max', 'min',
        'très long', 'très court', 'trop',
        'zéro article', '0 article', '0 élément',
        '1000', '999', '100000', // grosses limites
        // Caractère spécial
        'caractères spéciaux', 'special character', 'unicode', 'emoji',
        'accents', 'espaces multiples',
        // Temporel anormal
        'timeout', 'délai', 'expiration', 'expir',
        'race condition', 'simultané', 'concurrent', 'parallel',
        'double-clic', 'triple-clic', 'rapide',
        'rafraîchissement pendant', 'refresh during', 'back navigateur',
        // État inhabituel
        'déjà connecté', 'session expir', 'liste vide en tant qu\'état',
    ];

    // ==================== CLASSIFICATION ====================

    /**
     * Classifie un item de test en : POSITIF, NÉGATIF ou EDGE-CASE
     *
     * Stratégie : Analyse du TITRE EN PRIORITÉ, puis description si neutre
     * AVANT NÉGATIF, applique les règles d'exclusion : NÉGATION et NOMINALISATION
     *
     * @return array ['category' => 'POSITIF|NÉGATIF|EDGE-CASE', 'matched_in' => 'title|description|default', 'exclusion_applied' => bool, 'exclusion_rule' => string]
     */
    public function classify(string $title, ?string $description = ''): array
    {
        $description = $description ?? '';

        // ========== STEP 1 : ANALYSE DU TITRE ==========
        $title_analysis = $this->analyzeText($title);

        if ($title_analysis['negatif']) {
            // Appliquer les règles d'exclusion AVANT de retourner NÉGATIF
            // IMPORTANT : passer titre + description pour pouvoir détecter tous les patterns
            $full_text = $title . ' ' . $description;
            $exclusion = $this->checkExclusions($full_text);
            if ($exclusion['excluded']) {
                return [
                    'category' => 'POSITIF',
                    'matched_in' => 'title',
                    'reason' => 'Titre contient négatif, mais exclusion appliquée : ' . $exclusion['rule'],
                    'exclusion_applied' => true,
                    'exclusion_rule' => $exclusion['rule'],
                ];
            }

            return [
                'category' => 'NÉGATIF',
                'matched_in' => 'title',
                'reason' => 'Titre contient : ' . implode(', ', $title_analysis['negatif_triggers']),
                'exclusion_applied' => false,
            ];
        }

        if ($title_analysis['positif']) {
            return [
                'category' => 'POSITIF',
                'matched_in' => 'title',
                'reason' => 'Titre contient : ' . implode(', ', $title_analysis['positif_triggers']),
                'exclusion_applied' => false,
            ];
        }

        if ($title_analysis['edgecase']) {
            return [
                'category' => 'EDGE-CASE',
                'matched_in' => 'title',
                'reason' => 'Titre contient : ' . implode(', ', $title_analysis['edgecase_triggers']),
                'exclusion_applied' => false,
            ];
        }

        // ========== STEP 2 : TITRE NEUTRE -> ANALYSER DESCRIPTION ==========
        if (!empty($description)) {
            $desc_analysis = $this->analyzeText($description);

            if ($desc_analysis['negatif']) {
                // Appliquer les règles d'exclusion AVANT de retourner NÉGATIF
                // IMPORTANT : passer titre + description pour pouvoir détecter tous les patterns
                $full_text = $title . ' ' . $description;
                $exclusion = $this->checkExclusions($full_text);
                if ($exclusion['excluded']) {
                    return [
                        'category' => 'POSITIF',
                        'matched_in' => 'description',
                        'reason' => 'Description contient négatif, mais exclusion appliquée : ' . $exclusion['rule'],
                        'exclusion_applied' => true,
                        'exclusion_rule' => $exclusion['rule'],
                    ];
                }

                return [
                    'category' => 'NÉGATIF',
                    'matched_in' => 'description',
                    'reason' => 'Description contient : ' . implode(', ', $desc_analysis['negatif_triggers']),
                    'exclusion_applied' => false,
                ];
            }

            if ($desc_analysis['edgecase']) {
                return [
                    'category' => 'EDGE-CASE',
                    'matched_in' => 'description',
                    'reason' => 'Description contient : ' . implode(', ', $desc_analysis['edgecase_triggers']),
                    'exclusion_applied' => false,
                ];
            }

            if ($desc_analysis['positif']) {
                return [
                    'category' => 'POSITIF',
                    'matched_in' => 'description',
                    'reason' => 'Description contient : ' . implode(', ', $desc_analysis['positif_triggers']),
                    'exclusion_applied' => false,
                ];
            }
        }

        // ========== STEP 3 : AUCUNE CATÉGORIE MATCH -> FALLBACK POSITIF ==========
        return [
            'category' => 'POSITIF',
            'matched_in' => 'default',
            'reason' => 'Pas de critère NÉGATIF/EDGE-CASE, fallback POSITIF',
            'exclusion_applied' => false,
        ];
    }

    /**
     * Vérifie si les règles d'exclusion s'appliquent au texte
     *
     * Règle 1 : NÉGATION
     *   Si un trigger NÉGATIF est précédé de "sans", "pas de", "pas d'", "aucun(e)", "no"
     *   → ne pas déclencher NÉGATIF
     *
     * Règle 2 : NOMINALISATION D'ACTION
     *   Si un mot NÉGATIF est au participe passé (invalidée, invalidées) ou
     *   sous forme de nom dans une phrase de résultat attendu → POSITIF
     */
    private function checkExclusions(string $text): array
    {
        $text_lower = mb_strtolower($text, 'UTF-8');

        // ========== RÈGLE 1 : NÉGATION ==========
        // Patterns : "sans {negatif}", "pas de {negatif}", "pas d'{negatif}", "aucun(e) {negatif}", "no {negatif}"
        $negation_prefixes = ['sans', 'pas de', "pas d'", 'aucun', 'aucune', 'no '];
        $negatif_keywords = ['erreur', 'error', 'invalid', 'invalide', 'blocage', 'blocked', 'block', 'refuse', 'refus', 'reject'];

        foreach ($negation_prefixes as $prefix) {
            foreach ($negatif_keywords as $keyword) {
                // Chercher patterns comme "sans erreur", "pas d'erreur", etc.
                if (preg_match('#' . preg_quote($prefix, '#') . '\s+(?:.*?\s+)?' . preg_quote($keyword, '#') . '#i', $text_lower)) {
                    return [
                        'excluded' => true,
                        'rule' => 'NÉGATION : ' . $prefix . ' ' . $keyword,
                    ];
                }
            }
        }

        // ========== RÈGLE 2 : NOMINALISATION D'ACTION ==========
        // Participe passé ou adjectif attribut : "invalidée", "invalidées", etc.
        // ATTENTION : L'exclusion ne s'applique que si le mot nominalisé n'est PAS suivi
        // d'un pattern de "rejet utilisateur"

        $nominalizations = [
            'invalidée', 'invalidées', 'invalidation',
            'bloquée', 'bloquées', 'blocage',
            'refusée', 'refusées', 'refus',
            'bannies', 'bannit', 'échec', 'rejet',
        ];

        // Patterns de "rejet utilisateur" qui ANNULENT l'exclusion NOMINALISATION
        // Si la nominalisation est suivie de ces patterns, ça reste NÉGATIF
        $user_rejection_patterns = [
            'de\s+(?:user|utilisateur|account|compte|locked_out|admin|guest)',
            'de\s+(?:la|une)\s+connexion',
            'de\s+(?:la|une)\s+tentative',
            'de\s+(?:l\'|l|la|une)\s+accès',
            'de\s+(?:la|une)\s+requête',
            'de\s+(?:la|une)\s+action',
            'of\s+(?:user|account|attempt|login|request|access)',
        ];

        foreach ($nominalizations as $nominalization) {
            // Variante 1 : après "est"/"sont"/"reste"/"demeure"
            if (preg_match('#(?:est|sont|reste|demeure)\s+(?:.*?\s+)?' . preg_quote($nominalization, '#') . '#i', $text_lower)) {
                // Vérifier que la nominalisation n'est PAS suivie d'un pattern de rejet utilisateur
                $has_user_rejection = false;
                foreach ($user_rejection_patterns as $pattern) {
                    if (preg_match('#' . preg_quote($nominalization, '#') . '\s+' . $pattern . '#i', $text_lower)) {
                        $has_user_rejection = true;
                        break;
                    }
                }

                if (!$has_user_rejection) {
                    return [
                        'excluded' => true,
                        'rule' => 'NOMINALISATION : ' . $nominalization . ' (état attendu)',
                    ];
                }
            }

            // Variante 2 : suivi de mots signalant le résultat attendu
            if (preg_match('#' . preg_quote($nominalization, '#') . '\s+(?:après|lors|pendant|pour|garantir|assurer|vérifier|globalement|complètement|entièrement)#i', $text_lower)) {
                // Vérifier que la nominalisation n'est PAS suivie d'un pattern de rejet utilisateur
                $has_user_rejection = false;
                foreach ($user_rejection_patterns as $pattern) {
                    if (preg_match('#' . preg_quote($nominalization, '#') . '\s+' . $pattern . '#i', $text_lower)) {
                        $has_user_rejection = true;
                        break;
                    }
                }

                if (!$has_user_rejection) {
                    return [
                        'excluded' => true,
                        'rule' => 'NOMINALISATION : ' . $nominalization . ' (action attendue)',
                    ];
                }
            }

            // Variante 3 : Même si pas de "est"/"sont", vérifier directement si suivi de rejet utilisateur
            // Si c'est le cas, NE PAS appliquer l'exclusion
            foreach ($user_rejection_patterns as $pattern) {
                if (preg_match('#' . preg_quote($nominalization, '#') . '\s+' . $pattern . '#i', $text_lower)) {
                    // L'exclusion NE s'applique PAS -> retourne pas d'exclusion
                    return [
                        'excluded' => false,
                        'rule' => null,
                    ];
                }
            }
        }

        return [
            'excluded' => false,
            'rule' => null,
        ];
    }

    /**
     * Analyse un texte pour trouver les mots-clés des 3 catégories
     *
     * Précédence : NÉGATIF > EDGE-CASE > POSITIF (mais on retourne tous les matchs pour audit)
     */
    private function analyzeText(string $text): array
    {
        $text_lower = mb_strtolower($text, 'UTF-8');

        $negatif_triggers = [];
        $positif_triggers = [];
        $edgecase_triggers = [];

        // Check NÉGATIF
        foreach (self::NEGATIF_PATTERNS as $pattern) {
            if (mb_stripos($text_lower, $pattern) !== false) {
                $negatif_triggers[] = $pattern;
            }
        }

        // Check POSITIF
        foreach (self::POSITIF_PATTERNS as $pattern) {
            if (mb_stripos($text_lower, $pattern) !== false) {
                $positif_triggers[] = $pattern;
            }
        }

        // Check EDGE-CASE
        foreach (self::EDGECASE_PATTERNS as $pattern) {
            if (mb_stripos($text_lower, $pattern) !== false) {
                $edgecase_triggers[] = $pattern;
            }
        }

        return [
            'negatif' => !empty($negatif_triggers),
            'negatif_triggers' => array_slice(array_unique($negatif_triggers), 0, 3),
            'positif' => !empty($positif_triggers),
            'positif_triggers' => array_slice(array_unique($positif_triggers), 0, 3),
            'edgecase' => !empty($edgecase_triggers),
            'edgecase_triggers' => array_slice(array_unique($edgecase_triggers), 0, 3),
        ];
    }

    /**
     * Debug : retourne tous les matches (pour audit)
     */
    public function analyzeWithAudit(string $title, ?string $description = ''): array
    {
        $description = $description ?? '';

        $title_analysis = $this->analyzeText($title);
        $desc_analysis = !empty($description) ? $this->analyzeText($description) : [
            'negatif' => false, 'negatif_triggers' => [],
            'positif' => false, 'positif_triggers' => [],
            'edgecase' => false, 'edgecase_triggers' => [],
        ];

        $result = $this->classify($title, $description);

        return [
            'classification' => $result,
            'title_matches' => $title_analysis,
            'description_matches' => $desc_analysis,
        ];
    }
}
