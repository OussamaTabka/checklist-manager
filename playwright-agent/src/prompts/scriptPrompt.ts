export type GeneratorChecklistItem = {
  external_id: number
  test_case_title: string
  test_case_description?: string
  test_case_text: string
  base_url: string
  use_auth?: boolean
  environment_name?: string
  notes?: string
  priority?: string
  criticality?: string
  current_status?: string
  target_type?: string
  source_app?: string
  provided_inputs?: Record<string, unknown>
  expected_result?: Record<string, unknown>
  user_story?: {
    id?: number
    story_id?: string
    title?: string
    as_a?: string
    i_want_that?: string
    so_that?: string
    acceptance_criteria?: string
    business_rules?: string[]
  } | null
  checklist_business_rules?: string[]
}

function safeJson(value: unknown): string {
  return JSON.stringify(value ?? {}, null, 2)
}

function fewShotSection(): string {
  const example1Output = safeJson({
    execution_profile: {
      intent_summary: "Vérifier qu'un utilisateur valide peut se connecter et accéder au tableau de bord.",
      coverage_type: 'auth_login',
      preconditions: [
        "La page de connexion doit être accessible à l'URL de base.",
        'Des identifiants valides doivent être fournis dans inputs.',
      ],
      required_inputs: [
        { key: 'email', label: 'Email', kind: 'email', required: true, description: 'Adresse email de connexion.' },
        { key: 'password', label: 'Mot de passe', kind: 'password', required: true, description: 'Mot de passe utilisateur.' },
      ],
      expected_observations: [
        "L'utilisateur est redirigé vers le tableau de bord après la connexion.",
        'Le contenu authentifié devient visible.',
      ],
    },
    python_script_body: [
      "await page.get_by_label('Email').fill(inputs['email'])",
      "await page.get_by_label('Password').fill(inputs['password'])",
      "await page.get_by_role('button', name='Login').click()",
      "await page.wait_for_url('**/dashboard**', timeout=20000)",
      "await expect(page.get_by_role('heading')).to_be_visible()",
    ].join('\n'),
    human_readable_steps: [
      "Saisir l'email dans le champ Email",
      'Saisir le mot de passe dans le champ Password',
      'Cliquer sur le bouton Login',
      'Attendre la redirection vers le tableau de bord',
      "Vérifier que le titre de la page d'accueil est visible",
    ],
  })

  // NEGATIVE test: locked-out user — assertions verify the BLOCKING outcome
  const example2Output = safeJson({
    execution_profile: {
      intent_summary: "Vérifier qu'un utilisateur bloqué ne peut pas se connecter et voit un message d'erreur.",
      coverage_type: 'auth_login',
      preconditions: [
        'La page de connexion doit être accessible.',
        'Un compte bloqué doit exister avec les identifiants fournis.',
      ],
      required_inputs: [
        { key: 'email', label: 'Email', kind: 'email', required: true, description: "Email du compte bloqué." },
        { key: 'password', label: 'Mot de passe', kind: 'password', required: true, description: 'Mot de passe du compte bloqué.' },
      ],
      expected_observations: [
        "Un message d'erreur apparaît indiquant que le compte est bloqué.",
        "L'utilisateur reste sur la page de connexion — aucune redirection vers le dashboard.",
      ],
    },
    python_script_body: [
      "await page.get_by_label('Email').fill(inputs['email'])",
      "await page.get_by_label('Password').fill(inputs['password'])",
      "await page.get_by_role('button', name='Login').click()",
      "await expect(page.locator('[role=\"alert\"]')).to_be_visible()",
      "await expect(page.get_by_role('button', name='Login')).to_be_visible()",
    ].join('\n'),
    human_readable_steps: [
      "Saisir l'email du compte bloqué",
      'Saisir le mot de passe',
      'Cliquer sur le bouton Login',
      "Vérifier que le message d'erreur est affiché",
      "Vérifier que le bouton Login est encore présent (l'utilisateur n'a pas été redirigé)",
    ],
  })

  const example3Output = safeJson({
    execution_profile: {
      intent_summary: 'Vérifier que la recherche affiche des résultats correspondant à la requête saisie.',
      coverage_type: 'search_filter',
      preconditions: [
        'La page contenant le champ de recherche est accessible.',
        'Une requête de recherche est fournie dans inputs.',
      ],
      required_inputs: [
        { key: 'search_query', label: 'Requête de recherche', kind: 'search', required: true, description: 'Terme à saisir dans la barre de recherche.' },
      ],
      expected_observations: [
        'Des résultats correspondant à la requête sont affichés.',
        'La zone de résultats devient visible après soumission.',
      ],
    },
    python_script_body: [
      "search_box = page.get_by_role('searchbox')",
      "await search_box.fill(inputs['search_query'])",
      "await search_box.press('Enter')",
      "results = page.locator('[data-testid=\"search-results\"], .search-results, ul[aria-label], main ol')",
      "await expect(results.first()).to_be_visible()",
    ].join('\n'),
    human_readable_steps: [
      'Localiser le champ de recherche',
      'Saisir la requête dans le champ de recherche',
      'Appuyer sur Entrée pour lancer la recherche',
      'Attendre que la zone de résultats soit présente',
      'Vérifier que des résultats sont visibles',
    ],
  })

  return [
    '=== FEW-SHOT EXAMPLES ===',
    '',
    '--- Exemple 1 : Connexion valide (test POSITIF) ---',
    'Entrée :',
    '  test_case_title: "L\'utilisateur peut se connecter avec des identifiants valides"',
    '  base_url: "https://example.com"',
    '  provided_inputs: {"email": "user@example.com", "password": "secret123"}',
    '',
    'Sortie JSON attendue :',
    '```json',
    example1Output,
    '```',
    '',
    '--- Exemple 2 : Utilisateur bloqué (test NÉGATIF) ---',
    'ATTENTION : Ceci est un test NÉGATIF. Le comportement ATTENDU est que la connexion ÉCHOUE.',
    'Les assertions DOIVENT vérifier l\'échec (erreur visible, utilisateur PAS redirigé), PAS le succès.',
    'Entrée :',
    '  test_case_title: "Un utilisateur bloqué ne peut pas se connecter"',
    '  base_url: "https://example.com"',
    '  provided_inputs: {"email": "locked@example.com", "password": "anypassword"}',
    '',
    'Sortie JSON attendue :',
    '```json',
    example2Output,
    '```',
    '',
    '--- Exemple 3 : Recherche et filtrage ---',
    'Entrée :',
    '  test_case_title: "L\'utilisateur peut rechercher des produits"',
    '  base_url: "https://shop.example.com"',
    '  provided_inputs: {"search_query": "laptop"}',
    '',
    'Sortie JSON attendue :',
    '```json',
    example3Output,
    '```',
  ].join('\n')
}

