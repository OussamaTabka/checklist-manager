# Rapport d'investigation — Cas sans inputs (python-gen)

**Date :** 2026-06-07  
**Cible :** https://www.saucedemo.com  
**Modèle :** gpt-5.4 via codex.sale  
**Pipeline :** `generateScript.js` (python-gen)

---

## Résultats de génération

| # | Titre | API appelée | JSON valide | `required_inputs` vide | Sélecteurs sémantiques | Assertions pertinentes | Exécution réelle | Verdict |
|---|-------|:-----------:|:-----------:|:---------------------:|:----------------------:|:----------------------:|:----------------:|---------|
| 1 | 404 – Page d'erreur       | ✅ | ✅ | ✅ | ✅ | ✅ | ⛔ voir §3 | ✅ BON |
| 2 | Images – Chargement       | ✅ | ✅ | ✅ | ✅ | ⚠️ bug regex | ⛔ voir §3 | ⚠️ PARTIEL |
| 3 | Console – Zéro erreur     | ✅ | ✅ | ✅ | ✅ | ❌ hallucination | ⛔ voir §3 | ❌ CASSÉ |
| 4 | Accessibilité – alt vide  | ✅ | ✅ | ✅ | ✅ | ⚠️ bug regex | ⛔ voir §3 | ❌ CASSÉ |
| 5 | Responsive – 375×667      | ✅ | ✅ | ✅ | ✅ | ✅ | ⛔ voir §3 | ✅ EXCELLENT |
| 6 | SEO – title + lang        | ✅ | ✅ | ✅ | ✅ | ⚠️ `use_regex` invalide | ⛔ voir §3 | ⚠️ PARTIEL |

---

## Section 1 — Analyse détaillée par cas

### Cas 1 — 404 page

**Fichier :** `raw-response-1.txt`  
**Script Python généré :**

```python
await page.goto('/url-qui-nexiste-pas')
error_indicator = page.get_by_text('404').first()
not_found_indicator = page.get_by_text('Not Found').first()
...
if await error_indicator.is_visible() or await not_found_indicator.is_visible() ...:
    await expect(error_indicator).to_be_visible()
else:
    if await login_button.is_visible():
        await expect(login_button).to_be_visible()
    elif await username_field.is_visible():
        await expect(username_field).to_be_visible()
    else:
        await expect(password_field).to_be_visible()
```

**Évaluation :**
- Syntaxe Python async : ✅ valide
- Sélecteurs : `get_by_text`, `get_by_role`, `get_by_label` — 100 % sémantiques ✅
- Assertions : branche conditionnelle qui gère les deux issues (page 404 OU redirection login) ✅
- `required_inputs` : `[]` ✅
- `coverage_type` : `navigation` (correct) ✅
- `human_readable_steps` : 5 phrases claires en français ✅

**Note comportementale :** saucedemo.com ne renvoie pas de page 404 — il redirige vers la page login pour tout chemin inconnu. La branche `else` du script (vérifie le bouton Login) est donc celle qui s'exécute, ce qui est correct.

---

### Cas 2 — Chargement des images

**Script généré (extrait problématique) :**

```python
for image in results:
    await expect(page.locator(f'img').nth(image['index'])).to_have_attribute('src', r'.+')
    await expect(page.locator(f'img').nth(image['index'])).to_have_js_property('complete', True)
    await expect(page.locator(f'img').nth(image['index'])).not_to_have_js_property('naturalWidth', 0)
```

**Bug identifié :** `to_have_attribute('src', r'.+')` passe la chaîne littérale `'.+'` au lieu d'un pattern compilé. L'API Playwright Python attend `re.compile(r'.+')` pour activer le matching regex. En l'état, le test vérifie que `src` vaut exactement la chaîne `.+`, ce qui est faux pour toute image réelle.

**Cause probable :** aucun few-shot pour les cas DOM-inspection dans `scriptPrompt.ts`. Le modèle connaît la mécanique Playwright mais applique la syntaxe regex de JavaScript (`/pattern/`) transposée en Python raw-string, en omettant `re.compile`.

---

### Cas 3 — Console sans erreurs

**Script généré (extrait problématique) :**

```python
page.on('console', _handle_console)
await page.goto('https://www.saucedemo.com', wait_until='load')
...
error_locator = page.locator('#__console_error_count__')
await error_locator.evaluate("(el, value) => { el.setAttribute('data-count', String(value)); }", len(console_errors))
await expect(error_locator).to_have_attribute('data-count', '0')
```

