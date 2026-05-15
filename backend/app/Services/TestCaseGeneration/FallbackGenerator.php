<?php

namespace App\Services\TestCaseGeneration;

use App\Services\StoryContextExtractor;
use App\Services\ChecklistGenerationTextService;
use App\Models\UserStory;
use Illuminate\Support\Collection;

/**
 * Fallback Test Case Generator
 * 
 * Simple rule-based generator when other services are unavailable
 * Generates basic test scenarios from user story structure
 */
class FallbackGenerator implements TestCaseGeneratorInterface
{
    private const PAYMENT_KEYWORDS = ['paiement', 'payment', 'carte', 'card', 'checkout', 'commande', 'order', 'cvv'];
    private const BOOKING_KEYWORDS = ['rendez', 'appointment', 'booking', 'creneau', 'slot', 'medecin', 'doctor', 'reservation', 'reserve'];
    private const REMINDER_KEYWORDS = ['reminder', 'rappel', 'notification', 'notify', 'scheduler', 'schedule', 'consultation', 'access instructions'];
    private const CANCEL_KEYWORDS = ['cancel', 'annul', 'annulation', 'supprimer', 'remove'];
    private const RESCHEDULE_KEYWORDS = ['reschedule', 'replan', 'report', 'changer', 'modifier', 'move'];
    private const CREATE_KEYWORDS = ['book', 'booking', 'reserve', 'reservation', 'prendre', 'creer', 'create'];

    public function __construct(
        private ?StoryContextExtractor $storyContextExtractor = null,
        private ?ChecklistGenerationTextService $generationText = null
    ) {
        $this->storyContextExtractor ??= new StoryContextExtractor();
        $this->generationText ??= app(ChecklistGenerationTextService::class);
    }

    /**
     * Generate basic test cases from user story
     *
     * @param UserStory $userStory
     * @return array
     */
    public function generateTestCases(UserStory $userStory, array $context = []): array
    {
        $language = $this->generationText->normalizeLanguage($context['language'] ?? 'fr');
        $storyContext = $context['story_context'] ?? $this->storyContextExtractor->extract($userStory);
        $storyIntent = $this->detectStoryIntent($userStory, $storyContext);
        $criterionCases = [];

        // Parse acceptance criteria into test cases
        if (($storyContext['acceptance_criteria'] ?? []) !== []) {
            $criterionCases = $this->generateFromCriteria($storyContext['acceptance_criteria']);
        }

        $domainSpecificCases = $this->generateDomainSpecificCases($userStory, $storyContext, $storyIntent);
        $dimensionCases = $this->generateDimensionCases($userStory, $storyContext, $storyIntent);
        $gapCases = $this->generateGapFocusedCases($userStory, $storyContext, $context, $storyIntent);

        if (count($criterionCases) >= 3) {
            $dimensionCases = array_values(array_filter(
                $dimensionCases,
                fn (array $item) => !str_starts_with(strtolower((string) ($item['name'] ?? '')), 'cas nominal')
                    && !str_starts_with(strtolower((string) ($item['name'] ?? '')), 'validation des champs')
                    && !str_starts_with(strtolower((string) ($item['name'] ?? '')), 'regles metier')
            ));
        }

        $testCases = array_merge(
            $criterionCases,
            $domainSpecificCases,
            $gapCases,
            $dimensionCases
        );

        $testCases = $this->ensureMinimumCoverage($testCases, $criterionCases, $userStory, $storyContext, $context, $storyIntent);
        $testCases = $this->filterCasesByIntent($testCases, $storyIntent);
        $testCases = $this->removeCasesCoveredByStoryContext($testCases, $storyContext);
        $testCases = $this->removeNearDuplicates($testCases);

        return Collection::make($testCases)
            ->map(function (array $item) use ($language) {
                $localized = $this->generationText->localizeCase($item, $language);
                $localized['language'] = $language;

                return $localized;
            })
            ->unique(fn (array $item) => strtolower(trim((string) ($item['name'] ?? ''))))
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Check if available (always available as fallback)
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Get service name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Fallback (base de regles)';
    }

