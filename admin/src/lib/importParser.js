import * as XLSX from 'xlsx'
import Papa from 'papaparse'

export const LEAD_IMPORT_FIELDS = [
  'first_name',
  'last_name',
  'email',
  'phone',
  'company',
  'city',
  'interest',
  'service',
  'title',
  'status',
  'source',
  'score',
  'email_consent',
  'tags',
]

export function normalizeHeader(value) {
  return String(value ?? '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
}

export async function parseImportFile(file) {
  if (!file) return { rows: [], columns: [] }

  const extension = file.name.toLowerCase().split('.').pop()
  let rows = []

  if (extension === 'csv') {
    const text = await file.text()
    const parsed = Papa.parse(text, { header: true, skipEmptyLines: true })
    rows = parsed.data ?? []
  } else if (['xlsx', 'xls'].includes(extension)) {
    const buffer = await file.arrayBuffer()
    const workbook = XLSX.read(buffer, { type: 'array' })
    const firstSheet = workbook.SheetNames[0]
    rows = XLSX.utils.sheet_to_json(workbook.Sheets[firstSheet], { defval: '' })
  } else {
    throw new Error('Unsupported file type. Use CSV/XLSX.')
  }

  if (!Array.isArray(rows) || rows.length === 0) {
    throw new Error('No rows found in selected file.')
  }

  const columns = Object.keys(rows[0])
  const mapping = {}

  columns.forEach((column) => {
    const normalized = normalizeHeader(column)
    const mappedField = LEAD_IMPORT_FIELDS.find((field) => normalizeHeader(field) === normalized)
    mapping[column] = mappedField ?? ''
  })

  return {
    rows: rows.slice(0, 500),
    columns,
    mapping,
  }
}

export function mapImportRows(rows, mapping) {
  return rows
    .map((row) => {
      const lead = {}

      Object.entries(mapping).forEach(([column, field]) => {
        if (!field) return
        const rawValue = row[column]

        if (field === 'score') {
          const parsed = Number(rawValue)
          lead.score = Number.isFinite(parsed) ? parsed : 0
          return
        }

        if (field === 'email_consent') {
          const text = String(rawValue ?? '').toLowerCase().trim()
          lead.email_consent = ['1', 'true', 'yes', 'y'].includes(text)
          return
        }

        if (field === 'tags') {
          lead.tags = String(rawValue ?? '')
            .split(',')
            .map((item) => item.trim())
            .filter(Boolean)
          return
        }

        lead[field] = String(rawValue ?? '').trim()
      })

      return lead
    })
    .filter((lead) => (lead.email && lead.email !== '') || (lead.phone && lead.phone !== ''))
}
