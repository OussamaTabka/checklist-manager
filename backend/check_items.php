<?php
try {
  $pdo = new PDO('sqlite:database/database.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Check project versions
  echo "=== PROJECT VERSIONS ===\n";
  $stmt = $pdo->query('SELECT id, version_number FROM project_versions LIMIT 3');
  $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
  echo "Found " . count($versions) . " project versions\n";
  foreach($versions as $v) {
    echo "  ID: " . $v['id'] . ", Version: " . $v['version_number'] . "\n";
  }

  // Check items for first version
  echo "\n=== VERSION ITEMS ===\n";
  $stmt = $pdo->query('SELECT id, title, is_executable, test_type FROM version_items WHERE project_version_id = 1 LIMIT 10');
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
  echo "Found " . count($items) . " items for version 1\n";
  foreach($items as $item) {
    $executable = $item['is_executable'] ? 'YES' : 'NO';
    echo "  ID: " . $item['id'] . ", Title: " . $item['title'] . ", Executable: " . $executable . ", Type: " . ($item['test_type'] ?? 'NULL') . "\n";
  }

  // Count executable items
  echo "\n=== EXECUTABLE COUNT ===\n";
  $stmt = $pdo->query('SELECT COUNT(*) as count FROM version_items WHERE is_executable = 1');
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  echo "Total executable items in DB: " . $result['count'] . "\n";
} catch(Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
}
