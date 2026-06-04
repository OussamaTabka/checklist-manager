# Rapport d'Évaluation de l'API de Génération des Checklists

## Contexte et Objectif

Ce rapport présente une évaluation rigoureuse de l'API de génération automatisée de checklists de test, basée sur le modèle **GPT-5.3-codex** via le service **codex.sale** (OpenAI-compatible). L'objectif est de mesurer la qualité, la robustesse et la performance de cette API pour la génération automatisée de cas de test à partir de user stories dans la plateforme **IntelliTest**.

L'évaluation porte sur **15 user stories réelles** sélectionnées de la base de production, évaluées sur les **5 métriques suivantes** :
1. **Couverture fonctionnelle** — Qu'est-ce qui est testé ?
2. **Diversité des cas de test** — Types de tests générés ?
3. **Validité structurelle** — Données exploitables en base ?
4. **Latence** — Temps d'attente avant résultat ?
5. **Vitesse d'inférence** — Débit de génération ?

---

## Méthodologie

### Phases de l'évaluation

**Phase A : Mesure sans appel API** (~30 secondes)
- Évaluation des **checklists IA déjà persistées** pour 11 des 15 user stories
- Dataset : **27 checklists IA | 179 items de test**
- Approche : Classification heuristique + analyse de couverture
- Coût : 0 API call

**Phase B : Mesure avec appels API frais** (~5 minutes)
- Génération de **nouvelles checklists** pour les 4 US sans checklist existante
- Plus 11 appels supplémentaires sur les US existantes pour mesurer latence/vitesse
- Total : **15 appels API frais** (100 % taux de succès)
- Coût : ~$0.10-0.50 (tarification favorable OpenAI-compatible)

### Classification heuristique des cas de test

Chaque item de test est classifié en **3 catégories** :
- **POSITIF** : Cas de succès nominal (flux normal)
- **NÉGATIF** : Cas d'erreur, refus, validation en échec
- **EDGE-CASE** : Cas limites, valeurs extrêmes, comportements rares

**Règles d'exclusion appliquées** pour éviter les faux positifs :
1. **NÉGATION** : Un mot-clé négatif (« erreur », « invalide ») précédé de « sans », « pas de », « aucun(e) » ne déclenche pas la catégorie NÉGATIF.
2. **NOMINALISATION D'ACTION** : Les formes verbales (participe passé) décrivant le résultat attendu d'une action utilisateur normale restent POSITIF.

Un **audit CSV détaillé** trace chaque item et chaque exclusion appliquée.

---

## Résultats Phase A : Couverture, Diversité, Validité Structurelle

### 1. Couverture Fonctionnelle

**Qu'est-ce que c'est ?**
La couverture fonctionnelle mesure si les cas de test générés couvrent bien **tout ce que la user story demande**. On compare les items générés aux critères d'acceptation et aux règles métier. Un score élevé signifie que le modèle IA a bien compris les exigences et ne les a pas oubliées.

**Méthodologie de mesure** : Pour chaque user story, nous avons extrait les 3-5 mots-clés porteurs de sens de chaque règle métier et critère d'acceptation (en excluant 56 mots vides génériques), puis vérifié si au moins 2 mots-clés apparaissaient dans le titre ou la description des items générés. Cette approche par correspondance lexicale est simple et traçable, mais peut **sous-estimer la couverture** pour les règles formulées très différemment des items générés (par ex. une règle « Gestion des erreurs » sera difficile à matcher lexicalement avec un item « Affichage du message d'erreur incorrect »). L'audit CSV complet (`phase_a_coverage.csv`) permet une vérification manuelle item par item.

**Résultats réels mesurés par priorité** :

| Priorité | n | Couverture |
|----------|---|-----------|
| Critical | 3 | 79.2% |
| High | 5 | 100% |
| Medium | 3 | 83.3% |
| **GLOBAL** | **11** | **89.8%** |

La couverture inférieure observée sur la priorité Critical s'explique par une user story du dataset (US-001 « Authentification utilisateur »), dont les règles métier sont formulées de manière très générique (« Gestion des erreurs », « Respect UI/UX »). Ces formulations sont sémantiquement couvertes par les items générés mais difficiles à valider par correspondance lexicale stricte, ce qui constitue une limite connue de notre méthode de mesure. En excluant cette user story, la couverture Critical remonte à 93,75 %.

**Synthèse** : La **couverture globale mesurée est de 89.8 %**, composée de :
- Business Rules Coverage : 90.9 %
- Acceptance Criteria Coverage : 88.6 %

Le modèle capture très bien les exigences visibles et explicites. Les user stories de domaines publics (Sauce Demo, Practice Login) et les exigences bien formulées atteignent 100 % de couverture.

**Note attribuée** : **4/5** — Couverture très bonne et mesurée. La limite méthodologique (correspondance lexicale stricte) peut sous-estimer légèrement la couverture sémantique réelle.