    /**
     * Generate test cases from acceptance criteria
     *
     * @param array<int, string> $criteria
     * @return array
     */
    private function generateFromCriteria(array $criteria): array
    {
        $testCases = [];
        foreach ($criteria as $index => $criterion) {
            $criterion = trim((string) preg_replace('/^\d+\.\s*/', '', $criterion));

            if (!empty($criterion)) {
                $testCases[] = [
                    'name' => $this->criterionTitle($criterion, $index),
                    'description' => $criterion,
                    'expected_result' => 'Le critere est respecte.',
                    'severity' => $this->determineSeverity($index),
                    'category' => 'acceptance_criterion',
                ];
            }
        }

        return $testCases;
    }

    /**
     * Generate generic test cases from detected test dimensions.
     *
     * @param UserStory $userStory
     * @param array $context
     * @return array
     */
    private function generateDimensionCases(UserStory $userStory, array $context, string $storyIntent = 'generic'): array
    {
        $cases = [];
        $actor = $context['actor'] ?: 'utilisateur';
        $dimensions = $context['dimensions'] ?? [];
        $actionLabel = $this->intentActionLabel($storyIntent);

        foreach ($dimensions as $dimension) {
            $template = match ($dimension) {
                'happy_path' => [
                    'name' => match ($storyIntent) {
                        'cancel_booking' => 'Confirmation d annulation met a jour le statut et le creneau',
                        'reminder_notification' => 'Rappel envoye au bon moment avec les bonnes informations',
                        default => "Cas nominal reussi pour {$userStory->title}",
                    },
                    'description' => match ($storyIntent) {
                        'cancel_booking' => 'Verifier qu apres confirmation, le rendez-vous passe au statut annule et que le creneau est de nouveau disponible.',
                        'reminder_notification' => 'Verifier qu un rappel est envoye a l heure prevue pour un rendez-vous confirme avec la date, l heure et les instructions d acces.',
                        default => "Verifier que le flux principal permet au {$actor} de {$actionLabel} avec succes dans les conditions de la story.",
                    },
                    'expected_result' => match ($storyIntent) {
                        'cancel_booking' => 'Le statut du rendez-vous, la disponibilite du creneau et le message de confirmation sont coherents.',
                        'reminder_notification' => 'Le rappel est envoye une seule fois au bon destinataire avec un contenu complet et exact.',
                        default => 'Le flux metier principal se termine correctement et la confirmation attendue est affichee.',
                    },
                    'severity' => 'high',
                ],
                'validation' => [
                    'name' => match ($storyIntent) {
                        'cancel_booking' => 'Annulation refusee si le rendez-vous est deja demarre',
                        'reminder_notification' => 'Aucun rappel pour un rendez-vous annule avant l echeance',
                        default => "Validation des champs pour {$userStory->title}",
                    },
                    'description' => match ($storyIntent) {
                        'cancel_booking' => 'Verifier qu un rendez-vous commence, termine ou deja passe ne peut plus etre annule par le patient.',
                        'reminder_notification' => 'Verifier qu un rendez-vous annule avant l heure de rappel est ignore par le scheduler et ne declenche aucune notification.',
                        default => 'Verifier les champs requis, les formats invalides et les combinaisons non autorisees mentionnees par la story.',
                    },
                    'expected_result' => match ($storyIntent) {
                        'cancel_booking' => 'Le systeme bloque l action avec un message explicite sans modifier le statut du rendez-vous.',
                        'reminder_notification' => 'Aucun rappel n est envoye et le rendez-vous conserve un etat coherent.',
                        default => 'Les donnees invalides sont rejetees et un message explicite est affiche sans corrompre l etat.',
                    },
                    'severity' => 'high',
                ],
                'business_rules' => [
                    'name' => "Regles metier appliquees pour {$userStory->title}",
                    'description' => 'Verifier que les contraintes metier du domaine sont appliquees sur tout le parcours.',
                    'expected_result' => 'Les regles metier sont appliquees de facon coherente et les actions non autorisees sont bloquees.',
                    'severity' => 'high',
                ],
                'error_handling' => [
                    'name' => match ($storyIntent) {
                        'cancel_booking' => 'Echec technique sans annulation partielle',
                        'reminder_notification' => 'Echec de livraison journalise sans modifier le rendez-vous',
                        default => "Gestion des erreurs pour {$userStory->title}",
                    },
                    'description' => match ($storyIntent) {
                        'cancel_booking' => 'Verifier qu en cas d erreur serveur, timeout ou echec fournisseur, le rendez-vous reste dans son etat initial et le creneau n est pas libere a tort.',
                        'reminder_notification' => 'Verifier qu en cas de canal indisponible, le rappel non delivre est journalise sans annuler ni modifier le rendez-vous.',
                        default => 'Verifier les erreurs systeme, metier ou fournisseur concretement liees au parcours de la story.',
                    },
                    'expected_result' => match ($storyIntent) {
                        'cancel_booking' => 'Aucune annulation partielle n est enregistree et un retour comprehensible est affiche au patient.',
                        'reminder_notification' => 'L echec est trace correctement et le rendez-vous reste inchange.',
                        default => 'Les erreurs sont gerees sans confirmation incorrecte ni corruption des donnees.',
                    },
                    'severity' => 'high',
                ],
                'notifications' => [
                    'name' => $storyIntent === 'reminder_notification'
                        ? "Notification de rappel unique et complete pour {$userStory->title}"
                        : "Notifications correctes pour {$userStory->title}",
                    'description' => $storyIntent === 'reminder_notification'
                        ? 'Verifier que le rappel contient la date, l heure et les instructions d acces, sans envoi premature ni doublon.'
                        : 'Verifier que les notifications ou confirmations ne sont declenchees qu apres une issue reussie.',
                    'expected_result' => $storyIntent === 'reminder_notification'
                        ? 'Le rappel contient les bonnes informations et n est emis qu une seule fois a la bonne echeance.'
                        : 'Les notifications sont envoyees avec le bon contenu uniquement apres succes de l action metier.',
                    'severity' => 'medium',
                ],
                'data_integrity' => [
                    'name' => "Integrite des donnees preservee pour {$userStory->title}",
                    'description' => 'Verifier que la persistance, le stock, la prevention des doublons et les mises a jour restent coherents.',
                    'expected_result' => 'Seules les donnees attendues sont modifiees et aucun etat incoherent ou doublon n est cree.',
                    'severity' => 'high',
                ],
                'security' => [
                    'name' => "Securite des donnees sensibles pour {$userStory->title}",
                    'description' => 'Verifier que les actions protegees et les donnees sensibles sont traitees de facon securisee.',
                    'expected_result' => 'Les operations sensibles restent protegees et aucune information confidentielle n est exposee.',
                    'severity' => 'high',
                ],
                'usability' => [
                    'name' => "Retours utilisateur clairs pour {$userStory->title}",
                    'description' => 'Verifier que les messages d etat, confirmations et erreurs sont comprehensibles pour l utilisateur.',
                    'expected_result' => 'L utilisateur comprend clairement ce qui s est passe et la prochaine action a effectuer.',
                    'severity' => 'medium',
                ],
                'concurrency' => [
                    'name' => "Prevention des actions en double pour {$userStory->title}",
                    'description' => 'Verifier que les soumissions repetees ou simultanees ne creent pas de resultat en double.',
                    'expected_result' => 'L action n est appliquee qu une seule fois et les doublons sont bloques ou ignores proprement.',
                    'severity' => 'high',
                ],
                'integrations' => [
                    'name' => "Integrations externes fiables pour {$userStory->title}",
                    'description' => 'Verifier que les services externes sont appeles correctement et que leurs echecs sont geres proprement.',
                    'expected_result' => 'Les integrations externes reussissent avec des donnees valides et echouent sans effet de bord critique.',
                    'severity' => 'medium',
                ],
                default => null,
            };

            if ($template) {
                $cases[] = $template;
            }
        }

        if ($cases === []) {
            $cases[] = [
                'name' => match ($storyIntent) {
                    'cancel_booking' => 'Confirmation d annulation met a jour le statut et le creneau',
                    'reminder_notification' => 'Rappel envoye au bon moment avec les bonnes informations',
                    default => "Cas nominal reussi pour {$userStory->title}",
                },
                'description' => match ($storyIntent) {
                    'cancel_booking' => 'Verifier qu apres confirmation, le rendez-vous est bien annule et le creneau redevient disponible.',
                    'reminder_notification' => 'Verifier qu un rappel est emis a l heure prevue pour un rendez-vous confirme.',
                    default => 'Verifier que le parcours principal reussit avec des donnees valides.',
                },
                'expected_result' => match ($storyIntent) {
                    'cancel_booking' => 'Le rendez-vous est annule, le creneau est libere et une confirmation claire est affichee.',
                    'reminder_notification' => 'Le rappel est emis au bon moment avec les informations attendues.',
                    default => 'Le parcours principal reussit et le resultat attendu est affiche.',
                },
                'severity' => 'high',
            ];
        }

        return $cases;
    }

