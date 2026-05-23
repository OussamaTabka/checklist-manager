<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport projet</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; margin: 24px; }
        h1, h2, h3 { margin: 0 0 8px 0; }
        h1 { font-size: 24px; }
        h2 { font-size: 18px; margin-top: 26px; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px; }
        h3 { font-size: 13px; margin-top: 18px; }
        p { margin: 0 0 8px 0; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
        th, td { border: 1px solid #cbd5e1; padding: 7px; text-align: left; vertical-align: top; }
        th { background: #e2e8f0; font-weight: 700; }
        .muted { color: #64748b; }
        .cover { padding: 56px 0 38px; }
        .pill { display: inline-block; background: #ecfeff; color: #155e75; padding: 4px 8px; border-radius: 999px; font-size: 10px; }
        .grid { width: 100%; }
        .grid td { width: 50%; }
        .section-note { color: #334155; }
        ul { padding-left: 18px; margin: 8px 0 16px; }
        li { margin-bottom: 6px; }
    </style>
</head>
<body>
    <div class="cover">
        <span class="pill">Rapport projet synthétique</span>
        <h1>{{ $report['project']['name'] }}</h1>
        <p>{{ $report['project']['description'] }}</p>
        <p class="muted">Chef de projet : {{ $report['project']['project_manager'] }}</p>
        <p class="muted">Généré le : {{ $report['meta']['generated_at'] }}</p>
        <p class="muted">Généré par : {{ $report['meta']['generated_by'] }}</p>
    </div>

    <h2>Résumé exécutif</h2>
    <table class="grid">
        <tr>
            <th>Total cas de test</th>
            <td>{{ $report['summary']['total_test_cases'] }}</td>
            <th>Tests exécutés</th>
            <td>{{ $report['summary']['executed_tests'] }}</td>
        </tr>
        <tr>
            <th>Tests non exécutés</th>
            <td>{{ $report['summary']['not_tested'] }}</td>
            <th>Taux d'exécution</th>
            <td>{{ $report['summary']['execution_rate'] }}%</td>
        </tr>
        <tr>
            <th>Tests réussis</th>
            <td>{{ $report['summary']['passed'] }}</td>
            <th>Taux de réussite</th>
            <td>{{ $report['summary']['pass_rate'] }}%</td>
        </tr>
        <tr>
            <th>Tests échoués</th>
            <td>{{ $report['summary']['failed'] }}</td>
            <th>Tests bloqués</th>
            <td>{{ $report['summary']['blocked'] }}</td>
        </tr>
    </table>

    <h2>Informations générales du projet</h2>
    <table>
        <tr><th>Nom du projet</th><td>{{ $report['project']['name'] }}</td></tr>
        <tr><th>Description</th><td>{{ $report['project']['description'] }}</td></tr>
        <tr><th>Chef de projet</th><td>{{ $report['project']['project_manager'] }}</td></tr>
        <tr><th>Testeurs assignés</th><td>{{ count($report['project']['assigned_testers']) > 0 ? collect($report['project']['assigned_testers'])->pluck('name')->implode(', ') : 'Non disponible' }}</td></tr>
        <tr><th>Date de création</th><td>{{ $report['project']['created_at'] ?? 'Non disponible' }}</td></tr>
        <tr><th>Dernière mise à jour</th><td>{{ $report['project']['updated_at'] ?? 'Non disponible' }}</td></tr>
    </table>

    <h2>Périmètre du rapport</h2>
    <p class="section-note">Versions incluses : {{ count($report['scope']['versions_included']) > 0 ? implode(', ', $report['scope']['versions_included']) : 'Non disponible' }}</p>
    <p class="section-note">User stories incluses : {{ $report['scope']['user_stories_included'] }}</p>

    <h2>Couverture des User Stories</h2>
    @if (!empty($report['user_stories']))
        <table>
            <thead>
                <tr>
                    <th>Story</th>
                    <th>Statut</th>
                    <th>Priorité</th>
                    <th>Checklists liées</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['user_stories'] as $story)
                    <tr>
                        <td>{{ $story['title'] }}</td>
                        <td>{{ $story['status'] }}</td>
                        <td>{{ $story['priority'] }}</td>
                        <td>{{ count($story['checklists']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Non disponible.</p>
    @endif

    <h2>Synthèse des checklists</h2>
    @if (!empty($report['checklists']))
        <table>
            <thead>
                <tr>
                    <th>Checklist</th>
                    <th>Source</th>
                    <th>Version</th>
                    <th>Statut</th>
                    <th>Origine</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['checklists'] as $checklist)
                    <tr>
                        <td>{{ $checklist['name'] }}</td>
                        <td>{{ $checklist['source'] }}</td>
                        <td>{{ $checklist['version_number'] ?? 'Non disponible' }}</td>
                        <td>{{ $checklist['lifecycle_status'] }}</td>
                        <td>{{ $checklist['generated_from'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Non disponible.</p>
    @endif

    <h2>Résumé d’exécution des tests</h2>
    <table>
        <tr><th>Cas de test totaux</th><td>{{ $report['summary']['total_test_cases'] }}</td></tr>
        <tr><th>Exécutés</th><td>{{ $report['summary']['executed_tests'] }}</td></tr>
        <tr><th>À risque</th><td>{{ $report['summary']['risk_count'] }}</td></tr>
        <tr><th>Niveau de risque</th><td>{{ $report['quality_risks']['risk_level'] }}</td></tr>
    </table>

    <h2>Tests échoués et bloqués</h2>
    @if (!empty($report['failed_and_blocked']))
        <table>
            <thead>
                <tr>
                    <th>Cas</th>
                    <th>Statut</th>
                    <th>Priorité</th>
                    <th>Dernière erreur</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['failed_and_blocked'] as $case)
                    <tr>
                        <td>{{ $case['title'] }}</td>
                        <td>{{ $case['status'] }}</td>
                        <td>{{ $case['priority'] }}</td>
                        <td>{{ $case['latest_error'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Aucun test échoué ou bloqué.</p>
    @endif

    <h2>Historique et commentaires</h2>
    <p class="section-note">Historique d’exécution disponible : {{ count($report['execution_history']) }}</p>
    <p class="section-note">Commentaires disponibles : {{ count($report['comments']) }}</p>
    @if (!empty($report['comments']))
        <table>
            <thead>
                <tr>
                    <th>Auteur</th>
                    <th>Cas</th>
                    <th>Commentaire</th>
                </tr>
            </thead>
            <tbody>
                @foreach (array_slice($report['comments'], 0, 10) as $comment)
                    <tr>
                        <td>{{ $comment['author'] }}</td>
                        <td>{{ $comment['test_case_title'] }}</td>
                        <td>{{ $comment['content'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Résultats des tests automatiques</h2>
    <p class="section-note">Résultats automatiques recensés : {{ count($report['automation_results']) }}</p>
    @if (!empty($report['automation_results']))
        <table>
            <thead>
                <tr>
                    <th>Run</th>
                    <th>Version</th>
                    <th>Statut</th>
                    <th>Traces</th>
                </tr>
            </thead>
            <tbody>
                @foreach (array_slice($report['automation_results'], 0, 10) as $result)
                    <tr>
                        <td>{{ $result['run_id'] }}</td>
                        <td>{{ $result['version_number'] }}</td>
                        <td>{{ $result['status'] }}</td>
                        <td>{{ $result['artifacts']['trace_count'] }} trace(s), {{ $result['artifacts']['screenshot_count'] }} capture(s), {{ $result['artifacts']['video_count'] }} vidéo(s)</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Non disponible.</p>
    @endif

    <h2>Risques qualité</h2>
    <table>
        <tr><th>Nombre de risques</th><td>{{ $report['quality_risks']['risk_count'] }}</td></tr>
        <tr><th>Niveau</th><td>{{ $report['quality_risks']['risk_level'] }}</td></tr>
        <tr><th>Tests non exécutés</th><td>{{ $report['quality_risks']['not_tested'] }}</td></tr>
    </table>

    <h2>Décision finale / conclusion</h2>
    <p><strong>{{ $report['conclusion']['decision'] }}</strong></p>
    <p>{{ $report['conclusion']['statement'] }}</p>

    <h2>Recommandations</h2>
    <ul>
        @foreach ($report['recommendations'] as $recommendation)
            <li>{{ $recommendation }}</li>
        @endforeach
    </ul>
</body>
</html>
