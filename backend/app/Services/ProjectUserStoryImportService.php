<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ProjectUserStoryImportService
{
    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    private const STATUSES = ['backlog', 'in_progress', 'ready_for_test', 'completed'];
    private const DUPLICATE_STORY_REFERENCE_MESSAGE = 'Cette référence existe déjà dans ce projet.';

    public function prepareManualStories(mixed $stories): array
    {
        $decoded = $this->decodeManualStories($stories);

        if (!is_array($decoded)) {
            throw new RuntimeException('manual_user_stories must be a valid array.');
        }

        return $this->prepareStories($decoded, 'manual');
    }

    public function prepareImportedStories(?UploadedFile $file, array $existingStoryReferences = []): array
    {
        if (!$file) {
            return $this->emptyResult();
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $rows = match ($extension) {
            'csv' => $this->parseCsv($file),
            'json' => $this->parseJson($file),
            'xlsx' => $this->parseXlsx($file),
            default => throw new RuntimeException("Le format du fichier importé n'est pas valide."),
        };

        return $this->prepareStories($rows, 'import', $existingStoryReferences);
    }

    private function decodeManualStories(mixed $stories): mixed
    {
        if (is_string($stories)) {
            $decoded = json_decode($stories, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('manual_user_stories must contain valid JSON.');
            }

            return $decoded;
        }

        return $stories;
    }

    private function prepareStories(array $rows, string $source, array $existingStoryReferences = []): array
    {
        $validStories = [];
        $errors = [];
        $failedCount = 0;
        $seenReferences = $this->normalizeStoryReferences($existingStoryReferences);

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $failedCount++;
                $errors[] = [
                    'source' => $source,
                    'row' => $index + 1,
                    'message' => 'Invalid user story payload.',
                ];
                continue;
            }

            [$story, $storyErrors] = $this->normalizeAndValidateRow($row);

            $normalizedStoryReference = $this->normalizeStoryReference($story['story_id'] ?? null);

            if ($normalizedStoryReference !== null && in_array($normalizedStoryReference, $seenReferences, true)) {
                $storyErrors[] = self::DUPLICATE_STORY_REFERENCE_MESSAGE;
            }

            if ($storyErrors !== []) {
                $failedCount++;
                foreach ($storyErrors as $message) {
                    $errors[] = [
                        'source' => $source,
                        'row' => $index + 1,
                        'message' => $message,
                    ];
                }
                continue;
            }

            $validStories[] = $story;

            if ($normalizedStoryReference !== null) {
                $seenReferences[] = $normalizedStoryReference;
            }
        }

        return [
            'stories' => $validStories,
            'errors' => $errors,
            'failed_count' => $failedCount,
        ];
    }

    private function normalizeAndValidateRow(array $row): array
    {
        $title = $this->extractString($row, ['title', 'titre']);
        $description = $this->extractString($row, ['description']);
        $acceptanceCriteria = $this->extractTextField($row, ['acceptance_criteria', 'acceptance criteria', 'criteres_acceptation', 'critères_acceptation']);
        $storyId = $this->extractString($row, ['story_id', 'reference', 'ref']);
        $priority = $this->normalizeEnum($this->extractString($row, ['priority', 'priorite', 'priorité']), self::PRIORITIES);
        $status = $this->normalizeEnum($this->extractString($row, ['status', 'statut']), self::STATUSES);
        $businessRules = $this->extractList($row, ['business_rules', 'business rules', 'regles_metier', 'règles_métier']);
        $scenarios = $this->extractList($row, ['scenarios', 'scenario', 'scénarios']);

        $errors = [];

        if ($title === '') {
            $errors[] = 'Missing title';
        }

        if ($description === '') {
            $errors[] = 'Missing description';
        }

        if ($acceptanceCriteria === '') {
            $errors[] = 'Missing acceptance criteria';
        }

        if ($this->hasProvidedValue($row, ['priority', 'priorite', 'priorité']) && $priority === null) {
            $errors[] = 'Invalid priority';
        }

        if ($this->hasProvidedValue($row, ['status', 'statut']) && $status === null) {
            $errors[] = 'Invalid status';
        }

        return [[
            'title' => $title,
            'description' => $description,
            'acceptance_criteria' => $acceptanceCriteria,
            'story_id' => $storyId !== '' ? $storyId : null,
            'priority' => $priority ?? 'medium',
            'status' => $status ?? 'backlog',
            'business_rules' => $businessRules,
            'scenarios' => $scenarios,
        ], $errors];
    }

    private function parseCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if (!$handle) {
            throw new RuntimeException('Unable to read CSV file.');
        }

        $rows = [];
        $headers = null;

        while (($data = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(
                    fn ($header) => is_string($header) ? trim($header) : '',
                    $data
                );
                continue;
            }

            if ($this->rowIsEmpty($data)) {
                continue;
            }

            $row = [];
            foreach ($headers as $columnIndex => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = isset($data[$columnIndex]) ? trim((string) $data[$columnIndex]) : '';
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function parseJson(UploadedFile $file): array
    {
        $decoded = json_decode((string) file_get_contents($file->getRealPath()), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('The JSON file must contain an array of user stories.');
        }

        return $decoded;
    }

    private function parseXlsx(UploadedFile $file): array
    {
        $zip = new ZipArchive();
        $status = $zip->open($file->getRealPath());

        if ($status !== true) {
            throw new RuntimeException('Unable to open XLSX file.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $worksheetPath = $this->resolveFirstWorksheetPath($zip);
        $sheetXml = $zip->getFromName($worksheetPath);
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('Unable to read XLSX worksheet.');
        }

        $xml = simplexml_load_string($sheetXml);
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Invalid XLSX worksheet content.');
        }

        $xml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rowNodes = $xml->xpath('//main:sheetData/main:row') ?: [];

        $grid = [];
        foreach ($rowNodes as $rowNode) {
            $rowData = [];
            foreach ($rowNode->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $columnLetters = preg_replace('/\d+/', '', $reference) ?: '';
                $columnIndex = $this->columnLettersToIndex($columnLetters);
                $rowData[$columnIndex] = $this->extractCellValue($cell, $sharedStrings);
            }
            ksort($rowData);
            $grid[] = $rowData;
        }

        if ($grid === []) {
            return [];
        }

        $headerRow = array_shift($grid);
        $maxColumnIndex = $headerRow !== [] ? max(array_keys($headerRow)) : -1;
        $headers = [];

        for ($i = 0; $i <= $maxColumnIndex; $i++) {
            $headers[$i] = trim((string) ($headerRow[$i] ?? ''));
        }

        $rows = [];
        foreach ($grid as $row) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $normalized = [];
            foreach ($headers as $columnIndex => $header) {
                if ($header === '') {
                    continue;
                }

                $normalized[$header] = trim((string) ($row[$columnIndex] ?? ''));
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');

        if ($sharedStringsXml === false) {
            return [];
        }

        $xml = simplexml_load_string($sharedStringsXml);
        if (!$xml instanceof SimpleXMLElement) {
            return [];
        }

        $xml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $items = $xml->xpath('//main:si') ?: [];

        return array_map(function (SimpleXMLElement $item): string {
            $texts = $item->xpath('.//main:t') ?: [];
            return trim(implode('', array_map(fn ($node) => (string) $node, $texts)));
        }, $items);
    }

    private function resolveFirstWorksheetPath(ZipArchive $zip): string
    {
        $workbookRelsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookRelsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $rels = simplexml_load_string($workbookRelsXml);
        if (!$rels instanceof SimpleXMLElement) {
            return 'xl/worksheets/sheet1.xml';
        }

        foreach ($rels->Relationship as $relationship) {
            $type = (string) ($relationship['Type'] ?? '');
            if (str_ends_with($type, '/worksheet')) {
                $target = (string) ($relationship['Target'] ?? 'worksheets/sheet1.xml');
                return str_starts_with($target, 'xl/') ? $target : 'xl/' . ltrim($target, '/');
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function extractCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 's') {
            $sharedIndex = (int) ($cell->v ?? 0);
            return (string) ($sharedStrings[$sharedIndex] ?? '');
        }

        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        return trim((string) ($cell->v ?? ''));
    }

    private function extractString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            foreach ($row as $candidateKey => $value) {
                if ($this->normalizeKey((string) $candidateKey) !== $this->normalizeKey($key)) {
                    continue;
                }

                if (is_scalar($value)) {
                    return trim((string) $value);
                }

                if ($value === null) {
                    return '';
                }

                return trim(json_encode($value, JSON_UNESCAPED_UNICODE) ?: '');
            }
        }

        return '';
    }

    private function extractTextField(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            foreach ($row as $candidateKey => $value) {
                if ($this->normalizeKey((string) $candidateKey) !== $this->normalizeKey($key)) {
                    continue;
                }

                if (is_array($value)) {
                    return trim(implode("\n", array_map(fn ($item) => trim((string) $item), $value)));
                }

                return trim((string) ($value ?? ''));
            }
        }

        return '';
    }

    private function extractList(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            foreach ($row as $candidateKey => $value) {
                if ($this->normalizeKey((string) $candidateKey) !== $this->normalizeKey($key)) {
                    continue;
                }

                if (is_array($value)) {
                    return array_values(array_filter(array_map(
                        fn ($item) => trim((string) $item),
                        $value
                    ), fn ($item) => $item !== ''));
                }

                $stringValue = trim((string) ($value ?? ''));
                if ($stringValue === '') {
                    return [];
                }

                $decoded = json_decode($stringValue, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return array_values(array_filter(array_map(
                        fn ($item) => trim((string) $item),
                        $decoded
                    ), fn ($item) => $item !== ''));
                }

                return array_values(array_filter(array_map(
                    fn ($item) => trim($item),
                    preg_split('/\r\n|\r|\n|\|/', $stringValue) ?: []
                ), fn ($item) => $item !== ''));
            }
        }

        return [];
    }

    private function normalizeEnum(string $value, array $allowed): ?string
    {
        if ($value === '') {
            return null;
        }

        $normalized = strtolower(str_replace([' ', '-'], '_', $value));

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function hasProvidedValue(array $row, array $keys): bool
    {
        return $this->extractString($row, $keys) !== '';
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = str_replace([' ', '-'], '_', $key);

        return strtr($key, [
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'ç' => 'c',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'î' => 'i',
            'ï' => 'i',
            'ô' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
        ]);
    }

    private function columnLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max($index - 1, 0);
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function emptyResult(): array
    {
        return [
            'stories' => [],
            'errors' => [],
            'failed_count' => 0,
        ];
    }

    private function normalizeStoryReferences(array $references): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($reference) => $this->normalizeStoryReference(is_string($reference) ? $reference : null),
            $references
        ))));
    }

    private function normalizeStoryReference(?string $reference): ?string
    {
        $trimmedReference = trim((string) $reference);

        if ($trimmedReference === '') {
            return null;
        }

        return mb_strtolower($trimmedReference);
    }
}
