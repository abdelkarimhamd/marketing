import { useCallback, useEffect, useState } from 'react'
import {
  Button,
  Card,
  CardContent,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  IconButton,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon } from '@mui/icons-material'
import { apiRequest } from '../lib/api'

const SEGMENT_FIELDS = [
  { value: 'city', label: 'City' },
  { value: 'interest', label: 'Interest' },
  { value: 'service', label: 'Service' },
  { value: 'status', label: 'Status' },
  { value: 'source', label: 'Source' },
  { value: 'company', label: 'Company' },
  { value: 'email', label: 'Email' },
]

const SEGMENT_OPERATORS = [
  { value: 'equals', label: 'equals' },
  { value: 'not_equals', label: 'does not equal' },
  { value: 'contains', label: 'contains' },
  { value: 'starts_with', label: 'starts with' },
  { value: 'ends_with', label: 'ends with' },
  { value: 'in', label: 'is one of (comma separated)' },
  { value: 'not_in', label: 'is not one of (comma separated)' },
]

function createRuleRow() {
  return { field: 'city', operator: 'equals', value: '' }
}

function parseList(text) {
  return String(text ?? '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

function parseRulesToRows(rulesJson) {
  if (!rulesJson || !Array.isArray(rulesJson.rules)) {
    return { operator: 'AND', rows: [createRuleRow()], isSimple: true }
  }

  const rows = []
  let isSimple = true

  rulesJson.rules.forEach((rule) => {
    if (!rule || typeof rule !== 'object') return
    if (Array.isArray(rule.rules)) {
      isSimple = false
      return
    }

    rows.push({
      field: typeof rule.field === 'string' ? rule.field : 'city',
      operator: typeof rule.operator === 'string' ? rule.operator : 'equals',
      value: Array.isArray(rule.value) ? rule.value.join(', ') : String(rule.value ?? ''),
    })
  })

  return {
    operator: String(rulesJson.operator ?? 'AND').toUpperCase() === 'OR' ? 'OR' : 'AND',
    rows: rows.length > 0 ? rows : [createRuleRow()],
    isSimple,
  }
}

function buildRulesJson(operator, rows) {
  const normalizedRows = rows
    .map((row) => {
      const value = String(row.value ?? '').trim()
      if (value === '') return null

      const normalized = {
        field: row.field,
        operator: row.operator,
        value,
      }

      if (row.operator === 'in' || row.operator === 'not_in') {
        normalized.value = parseList(value)
      }

      return normalized
    })
    .filter(Boolean)

  if (normalizedRows.length === 0) {
    return null
  }

  return {
    operator,
    rules: normalizedRows,
  }
}

function SegmentsPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [segments, setSegments] = useState([])
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState({
    name: '',
    description: '',
    operator: 'AND',
    rules: [createRuleRow()],
    is_active: true,
    existingRulesJson: null,
    hasComplexRules: false,
  })
  const canCreate = can('segments.create')
  const canUpdate = can('segments.update')
  const canDelete = can('segments.delete')

  const loadSegments = useCallback(async () => {
    try {
      const response = await apiRequest('/api/admin/segments?per_page=100', { token, tenantId })
      setSegments(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadSegments()
  }, [loadSegments, refreshKey])

  const openNew = () => {
    if (!canCreate) {
      onNotify('You do not have permission to create segments.', 'warning')
      return
    }

    setEditing(null)
    setForm({
      name: '',
      description: '',
      operator: 'AND',
      rules: [createRuleRow()],
      is_active: true,
      existingRulesJson: null,
      hasComplexRules: false,
    })
    setDialogOpen(true)
  }

  const openEdit = (segment) => {
    if (!canUpdate) {
      onNotify('You do not have permission to update segments.', 'warning')
      return
    }

    const parsed = parseRulesToRows(segment.rules_json)

    setEditing(segment)
    setForm({
      name: segment.name ?? '',
      description: segment.description ?? '',
      operator: parsed.operator,
      rules: parsed.rows,
      is_active: Boolean(segment.is_active),
      existingRulesJson: segment.rules_json ?? null,
      hasComplexRules: !parsed.isSimple,
    })
    setDialogOpen(true)
  }

  const updateRule = (index, key, value) => {
    setForm((current) => ({
      ...current,
      rules: current.rules.map((rule, rowIndex) => (rowIndex === index ? { ...rule, [key]: value } : rule)),
    }))
  }

  const addRule = () => {
    setForm((current) => ({
      ...current,
      rules: [...current.rules, createRuleRow()],
    }))
  }

  const removeRule = (index) => {
    setForm((current) => {
      const nextRules = current.rules.filter((_, rowIndex) => rowIndex !== index)
      return {
        ...current,
        rules: nextRules.length > 0 ? nextRules : [createRuleRow()],
      }
    })
  }

  const save = async () => {
    if (editing && !canUpdate) {
      onNotify('You do not have permission to update segments.', 'warning')
      return
    }

    if (!editing && !canCreate) {
      onNotify('You do not have permission to create segments.', 'warning')
      return
    }

    try {
      const payload = {
        name: form.name.trim(),
        description: form.description.trim() || null,
        is_active: form.is_active,
      }

      const builtRules = buildRulesJson(form.operator, form.rules)

      if (builtRules) {
        payload.rules_json = builtRules
      } else if (editing && form.hasComplexRules && form.existingRulesJson) {
        payload.rules_json = form.existingRulesJson
      } else {
        payload.rules_json = null
      }

      if (editing) {
        await apiRequest(`/api/admin/segments/${editing.id}`, {
          method: 'PATCH',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Segment updated.', 'success')
      } else {
        await apiRequest('/api/admin/segments', {
          method: 'POST',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Segment created.', 'success')
      }

      setDialogOpen(false)
      loadSegments()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const remove = async (segmentId) => {
    if (!canDelete) {
      onNotify('You do not have permission to delete segments.', 'warning')
      return
    }

    try {
      await apiRequest(`/api/admin/segments/${segmentId}`, { method: 'DELETE', token, tenantId })
      onNotify('Segment deleted.', 'success')
      loadSegments()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction="row" justifyContent="space-between">
        <Typography variant="h5">Segments</Typography>
        {canCreate && (
          <Button variant="contained" startIcon={<AddIcon />} onClick={openNew}>
            New Segment
          </Button>
        )}
      </Stack>

      <Card>
        <CardContent sx={{ p: 0 }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Name</TableCell>
                <TableCell>Slug</TableCell>
                <TableCell>Active</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {segments.map((segment) => (
                <TableRow key={segment.id}>
                  <TableCell>{segment.name}</TableCell>
                  <TableCell>{segment.slug}</TableCell>
                  <TableCell>{segment.is_active ? 'yes' : 'no'}</TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={1} justifyContent="flex-end">
                      {canUpdate && (
                        <Button size="small" onClick={() => openEdit(segment)}>
                          Edit
                        </Button>
                      )}
                      {canDelete && (
                        <Button size="small" color="error" onClick={() => remove(segment.id)}>
                          Delete
                        </Button>
                      )}
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
              {segments.length === 0 && (
                <TableRow>
                  <TableCell colSpan={4}>
                    <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                      No segments yet.
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="md" fullWidth>
        <DialogTitle>{editing ? 'Edit Segment' : 'New Segment'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              label="Name"
              value={form.name}
              onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
            />
            <TextField
              label="Description"
              value={form.description}
              onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))}
            />

            <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2} alignItems={{ xs: 'stretch', md: 'center' }}>
              <FormControl size="small" sx={{ minWidth: 160 }}>
                <InputLabel>Match Logic</InputLabel>
                <Select
                  label="Match Logic"
                  value={form.operator}
                  onChange={(event) => setForm((prev) => ({ ...prev, operator: event.target.value }))}
                >
                  <MenuItem value="AND">All conditions (AND)</MenuItem>
                  <MenuItem value="OR">Any condition (OR)</MenuItem>
                </Select>
              </FormControl>
              <Button variant="outlined" onClick={addRule}>
                Add Condition
              </Button>
            </Stack>

            {form.hasComplexRules && (
              <Typography variant="caption" color="warning.main">
                This segment has advanced nested logic. Simple fields below will overwrite it when saved.
              </Typography>
            )}

            {form.rules.map((rule, index) => (
              <Stack key={index} direction={{ xs: 'column', md: 'row' }} spacing={1} alignItems={{ xs: 'stretch', md: 'center' }}>
                <FormControl size="small" sx={{ minWidth: 150 }}>
                  <InputLabel>Field</InputLabel>
                  <Select
                    label="Field"
                    value={rule.field}
                    onChange={(event) => updateRule(index, 'field', event.target.value)}
                  >
                    {SEGMENT_FIELDS.map((field) => (
                      <MenuItem key={field.value} value={field.value}>
                        {field.label}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>

                <FormControl size="small" sx={{ minWidth: 220 }}>
                  <InputLabel>Operator</InputLabel>
                  <Select
                    label="Operator"
                    value={rule.operator}
                    onChange={(event) => updateRule(index, 'operator', event.target.value)}
                  >
                    {SEGMENT_OPERATORS.map((operator) => (
                      <MenuItem key={operator.value} value={operator.value}>
                        {operator.label}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>

                <TextField
                  size="small"
                  fullWidth
                  label={rule.operator === 'in' || rule.operator === 'not_in' ? 'Values (comma separated)' : 'Value'}
                  value={rule.value}
                  onChange={(event) => updateRule(index, 'value', event.target.value)}
                />

                <IconButton color="error" onClick={() => removeRule(index)} disabled={form.rules.length === 1}>
                  <DeleteIcon />
                </IconButton>
              </Stack>
            ))}

            <FormControl size="small" sx={{ maxWidth: 180 }}>
              <InputLabel>Active</InputLabel>
              <Select
                label="Active"
                value={form.is_active ? 'yes' : 'no'}
                onChange={(event) => setForm((prev) => ({ ...prev, is_active: event.target.value === 'yes' }))}
              >
                <MenuItem value="yes">yes</MenuItem>
                <MenuItem value="no">no</MenuItem>
              </Select>
            </FormControl>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialogOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={save} disabled={editing ? !canUpdate : !canCreate}>
            Save
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default SegmentsPanel