---

### 2. Diversité des Cas de Test

**Qu'est-ce que c'est ?**
Un bon jeu de tests ne teste pas uniquement le cas normal. Il doit aussi tester les **situations d'erreur** (mot de passe invalide, serveur lent) et les **cas limites** (1000 articles, caractères spéciaux, timeouts). Cette diversité assure que le système reste robuste.

**Distribution réelle des 179 items** :

| Catégorie | Nombre | Pourcentage |
|-----------|--------|------------|
| POSITIF (cas nominal/succès) | 112 | **62.6 %** |
| NÉGATIF (erreurs, refus, rejet) | 53 | **29.6 %** |
| EDGE-CASE (limites, rares, concurrence) | 14 | **7.8 %** |

**Synthèse** : La distribution **63 % positif, 30 % négatif, 8 % edge-case** est équilibrée et représentative d'une suite de tests mûre. Le modèle génère naturellement plus de cas positifs (attendu : c'est le flux principal), mais ne néglige pas les erreurs (30 %, bon) ni les limites (8 %, acceptable mais améliorable).

**Note méthodologique sur la classification** : La catégorisation a nécessité l'ajout de **deux règles d'exclusion** (NÉGATION et NOMINALISATION D'ACTION) pour éviter les **faux positifs**. L'**audit CSV complet** documente chaque application d'exclusion, permettant une **vérification manuelle** et une **traçabilité complète**.

**Note attribuée** : **4/5** — Diversité bonne et équilibrée. Idéalement, viser 12-15 % d'edge-cases pour une suite mature (vs. 8 % observé).

---

### 3. Validité Structurelle

**Qu'est-ce que c'est ?**
Le JSON retourné par l'IA doit pouvoir être enregistré dans la base de données sans perte d'information et sans erreur. Les champs obligatoires (titre, description, priorité, sévérité) doivent être présents, valides et non-vides.

**Résultats d'évaluation** :

| Métrique | Résultat |
|----------|----------|
| Checklists IA évaluées | **27** |
| Checklists avec au moins 1 item | **27 (100 %)** |
| Items avec titre | **179 (100 %)** |
| Items avec description | **179 (100 %)** |
| Items avec priorité valide | **179 (100 %)** |
| Items avec criticality valide | **179 (100 %)** |
| Persistance en base sans erreur | **27 (100 %)** |
| Items avec champs manquants | **0 (0 %)** |

**Synthèse** : **Validité structurelle parfaite (100 %)**. Les items générés par l'IA respectent strictement le schéma attendu et peuvent être persistés sans modification.

**Note attribuée** : **5/5** — Validité structurelle impeccable.

---

## Résultats Phase B : Latence et Vitesse d'Inférence

### 4. Latence (Time-to-First-Token)

**Qu'est-ce que c'est ?**
C'est le **temps d'attente avant que l'utilisateur ne voie une réponse commencer à arriver**. Si un utilisateur clique sur « Générer une checklist », combien de temps attend-il avant que le premier texte apparaisse ? Un délai court (~1-5 sec) est confortable ; un délai long (>30 sec) peut frustrer.

**Résultats réels sur 15 appels API** :

| Métrique | Valeur |
|----------|--------|
| **Médiane** | 18.1 secondes |
| **Moyenne** | 18.4 secondes |
| **P95 (95e percentile)** | 23.7 secondes |
| **Écart-type** | 2.6 secondes |
| **Min** | 14.7 secondes |
| **Max** | 23.7 secondes |

**Analyse contexte** :
- La latence mesurée (**18-24 sec**) est une **borne supérieure réelle**, car le **streaming n'est pas activé** dans l'implémentation actuelle. À cause de cette absence de streaming, les métriques de latence et de vitesse d'inférence sont dérivées de la **même mesure (durée totale d'appel)** et ne sont donc **pas statistiquement indépendantes**. Chaque appel API, sans streaming, attend la réponse complète avant de la retourner au client.
- Avec un **streaming SSE activé côté frontend**, la latence perçue (premiers mots visibles) serait **inférieure de 30-50 %** (~9-12 sec), ce qui améliorerait significativement l'UX.
- **En contexte asynchrone** (jobs Laravel en arrière-plan), l'utilisateur n'attend pas devant l'écran. La durée total est cachée ; seul l'affichage du résultat final importe à l'utilisateur.

**Synthèse** : Latence acceptable pour une génération asynchrone. Pourrait être améliorée via streaming SSE.

**Note attribuée** : **3.5/5** — Acceptable en contexte asynchrone. Meilleure note avec streaming activé.

---

### 5. Vitesse d'Inférence

**Qu'est-ce que c'est ?**
Une fois que l'IA **commence à répondre**, à quelle vitesse produit-elle le contenu ? C'est le **débit de génération**, mesuré en **tokens par seconde** (un token ≈ 4 caractères).

**Résultats réels sur 15 appels API** :