export function buildScriptPrompt(item: GeneratorChecklistItem): string {
  return [
    'Tu es un ingénieur QA senior spécialisé en automatisation Playwright Python.',
    'Produis exactement UN objet JSON — sans balises markdown, sans explication, uniquement le JSON.',
    '',
    '=== FORMAT DE SORTIE ===',
    "Retourne un objet JSON avec EXACTEMENT ces trois clés au niveau racine :",
    '  1. "execution_profile"    — objet décrivant l\'intention du test et les entrées requises',
    '  2. "python_script_body"   — chaîne : le corps d\'une fonction async Playwright',
    '  3. "human_readable_steps" — tableau de chaînes : une phrase par étape, en français',
    '',
    '=== STRUCTURE DE execution_profile ===',
    '{',
    '  "intent_summary": string (1 phrase décrivant l\'objectif du test),',
    '  "coverage_type": string (un parmi : auth_login, search_filter, form_interaction, validation,',
    '                   table_listing, upload, modal_dialog, navigation, button_action,',
    '                   redirection, feedback_message, generic_ui),',
    '  "preconditions": string[] (conditions qui doivent être vraies avant le test),',
    '  "required_inputs": [{ "key": string, "label": string,',
    '                        "kind": "text"|"email"|"password"|"search"|"file"|"textarea",',
    '                        "required": boolean, "description": string }],',
    '  "expected_observations": string[] (ce qui doit être observable si le test réussit)',
    '}',
    '',
    '=== RÈGLES POUR python_script_body ===',
    'Le corps est le CONTENU de cette fonction (sans le "def" ni aucun "import") :',
    '  async def __script__(page, expect, inputs):',
    '      <ton code ici>',
    '',
    'Variables disponibles :',
    '  - page    : Page Playwright, déjà naviguée vers base_url',
    '  - expect  : depuis playwright.async_api (à utiliser pour toutes les assertions)',
    '  - inputs  : dict des valeurs de données de test, ex: inputs["email"]',
    '',
    'RÈGLES OBLIGATOIRES :',
    '  1. Utilise UNIQUEMENT ces stratégies de locateur Playwright (par ordre de préférence) :',
    '       page.get_by_role(...)  > page.get_by_label(...)  > page.get_by_text(...)',
    '       > page.get_by_test_id(...)  > page.locator(css)  [CSS en dernier recours uniquement]',
    '  2. TOUTES les assertions DOIVENT utiliser expect() :',
    '       await expect(locator).to_be_visible()',
    '     NE PAS utiliser l\'instruction Python "assert".',
    '  3. Ne PAS importer quoi que ce soit. Ne PAS utiliser os, sys, subprocess, open() ni I/O fichier.',
    '  4. La page est pré-naviguée vers base_url. Tu peux naviguer vers des sous-chemins :',
    '       await page.goto("/sous-chemin")',
    '  5. Utilise inputs["cle"] pour accéder aux valeurs de données de test.',
    '     Si une valeur n\'est pas dans inputs, utilise une valeur de secours raisonnable.',
    '  6. Si provided_inputs est vide, deduis les valeurs par defaut depuis la user story,',
    '     les regles metier et le test_case_text (sans inventer de secrets).',
    '     Priorite absolue: si un compte est explicitement mentionne (ex: locked_out_user),',
    '     utilise ce compte explicitement plutot qu\'une valeur generique.',
    '',
    '=== CRITICAL : SÉMANTIQUE DES ASSERTIONS ===',
    'LE TEST RÉUSSIT QUAND LE COMPORTEMENT OBSERVÉ CORRESPOND AU COMPORTEMENT ATTENDU.',
    '',
    'Pour les tests POSITIFS (connexion réussie, formulaire soumis, données affichées) :',
    '  → Asserter que l\'état de succès est visible (redirection effectuée, contenu apparu).',
    '',
    'Pour les tests NÉGATIFS (compte bloqué, identifiants invalides, erreur de validation) :',
    '  → NE PAS asserter que l\'action a réussi.',
    '  → ASSERTER LE COMPORTEMENT DE BLOCAGE : message d\'erreur visible, utilisateur toujours',
    '    sur la page courante, bouton de connexion encore présent, formulaire NON soumis.',
    '  → Exemple : si le test est "un utilisateur bloqué ne peut pas se connecter", asserter :',
    '      await expect(page.locator(\'[role="alert"]\')).to_be_visible()  # erreur affichée',
    '      await expect(page.get_by_role("button", name="Login")).to_be_visible()  # pas redirigé',
    '',
    '=== RÈGLES POUR human_readable_steps ===',
    '  - Une phrase courte par étape logique du script (en français).',
    '  - Inclure les étapes d\'action ET les étapes d\'assertion.',
    '  - Exemple : ["Saisir l\'email", "Cliquer sur Login", "Vérifier la redirection"]',
    '',
    fewShotSection(),
    '',
    '=== ITEM DE CHECKLIST À AUTOMATISER ===',
    `test_case_title: ${item.test_case_title}`,
    `test_case_description: ${item.test_case_description ?? ''}`,
    `test_case_text: ${item.test_case_text}`,
    `base_url: ${item.base_url}`,
    `notes: ${item.notes ?? ''}`,
    `priority: ${item.priority ?? ''}`,
    `criticality: ${item.criticality ?? ''}`,
    `current_status: ${item.current_status ?? ''}`,
    'user_story:',
    '```json',
    safeJson(item.user_story ?? {}),
    '```',
    'checklist_business_rules:',
    '```json',
    safeJson(item.checklist_business_rules ?? []),
    '```',
    'provided_inputs:',
    '```json',
    safeJson(item.provided_inputs ?? {}),
    '```',
    'expected_result:',
    '```json',
    safeJson(item.expected_result ?? {}),
    '```',
    '',
    '=== RAPPEL FINAL ===',
    'Retourne UNIQUEMENT l\'objet JSON. Pas de markdown. Pas d\'explication.',
    'Le JSON doit avoir exactement 3 clés : execution_profile, python_script_body, human_readable_steps.',
  ].join('\n')
}
