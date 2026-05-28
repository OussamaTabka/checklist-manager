# Diagramme de cas d'utilisation de l'agent d'automatisation des tests

## Affichage du diagramme

Pour afficher le diagramme localement, ouvrez le fichier `docs/diagrams/use-case-agent-automatisation.puml` avec l'extension VS Code PlantUML puis utilisez la prévisualisation PlantUML.

Vous pouvez aussi copier le contenu du fichier sur le site https://www.plantuml.com/plantuml pour générer un rendu du diagramme.

## Acteurs

- Testeur QA
- Application sous test

## Cas d'utilisation

- Préparer un cas de test pour l'automatisation
- Renseigner les inputs requis
- Valider la testabilité du cas de test
- Déclencher l'automatisation d'un cas de test
- Générer la spécification d'exécution via IA
- Exécuter la spécification dans un environnement isolé
- Collecter les artefacts d'exécution
- Suivre l'avancement d'une exécution automatisée
- Consulter le rapport d'une exécution automatisée
- Visualiser les traces et captures d'écran
- Comparer plusieurs exécutions d'un même cas
- Relancer une exécution automatisée

## Pourquoi certains éléments n'apparaissent pas comme acteurs

Le service Gemini, l'orchestrateur et le runner Playwright n'apparaissent pas comme acteurs, car ils ne sont pas vus de l'extérieur dans un diagramme de cas d'utilisation. Ils font partie du fonctionnement interne du système « Agent d'automatisation des tests » au même titre que les traitements d'analyse, d'orchestration, d'exécution isolée et de collecte d'artefacts. Le seul système externe légitime en interaction directe avec l'agent, du point de vue métier, est l'application sous test.