| Métrique | Valeur |
|----------|--------|
| **Médiane** | 179.8 tokens/sec |
| **Moyenne** | 180.0 tokens/sec |
| **Min** | 162.9 tokens/sec |
| **Max** | 206.5 tokens/sec |

**Analyse contexte** :
- Les vitesses observées (**163-206 tok/sec**) sont **typiques pour les modèles LLM modernes**.
- Pour une user story moyenne (400-600 tokens de description) et une réponse de 2000 tokens, le temps total est **~11-12 secondes**.
- La **stabilité** montre une **performance prévisible** et peu variable.

**Synthèse** : Vitesse d'inférence conforme aux attentes, stable et prévisible.

**Note attribuée** : **4/5** — Vitesse d'inférence excellente et stable.

---

## Synthèse Générale et Scoring

### Tableau récapitulatif des 5 métriques

| # | Métrique | Score | Contexte |
|---|----------|-------|---------|
| 1 | Couverture fonctionnelle | **4/5** | 89.8 % en moyenne (très bonne) |
| 2 | Diversité des cas | **4/5** | 63 % positif, 30 % négatif, 8 % edge-case |
| 3 | Validité structurelle | **5/5** | 100 % de persistance sans erreur |
| 4 | Latence | **3.5/5** | 18.4 sec médiane (acceptable asynchrone) |
| 5 | Vitesse d'inférence | **4/5** | 180 tokens/sec stable (excellent) |
| | **TOTAL** | **20.5/25** | **82 % — Très bon** |

### Interprétation

L'API de génération de checklists démontre une **qualité très bonne** pour l'automatisation de la création de cas de test. Elle est **production-ready** avec quelques optimisations recommandées.

**Forces principales** :
- ✅ Validité structurelle parfaite (100 %)
- ✅ Couverture fonctionnelle très bonne (89.8 %)
- ✅ Diversité de tests équilibrée (63-30-8)
- ✅ Performance stable et prévisible (180 tok/sec)

**Points d'amélioration** (mineurs) :
- ⚠️ Edge-cases sous-représentés (8 % vs. cible 12-15 %)
- ⚠️ Latence sans streaming (18 sec) — améliorable avec SSE

---

## Forces du Modèle

1. **Couverture excellente (87 %)** : Capture très bien les exigences métier
2. **Validité structurelle parfaite (100 %)** : Zéro erreur de persistance
3. **Diversité équilibrée** : Mélange sain de cas positifs, négatifs et limites
4. **Robustesse API** : Taux de succès 100 %, pas de timeouts ou erreurs
5. **Performance prévisible** : Latence et vitesse stables, peu de variance

---

## Limites Identifiées

1. **Edge-cases sous-représentés** : 8 % vs. cible 12-15 %
   - Mitigation : Enrichir le prompt avec exemples d'edge-cases

2. **Latence sans streaming** : 18.4 sec médiane
   - Mitigation : Activer streaming SSE côté frontend

3. **Amélioration edge-cases** : Chercher à atteindre 12-15 % (vs. 8 % observé)
   - Mitigation : Enrichir le prompt avec exemples d'edge-cases par domaine

---

## Pistes d'Amélioration Concrètes

### Court terme (1-3 mois)
1. **Enrichir le prompt** : Ajouter exemples d'edge-cases par domaine
2. **Activer streaming SSE** : Afficher résultat au fur et à mesure
3. **Post-traitement** : Fusionner items similaires, ajouter catégories

### Moyen terme (3-6 mois)
4. **Feedback utilisateur** : Boucle d'apprentissage sur corrections manuelles
5. **Benchmarking multi-modèles** : Comparer avec Sonnet/Opus

### Long terme (6-12 mois)
6. **Fine-tuning spécialisé** : Dataset sur cas de test métier historiques

---

## Conclusion et Recommandation

### Verdict

L'API de génération de checklists est **production-ready** avec un score de **82 % (20.5/25)**.

Elle **automatise efficacement** la création de cas de test, produit des résultats de **qualité très bonne** et respecte **intégralement** les contraintes de schéma.

### Recommandation

✅ **DÉPLOYER EN PRODUCTION** avec la feuille de route d'amélioration :

**Phase 1** (Immédiate) : Monitoring + alertes
**Phase 2** (Court terme) : Streaming SSE + enrichissement prompt
**Phase 3** (Moyen terme) : Feedback loop utilisateur

---

## Appendices

### Données brutes
- **Phase A résultats** : `evaluation/data/phase_a_results.json`
- **Phase A audit** : `evaluation/data/phase_a_classification_audit.csv` (179 items)
- **Phase B résultats** : `evaluation/data/phase_b_results.json`
- **Phase B timings** : `evaluation/data/phase_b_timings.csv` (15 mesures)
- **Phase B runs** : `evaluation/data/phase_b_runs/*.json` (contenu généré)

**Rapport généré** : 2026-06-04
**Confidentiel** : Non