    /**
     * Determine severity based on position (first criteria are usually most important)
     *
     * @param int $index
     * @return string
     */
    private function determineSeverity(int $index): string
    {
        if ($index === 0) {
            return 'critical';
        } elseif ($index <= 2) {
            return 'high';
        } elseif ($index <= 5) {
            return 'medium';
        }
        return 'low';
    }

    private function criterionTitle(string $criterion, int $index): string
    {
        $short = trim((string) preg_replace('/\s+/', ' ', $criterion));

        if (strlen($short) > 70) {
            $short = rtrim(substr($short, 0, 67)) . '...';
        }

        return $short;
    }

    private function generateDomainSpecificCases(UserStory $userStory, array $context, string $storyIntent = 'generic'): array
    {
        $text = strtolower(implode(' ', array_filter([
            $userStory->title,
            $userStory->description,
            implode(' ', $context['acceptance_criteria'] ?? []),
            implode(' ', $context['business_rules'] ?? []),
            implode(' ', $context['scenarios'] ?? []),
        ])));

        $cases = [];

        if ($this->containsAny($text, self::PAYMENT_KEYWORDS)) {
            $cases = array_merge($cases, [
                [
                    'name' => 'Carte expiree refuse le paiement',
                    'description' => 'Verifier qu une carte expiree est rejetee et qu aucune commande n est confirmee.',
                    'expected_result' => 'Le paiement est refuse avec un message clair et aucune commande valide n est creee.',
                    'severity' => 'high',
                ],
                [
                    'name' => 'CVV invalide bloque la transaction',
                    'description' => 'Verifier qu un CVV invalide ou incomplet empeche la transaction.',
                    'expected_result' => 'La transaction est bloquee et l utilisateur recoit un message de validation clair.',
                    'severity' => 'high',
                ],
                [
                    'name' => 'Double soumission ne cree pas de paiement en doublon',
                    'description' => 'Verifier qu un double clic ou une nouvelle tentative immediate ne cree pas de commande ou paiement duplique.',
                    'expected_result' => 'Un seul paiement est pris en compte et aucun doublon n est cree.',
                    'severity' => 'high',
                ],
                [
                    'name' => 'Refus du prestataire conserve un etat recuperable',
                    'description' => 'Verifier qu un refus du prestataire de paiement ne confirme pas la commande et laisse le panier recuperable.',
                    'expected_result' => 'La commande reste non confirmee, un message explicite est affiche et les donnees restent coherentes.',
                    'severity' => 'high',
                ],
            ]);
        }

        if ($this->containsAny($text, self::BOOKING_KEYWORDS) && $storyIntent === 'cancel_booking') {
            $cases = array_merge($cases, [
                [
                    'name' => 'Rendez-vous deja annule non annulable une seconde fois',
                    'description' => 'Verifier qu un rendez-vous deja annule ne peut pas etre annule une nouvelle fois depuis un autre ecran ou apres rechargement.',
                    'expected_result' => 'Le systeme bloque la seconde annulation et affiche un etat cohérent avec un message clair.',
                    'severity' => 'high',
                ],
                [
                    'name' => 'Annulation libere de nouveau le creneau',
                    'description' => 'Verifier qu apres annulation reussie d un rendez-vous futur, le creneau redevient disponible pour une nouvelle reservation.',
                    'expected_result' => 'Le rendez-vous est marque annule et le creneau est de nouveau visible comme disponible.',
                    'severity' => 'high',
                ],
                [
                    'name' => 'Rendez-vous passe non annulable',
                    'description' => 'Verifier qu un rendez-vous deja passe ou demarre ne peut plus etre annule par le patient.',
                    'expected_result' => 'L annulation est refusee avec une validation explicite et aucun changement d etat.',
                    'severity' => 'high',
                ],
            ]);
        }

        if ($this->containsAny($text, self::BOOKING_KEYWORDS) && $storyIntent === 'create_booking') {
            $cases = array_merge($cases, [
                [
                    'name' => 'Creneau deja reserve non selectionnable',
                    'description' => 'Verifier qu un creneau reserve entre temps ne peut plus etre confirme par un autre utilisateur.',
                    'expected_result' => 'Le creneau indisponible est bloque et un message clair est affiche.',
                    'severity' => 'high',
                ],
                [
                    'name' => 'Date passee refuse la reservation',
                    'description' => 'Verifier qu une date ou un horaire passe ne peut pas etre reserve.',
                    'expected_result' => 'La reservation est refusee avec une validation explicite.',
                    'severity' => 'medium',
                ],
            ]);
        }

        if ($this->containsAny($text, self::REMINDER_KEYWORDS) && $storyIntent === 'reminder_notification') {
            $cases = array_merge($cases, [
                [
                    'name' => 'Aucun rappel envoye pour un rendez-vous annule avant l echeance',
                    'description' => 'Verifier qu un rendez-vous annule avant l heure prevue n est pas pris en compte lors du passage du scheduler.',
                    'expected_result' => 'Aucun rappel n est envoye pour ce rendez-vous et aucun effet de bord n apparait.',
                    'severity' => 'high',
                ],
                [
                    'name' => 'Contenu du rappel inclut date et instructions d acces',
                    'description' => 'Verifier que le rappel contient les informations utiles a la consultation, notamment la date et les instructions d acces.',
                    'expected_result' => 'Le contenu du rappel est complet, exact et exploitable par le patient.',
                    'severity' => 'high',
                ],
                [
                    'name' => 'Echec du canal de contact journalise sans changer le rendez-vous',
                    'description' => 'Verifier qu un echec email ou SMS est trace sans modifier le statut ni les donnees du rendez-vous.',
                    'expected_result' => 'L echec est journalise et le rendez-vous reste confirme sans notification erronee.',
                    'severity' => 'high',
                ],
            ]);
        }

        return $cases;
    }

