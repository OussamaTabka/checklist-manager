export function createEmptyManualUserStory() {
  return {
    reference: '',
    title: '',
    description: '',
    acceptance_criteria: '',
    priority: 'medium',
    status: 'backlog',
    business_rules: '',
    scenarios: '',
  }
}

export function validateManualUserStory(story) {
  const errors = {}

  if (!String(story.title || '').trim()) {
    errors.title = 'Le titre de la User Story est obligatoire.'
  }

  if (!String(story.description || '').trim()) {
    errors.description = 'La description de la User Story est obligatoire.'
  }

  if (!String(story.acceptance_criteria || '').trim()) {
    errors.acceptance_criteria = 'Les critères d’acceptation sont obligatoires.'
  }

  return errors
}

export function normalizeManualUserStory(story) {
  return {
    story_id: normalizeText(story.reference),
    title: normalizeText(story.title),
    description: normalizeText(story.description),
    acceptance_criteria: normalizeText(story.acceptance_criteria),
    priority: normalizeText(story.priority) || 'medium',
    status: normalizeText(story.status) || 'backlog',
    business_rules: splitLines(story.business_rules),
    scenarios: splitLines(story.scenarios),
  }
}

export async function analyzeImportedUserStories(file) {
  const rows = await parseUserStoryFile(file)
  const validStories = []
  const errors = []

  rows.forEach((row, index) => {
    const normalized = normalizeImportedRow(row)
    const rowErrors = validateImportedRow(normalized)

    if (rowErrors.length > 0) {
      errors.push({
        row: index + 1,
        message: rowErrors.join(' | '),
      })
      return
    }

    validStories.push(normalized)
  })

  return {
    totalRows: rows.length,
    validStories,
    validCount: validStories.length,
    invalidCount: errors.length,
    errors,
  }
}

async function parseUserStoryFile(file) {
  const extension = file.name.split('.').pop()?.toLowerCase()

  if (!extension || !['csv', 'xlsx', 'json'].includes(extension)) {
    throw new Error('Format non supporté. Utilisez un fichier CSV, XLSX ou JSON.')
  }

  if (extension === 'json') {
    const text = await file.text()
    const parsed = JSON.parse(text)

    if (!Array.isArray(parsed)) {
      throw new Error('Le fichier JSON doit contenir un tableau de User Stories.')
    }

    return parsed
  }

  if (extension === 'csv') {
    const text = await file.text()
    const lines = text
      .split(/\r?\n/)
      .filter((line) => line.trim() !== '')

    if (lines.length < 2) {
      return []
    }

    const headers = parseCsvLine(lines[0])
    return lines.slice(1).map((line) => {
      const columns = parseCsvLine(line)
      return headers.reduce((accumulator, header, columnIndex) => {
        accumulator[header] = columns[columnIndex] ?? ''
        return accumulator
      }, {})
    })
  }

  const xlsx = await import('xlsx')
  const arrayBuffer = await file.arrayBuffer()
  const workbook = xlsx.read(arrayBuffer, { type: 'array' })
  const firstSheet = workbook.Sheets[workbook.SheetNames[0]]

  return xlsx.utils.sheet_to_json(firstSheet, { defval: '' })
}

function normalizeImportedRow(row) {
  return {
    story_id: pickFirstValue(row, ['story_id', 'reference', 'ref']),
    title: pickFirstValue(row, ['title', 'titre']),
    description: pickFirstValue(row, ['description']),
    acceptance_criteria: stringifyField(row, ['acceptance_criteria', 'acceptance criteria', 'criteres_acceptation', 'critères_acceptation']),
    priority: normalizeEnum(pickFirstValue(row, ['priority', 'priorite', 'priorité']), ['low', 'medium', 'high', 'critical']) || 'medium',
    status: normalizeEnum(pickFirstValue(row, ['status', 'statut']), ['backlog', 'in_progress', 'ready_for_test', 'completed']) || 'backlog',
    business_rules: normalizeListField(row, ['business_rules', 'business rules', 'regles_metier', 'règles_métier']),
    scenarios: normalizeListField(row, ['scenarios', 'scenario', 'scénarios']),
  }
}

function validateImportedRow(story) {
  const errors = []

  if (!story.title) {
    errors.push('Le titre de la User Story est obligatoire.')
  }

  if (!story.description) {
    errors.push('La description de la User Story est obligatoire.')
  }

  if (!story.acceptance_criteria) {
    errors.push('Les critères d’acceptation sont obligatoires.')
  }

  return errors
}

function pickFirstValue(row, aliases) {
  for (const alias of aliases) {
    const normalizedAlias = normalizeKey(alias)
    const entry = Object.entries(row).find(([key]) => normalizeKey(key) === normalizedAlias)
    if (!entry) {
      continue
    }

    return normalizeText(entry[1])
  }

  return ''
}

function stringifyField(row, aliases) {
  for (const alias of aliases) {
    const normalizedAlias = normalizeKey(alias)
    const entry = Object.entries(row).find(([key]) => normalizeKey(key) === normalizedAlias)
    if (!entry) {
      continue
    }

    if (Array.isArray(entry[1])) {
      return entry[1].map((item) => normalizeText(item)).filter(Boolean).join('\n')
    }

    return normalizeText(entry[1])
  }

  return ''
}

function normalizeListField(row, aliases) {
  for (const alias of aliases) {
    const normalizedAlias = normalizeKey(alias)
    const entry = Object.entries(row).find(([key]) => normalizeKey(key) === normalizedAlias)
    if (!entry) {
      continue
    }

    const value = entry[1]
    if (Array.isArray(value)) {
      return value.map((item) => normalizeText(item)).filter(Boolean)
    }

    const textValue = normalizeText(value)
    if (!textValue) {
      return []
    }

    try {
      const parsed = JSON.parse(textValue)
      if (Array.isArray(parsed)) {
        return parsed.map((item) => normalizeText(item)).filter(Boolean)
      }
    } catch {
      // Fallback to line-based parsing below.
    }

    return splitLines(textValue)
  }

  return []
}

function parseCsvLine(line) {
  const result = []
  let current = ''
  let inQuotes = false

  for (let index = 0; index < line.length; index += 1) {
    const character = line[index]
    const next = line[index + 1]

    if (character === '"' && inQuotes && next === '"') {
      current += '"'
      index += 1
      continue
    }

    if (character === '"') {
      inQuotes = !inQuotes
      continue
    }

    if (character === ',' && !inQuotes) {
      result.push(current.trim())
      current = ''
      continue
    }

    current += character
  }

  result.push(current.trim())
  return result
}

function splitLines(value) {
  return normalizeText(value)
    .split(/\r?\n|\|/)
    .map((item) => item.trim())
    .filter(Boolean)
}

function normalizeEnum(value, allowedValues) {
  const normalizedValue = normalizeText(value).toLowerCase().replace(/[ -]/g, '_')
  return allowedValues.includes(normalizedValue) ? normalizedValue : ''
}

function normalizeKey(value) {
  return normalizeText(value)
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[ -]/g, '_')
}

function normalizeText(value) {
  return String(value ?? '').trim()
}