**Problème :** `#__console_error_count__` est un **élément DOM halluciné** — il n'existe pas sur saucedemo.com. Le modèle avait raison sur l'usage de `page.on('console', ...)` mais, faute de savoir asserter une variable Python pure avec `expect()`, il a inventé un élément fantôme dans lequel stocker le décompte.

**Cause profonde :** `expect()` dans Playwright est conçu pour les assertions DOM. Il n'existe pas d'équivalent natif `expect(python_value).to_equal(0)`. Le modèle a tenté de contourner cette limite en fabriquant un nœud DOM inexistant. Un few-shot console-monitoring avec une assertion Python native (`assert len(console_errors) == 0, ...`) résoudrait le problème — la contrainte « pas de `assert` » est dans le prompt mais ne s'applique pas aux variables non-DOM.

---

### Cas 4 — Accessibilité alt

**Script généré (extrait problématique) :**

```python
for i in range(count):
    image = images.nth(i)
    await expect(image).to_be_visible()
    alt_value = await image.get_attribute('alt')          # ← jamais utilisé
    await expect(page.locator(f'img:nth-of-type({i + 1})')).to_have_attribute('alt', '.+')  # ← regex bug
```

**Deux défauts :**
1. `alt_value` est récupéré mais jamais asserté — variable morte.
2. `to_have_attribute('alt', '.+')` : même bug regex que le cas 2.

En l'état, le test vérifie uniquement que chaque `<img>` est visible — il ne vérifie pas réellement que `alt` est non-vide.

---

### Cas 5 — Responsive mobile 375×667

**Script généré (complet) :**

```python
await page.set_viewport_size({"width": 375, "height": 667})
await page.goto('/')
username = page.get_by_label('Username')
password = page.get_by_label('Password')
login_button = page.get_by_role('button', name='Login')
login_text = page.get_by_text('Login')
await expect(username).to_be_visible()
await expect(password).to_be_visible()
await expect(login_button).to_be_visible()
await expect(login_text).to_be_visible()
username_box = await username.bounding_box()
...
if username_box['x'] + username_box['width'] > 375:
    raise Exception('Username field overflows horizontally in mobile viewport')
if body_box['width'] > 375:
    raise Exception('Page body overflows horizontally in mobile viewport')
```

**Évaluation :**
- `page.set_viewport_size(...)` : API correcte ✅
- Sélecteurs sémantiques : `get_by_label`, `get_by_role`, `get_by_text` ✅
- `expect()` pour les visibilités ✅
- Vérification de débordement via `bounding_box()` : approche correcte ✅
- Assertions géométriques via `raise Exception` : acceptable pour les checks de layout (non-DOM) ✅
- `required_inputs : []` ✅
- 8 `human_readable_steps` précis ✅

**C'est le script le plus complet et le plus correct de la série.**

---

### Cas 6 — Métadonnées SEO

**Script généré :**

```python
await expect(page).to_have_title(".+", use_regex=True)
await expect(page.get_by_text('Swag Labs')).to_be_visible()
lang_value = await page.evaluate("() => document.documentElement.getAttribute('lang')")
await expect(page.locator('html[lang]')).to_be_visible()
await expect(page.locator('html')).to_have_attribute('lang', '.+', use_regex=True)
```

**Bug :** `use_regex=True` n'est **pas un paramètre valide** de `to_have_title()` ni de `to_have_attribute()` dans Playwright Python. Ces méthodes acceptent un `re.Pattern` directement en valeur. L'appel lèverait un `TypeError` à l'exécution.

**Correction minimale :**
```python
import re  # ← interdit par le prompt, mais nécessaire
await expect(page).to_have_title(re.compile(r".+"))
await expect(page.locator('html')).to_have_attribute('lang', re.compile(r".+"))
```

**Autre aspect :** l'assertion `await expect(page.locator('html[lang]')).to_be_visible()` vérifie que `<html>` possède un attribut `lang` mais `<html>` est toujours « visible » au sens DOM. Si saucedemo.com n'a pas de `lang`, le locator `html[lang]` ne matcherait aucun élément et l'assertion échouerait correctement — ce qui constitue un vrai résultat de test.

---

## Section 2 — Blocage pipeline (critique)

> **Tous les 6 cas ont retourné `LLMProviderError: OpenAI returned raw Playwright code instead of JSON`.**

Le modèle a produit un JSON valide dans les 6 cas. Le blocage vient du guard `looksLikeRawPlaywrightCode` dans `openaiProvider.js` :

```javascript
function looksLikeRawPlaywrightCode(text) {
    return /(?:...|await\s+page\.|page\.(?:goto|click|fill|locator|...))/
           .test(normalized)
}
```