    private function generateGapFocusedCases(UserStory $userStory, array $storyContext, array $generationContext, string $storyIntent = 'generic'): array
    {
        $cases = [];
        $focuses = array_values(array_filter(array_map('strval', $generationContext['generation_focus'] ?? [])));

        foreach ($focuses as $focus) {
            $normalized = strtolower($focus);

            if (str_contains($normalized, 'business rule')) {
                foreach (($storyContext['business_rules'] ?? []) as $rule) {
                    $cases[] = [
                        'name' => "Regle metier respectee - {$userStory->title}",
                        'description' => "Verifier explicitement la regle metier suivante: {$rule}",
                        'expected_result' => 'La regle metier est appliquee sans ambiguite et l action interdite est bloquee.',
                        'severity' => 'high',
                    ];
                }
            }

            if (str_contains($normalized, 'scenario')) {
                foreach (($storyContext['scenarios'] ?? []) as $scenario) {
                    $cases[] = [
                        'name' => "Scenario specifique couvre - {$userStory->title}",
                        'description' => "Executer le scenario complementaire suivant: {$scenario}",
                        'expected_result' => 'Le scenario est traite avec le comportement attendu et un retour comprehensible.',
                        'severity' => 'medium',
                    ];
                }
            }

            if (str_contains($normalized, 'notification') && !$this->containsNamedCase($cases, 'Notification coherente')) {
                $cases[] = [
                    'name' => $storyIntent === 'reminder_notification'
                        ? "Notification de rappel coherente pour {$userStory->title}"
                        : "Notification coherente apres succes pour {$userStory->title}",
                    'description' => $storyIntent === 'reminder_notification'
                        ? 'Verifier le contenu, le moment d envoi et l absence de rappel premature ou en doublon.'
                        : 'Verifier le contenu, le moment d envoi et l absence de notification prematuree ou en doublon.',
                    'expected_result' => $storyIntent === 'reminder_notification'
                        ? 'Le rappel est envoye une seule fois au bon moment avec les bonnes donnees.'
                        : 'La notification est envoyee une seule fois, avec les bonnes donnees, uniquement apres succes confirme.',
                    'severity' => 'medium',
                ];
            }

            if (str_contains($normalized, 'integration')) {
                $cases[] = [
                    'name' => "Echec integration externe recupere pour {$userStory->title}",
                    'description' => 'Verifier qu un service externe indisponible ou lent ne laisse pas l operation dans un etat incoherent.',
                    'expected_result' => 'L utilisateur recoit un message clair et aucune validation incorrecte n est enregistree.',
                    'severity' => 'high',
                ];
            }
        }

        return Collection::make($cases)
            ->unique(fn (array $item) => strtolower(trim((string) ($item['name'] ?? ''))))
            ->values()
            ->all();
    }

    private function containsAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function ensureMinimumCoverage(array $testCases, array $criterionCases, UserStory $userStory, array $context, array $generationContext = [], string $storyIntent = 'generic'): array
    {
        $cases = Collection::make($testCases);

        if (count($criterionCases) > 0 && $this->countCriterionCases($cases->all()) < min(3, count($criterionCases))) {
            $cases = $cases->merge(array_slice($criterionCases, 0, min(3, count($criterionCases))));
        }

        $requiredCases = [
            [
                'match' => $storyIntent === 'cancel_booking' ? 'Confirmation d annulation' : 'Cas nominal',
                'factory' => fn () => [
                    'name' => $storyIntent === 'cancel_booking'
                        ? 'Confirmation d annulation met a jour le statut et le creneau'
                        : "Cas nominal reussi pour {$userStory->title}",
                    'description' => $storyIntent === 'cancel_booking'
                        ? 'Verifier qu apres confirmation, le rendez-vous passe au statut annule et que le creneau est libere.'
                        : 'Verifier que le parcours principal aboutit avec des donnees valides.',
                    'expected_result' => $storyIntent === 'cancel_booking'
                        ? 'Le rendez-vous est annule, le creneau redevient disponible et la confirmation est correcte.'
                        : 'Le parcours principal reussit et la confirmation attendue est affichee.',
                    'severity' => 'high',
                ],
            ],
            [
                'match' => $storyIntent === 'cancel_booking' ? 'Annulation refusee si le rendez-vous est deja demarre' : 'Validation des champs',
                'factory' => fn () => [
                    'name' => $storyIntent === 'cancel_booking'
                        ? 'Annulation refusee si le rendez-vous est deja demarre'
                        : "Validation des champs pour {$userStory->title}",
                    'description' => $storyIntent === 'cancel_booking'
                        ? 'Verifier qu un rendez-vous deja demarre ou passe ne peut plus etre annule.'
                        : 'Verifier que les champs obligatoires et les formats invalides sont correctement bloques.',
                    'expected_result' => $storyIntent === 'cancel_booking'
                        ? 'Le systeme bloque l action avec un message clair sans modifier l etat du rendez-vous.'
                        : 'Le systeme bloque les donnees invalides et affiche un message clair.',
                    'severity' => 'high',
                ],
            ],
            [
                'match' => $storyIntent === 'cancel_booking' ? 'Echec technique sans annulation partielle' : 'Gestion des erreurs',
                'factory' => fn () => [
                    'name' => $storyIntent === 'cancel_booking'
                        ? 'Echec technique sans annulation partielle'
                        : "Gestion des erreurs pour {$userStory->title}",
                    'description' => $storyIntent === 'cancel_booking'
                        ? 'Verifier qu une erreur technique pendant l annulation ne laisse ni statut partiellement modifie ni creneau libere a tort.'
                        : 'Verifier que les erreurs techniques ou metier laissent un etat recuperable.',
                    'expected_result' => $storyIntent === 'cancel_booking'
                        ? 'Le rendez-vous conserve son etat initial et aucun effet de bord incoherent n est visible.'
                        : 'Aucune action incorrecte n est validee et un retour comprehensible est affiche.',
                    'severity' => 'high',
                ],
            ],
        ];

        foreach ($requiredCases as $requiredCase) {
            if (!$this->containsCaseName($cases->all(), $requiredCase['match'])) {
                $cases->push($requiredCase['factory']());
            }
        }

        if (($context['dimensions'] ?? []) !== [] && !$this->containsAnyCaseName($cases->all(), [
            'Notifications correctes',
            'Integrite des donnees preservee',
            'Prevention des actions en double',
            'Integrations externes fiables',
            'Date passee refuse la reservation',
            'Rendez-vous passe non annulable',
            'Carte expiree refuse le paiement',
        ])) {
            $extraDimensionCases = $this->generateDimensionCases($userStory, $context, $storyIntent);
            if ($extraDimensionCases !== []) {
                $cases->push($extraDimensionCases[0]);
            }
        }

        if (($generationContext['generation_focus'] ?? []) !== [] && !$this->containsAnyCaseName($cases->all(), [
            'Regle metier respectee',
            'Scenario specifique couvre',
            'Notification coherente',
            'Echec integration externe',
        ])) {
            foreach ($this->generateGapFocusedCases($userStory, $context, $generationContext, $storyIntent) as $gapCase) {
                $cases->push($gapCase);
            }
        }

        return $cases
            ->unique(fn (array $item) => strtolower(trim((string) ($item['name'] ?? ''))))
            ->values()
            ->all();
    }