Ce guard est appliqué à la **réponse entière** avant tout parsing JSON. Or, le `python_script_body` contient inévitablement `await page.goto(...)`, `page.locator(...)` etc. Le regex matche à l'intérieur de la chaîne JSON — c'est un **faux positif systématique** qui rend la totalité du pipeline `generateScript.js` non fonctionnelle, **indépendamment du type de cas (transactionnel ou no-input).**

**Correction ciblée (hors périmètre de cette investigation) :**
```javascript
// Appliquer le guard UNIQUEMENT si la réponse ne commence pas par '{'
if (!normalized.startsWith('{') && looksLikeRawPlaywrightCode(normalized)) { ... }
```

---

## Section 3 — Exécution réelle

**Aucune exécution n'a été possible.** Deux bloqueurs indépendants :

1. **Guard faux positif** (`looksLikeRawPlaywrightCode`) — bloque la sortie de `generateScript.js` avant même d'écrire les runspecs.
2. **`wrapper.py` absent** — `playwright-agent/../python-runner/wrapper.py` (chemin attendu par `runLocal.js`) n'existe pas dans le dépôt. Sans ce fichier, `runLocal.js` ne peut pas spawner le processus Python.

Aucune des colonnes `execution_reussie` du tableau récapitulatif ne peut être remplie dans l'état actuel du dépôt.

---

## Section 4 — Conclusion

**L'agent gère-t-il les cas sans inputs aussi bien que les cas transactionnels ?**

Du point de vue du modèle LLM seul : **oui, avec nuances**. Dans les 6 cas, la réponse est un JSON valide, `required_inputs` est correctement vide, la `coverage_type` est cohérente, et les sélecteurs sont sémantiques. Sur 6 scripts :
- **2 sont techniquement corrects** (cas 1, cas 5),
- **2 ont un bug regex mineur identique** (cas 2, cas 4),
- **1 a un problème d'API Playwright** (`use_regex=True`, cas 6),
- **1 hallucine un élément DOM** pour contourner une limite de `expect()` (cas 3).

Le déficit principal n'est pas la compréhension des cas sans inputs mais l'absence de **few-shot pour les patterns spéciaux** (DOM-inspection, responsive, console) :

---

### Ajout minimal recommandé dans `scriptPrompt.ts`

Insérer un quatrième exemple dans `fewShotSection()`, après l'exemple 3 (search), ciblant trois patterns manquants :

````typescript
const example4Output = safeJson({
  execution_profile: {
    intent_summary: "Vérifier que la page expose un titre non vide et un attribut lang sur <html>.",
    coverage_type: 'generic_ui',
    preconditions: ["La page est accessible à l'URL de base.", "Aucun identifiant n'est requis."],
    required_inputs: [],
    expected_observations: [
      "Le titre de la page (document.title) est non vide.",
      "La balise <html> possède un attribut lang non vide.",
    ],
  },
  python_script_body: [
    // NOTE: regex = re.compile(...), pas use_regex=True
    "import re",           // ← interdit par schema, utiliser locator à la place
    // Alternative sans import :
    "await expect(page).to_have_title(re.compile(r'.+'))",
    "lang = await page.evaluate('() => document.documentElement.lang')",
    "assert lang, f'Missing lang attribute on <html>, got: {repr(lang)}'",
  ].join('\n'),
  human_readable_steps: [
    "Vérifier que le titre de la page est non vide via expect(page).to_have_title()",
    "Lire document.documentElement.lang via page.evaluate()",
    "Asserter que lang est non vide",
  ],
})
````

> **Note importante :** la contrainte `python_script_body` interdit `import`, ce qui empêche `re.compile`. Le prompt doit être précisé : **pour les patterns regex dans `to_have_attribute` / `to_have_title`, utiliser `locator.evaluate(...)` + assertion Python native au lieu de `re.compile`**.

Ajouter également dans les règles `=== RÈGLES POUR python_script_body ===` :

```
7. Pour vérifier un pattern regex sur un attribut DOM, utiliser page.evaluate() pour lire la valeur
   puis assert/raise, car les méthodes expect() ne supportent pas use_regex=True — elles acceptent
   uniquement un re.Pattern (nécessite import re, interdit). Exemple :
     val = await page.evaluate("() => document.documentElement.lang")
     if not val or val.strip() == '':
         raise Exception("lang attribute is missing or empty")
8. Pour les compteurs non-DOM (erreurs console, count d'images), stocker dans une liste Python
   et utiliser raise Exception() ou assert pour l'assertion finale — NE PAS inventer un élément
   DOM inexistant (#id-fantome) pour y stocker la valeur.
```

Ces deux ajouts (exemple 4 + règles 7-8) couvrent les cas console, accessibilité, SEO et responsive sans réécrire le prompt.