    private function countCriterionCases(array $cases): int
    {
        return Collection::make($cases)
            ->filter(fn (array $item) => ($item['category'] ?? null) === 'acceptance_criterion')
            ->count();
    }

    private function containsCaseName(array $cases, string $needle): bool
    {
        foreach ($cases as $case) {
            if (str_contains((string) ($case['name'] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsAnyCaseName(array $cases, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($this->containsCaseName($cases, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function detectStoryIntent(UserStory $userStory, array $context): string
    {
        $text = strtolower(implode(' ', array_filter([
            $userStory->title,
            $userStory->description,
            implode(' ', $context['acceptance_criteria'] ?? []),
            implode(' ', $context['business_rules'] ?? []),
            implode(' ', $context['scenarios'] ?? []),
        ])));

        if ($this->containsAny($text, self::REMINDER_KEYWORDS)) {
            return 'reminder_notification';
        }

        if ($this->containsAny($text, self::CANCEL_KEYWORDS) && $this->containsAny($text, self::BOOKING_KEYWORDS)) {
            return 'cancel_booking';
        }

        if ($this->containsAny($text, self::RESCHEDULE_KEYWORDS) && $this->containsAny($text, self::BOOKING_KEYWORDS)) {
            return 'reschedule_booking';
        }

        if ($this->containsAny($text, self::CREATE_KEYWORDS) && $this->containsAny($text, self::BOOKING_KEYWORDS)) {
            return 'create_booking';
        }

        return 'generic';
    }

    private function intentActionLabel(string $storyIntent): string
    {
        return match ($storyIntent) {
            'cancel_booking' => 'annuler le rendez-vous cible',
            'reminder_notification' => 'recevoir le rappel attendu',
            'reschedule_booking' => 'replanifier le rendez-vous cible',
            'create_booking' => 'reserver le rendez-vous cible',
            default => 'realiser l action attendue',
        };
    }

    private function filterCasesByIntent(array $cases, string $storyIntent): array
    {
        if ($storyIntent === 'reminder_notification') {
            $blockedTerms = ['annulation met a jour le statut', 'annulation partielle', 'non annulable', 'annule une seconde fois', 'creneau redevient disponible'];

            return array_values(array_filter($cases, function (array $case) use ($blockedTerms) {
                $text = strtolower(trim(($case['name'] ?? '') . ' ' . ($case['description'] ?? '')));

                foreach ($blockedTerms as $blockedTerm) {
                    if (str_contains($text, $blockedTerm)) {
                        return false;
                    }
                }

                return true;
            }));
        }

        if ($storyIntent !== 'cancel_booking') {
            return $cases;
        }

        $blockedTerms = ['reserve', 'reservation', 'reserver', 'booking confirme', 'payer', 'paiement'];

        return array_values(array_filter($cases, function (array $case) use ($blockedTerms) {
            $text = strtolower(trim(($case['name'] ?? '') . ' ' . ($case['description'] ?? '')));

            foreach ($blockedTerms as $blockedTerm) {
                if (str_contains($text, $blockedTerm)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function removeNearDuplicates(array $cases): array
    {
        $result = [];
        $seenRoots = [];
        $seenFingerprints = [];

        foreach ($cases as $case) {
            $name = strtolower(trim((string) ($case['name'] ?? '')));
            $root = $this->caseSemanticRoot($name);
            $fingerprint = $this->contentFingerprint($case);

            if ($fingerprint !== '' && isset($seenFingerprints[$fingerprint])) {
                continue;
            }

            if ($root !== '' && isset($seenRoots[$root]) && $this->isSameFamilyDuplicate($case, $result)) {
                continue;
            }

            if ($root !== '') {
                $seenRoots[$root] = true;
            }

            if ($fingerprint !== '') {
                $seenFingerprints[$fingerprint] = true;
            }

            $result[] = $case;
        }

        return $result;
    }

    private function caseSemanticRoot(string $name): string
    {
        return match (true) {
            str_contains($name, 'notification') => 'notification',
            str_contains($name, 'cas nominal') => 'happy_path',
            str_contains($name, 'gestion des erreurs') => 'error_handling',
            str_contains($name, 'validation des champs') => 'validation',
            str_contains($name, 'regle metier') || str_contains($name, 'regles metier') => 'business_rules',
            default => preg_replace('/[^a-z0-9]+/', '', $name) ?: '',
        };
    }

    private function containsNamedCase(array $cases, string $needle): bool
    {
        foreach ($cases as $case) {
            if (str_contains((string) ($case['name'] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function removeCasesCoveredByStoryContext(array $cases, array $storyContext): array
    {
        $referenceTexts = array_values(array_filter(array_merge(
            $storyContext['acceptance_criteria'] ?? [],
            $storyContext['business_rules'] ?? [],
            $storyContext['scenarios'] ?? [],
        )));

        return array_values(array_filter($cases, function (array $case) use ($referenceTexts) {
            $name = strtolower(trim((string) ($case['name'] ?? '')));

            if (($case['category'] ?? null) === 'acceptance_criterion') {
                return true;
            }

            $caseText = trim((string) (($case['description'] ?? '') . ' ' . ($case['expected_result'] ?? '')));
            $caseTokens = $this->meaningfulTokens($caseText);

            if (count($caseTokens) < 4) {
                return true;
            }

            foreach ($referenceTexts as $referenceText) {
                $referenceTokens = $this->meaningfulTokens((string) $referenceText);

                if ($referenceTokens === []) {
                    continue;
                }

                $overlap = array_intersect($caseTokens, $referenceTokens);
                $ratio = count($overlap) / max(count($caseTokens), count($referenceTokens), 1);

                if ($ratio >= 0.55) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function meaningfulTokens(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (string $part) => trim($part),
            $parts
        ), fn (string $part) => strlen($part) >= 4 && !in_array($part, [
            'verify', 'verifier', 'resultat', 'attendu', 'systeme', 'story', 'patient',
            'rendez', 'consultation', 'notification', 'correctement', 'coherente',
        ], true))));
    }

    private function contentFingerprint(array $case): string
    {
        $tokens = $this->meaningfulTokens(
            trim((string) (($case['name'] ?? '') . ' ' . ($case['description'] ?? '') . ' ' . ($case['expected_result'] ?? '')))
        );

        sort($tokens);

        return implode('|', array_slice($tokens, 0, 12));
    }

    private function isSameFamilyDuplicate(array $case, array $existingCases): bool
    {
        $candidateTokens = $this->meaningfulTokens(
            trim((string) (($case['name'] ?? '') . ' ' . ($case['description'] ?? '')))
        );

        foreach ($existingCases as $existingCase) {
            $existingTokens = $this->meaningfulTokens(
                trim((string) (($existingCase['name'] ?? '') . ' ' . ($existingCase['description'] ?? '')))
            );

            if ($candidateTokens === [] || $existingTokens === []) {
                continue;
            }

            $overlap = array_intersect($candidateTokens, $existingTokens);
            $ratio = count($overlap) / max(count($candidateTokens), count($existingTokens), 1);

            if ($ratio >= 0.6) {
                return true;
            }
        }

        return false;
    }
}
