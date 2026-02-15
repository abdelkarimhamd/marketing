import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Box,
  Button,
  Card,
  CardContent,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon, Edit as EditIcon, Refresh as RefreshIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

const CHANNEL_OPTIONS = ['', 'email', 'sms', 'whatsapp', 'call']

function splitLines(text) {
  return String(text ?? '')
    .split(/\r?\n/g)
    .map((line) => line.trim())
    .filter((line) => line !== '')
}

function objectionsFromText(text) {
  return splitLines(text)
    .map((line) => {
      const separator = line.includes('=>') ? '=>' : (line.includes(':') ? ':' : null)

      if (!separator) {
        return {
          objection: line,
          response: '',
        }
      }

      const [left, ...rest] = line.split(separator)

      return {
        objection: String(left ?? '').trim(),
        response: rest.join(separator).trim(),
      }
    })
    .filter((row) => row.objection !== '' || row.response !== '')
}

function objectionsToText(items) {
  if (!Array.isArray(items) || items.length === 0) return ''

  return items
    .map((item) => `${item.objection ?? ''}${item.response ? ` => ${item.response}` : ''}`.trim())
    .filter((line) => line !== '')
    .join('\n')
}

function templateContent(templates, channel) {
  if (!Array.isArray(templates)) return ''
  return templates.find((item) => item?.channel === channel)?.content ?? ''
}

function buildTemplates({ emailTemplate, smsTemplate, whatsappTemplate }) {
  const rows = []

  if (String(emailTemplate ?? '').trim() !== '') {
    rows.push({
      title: 'Email Template',
      channel: 'email',
      content: String(emailTemplate).trim(),
    })
  }

  if (String(smsTemplate ?? '').trim() !== '') {
    rows.push({
      title: 'SMS Template',
      channel: 'sms',
      content: String(smsTemplate).trim(),
    })
  }

  if (String(whatsappTemplate ?? '').trim() !== '') {
    rows.push({
      title: 'WhatsApp Template',
      channel: 'whatsapp',
      content: String(whatsappTemplate).trim(),
    })
  }

  return rows
}

function createEmptyForm() {
  return {
    name: '',
    industry: '',
    stage: '',
    channel: '',
    is_active: true,
    scriptsText: '',
    objectionsText: '',
    emailTemplate: '',
    smsTemplate: '',
    whatsappTemplate: '',
  }
}

function PlaybooksPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [filters, setFilters] = useState({
    search: '',
    industry: '',
    stage: '',
    channel: '',
    is_active: '',
  })
  const [loading, setLoading] = useState(false)
  const [rows, setRows] = useState([])
  const [editorOpen, setEditorOpen] = useState(false)
  const [editingRow, setEditingRow] = useState(null)
  const [form, setForm] = useState(createEmptyForm())
  const [saving, setSaving] = useState(false)
  const [bootstrapRunning, setBootstrapRunning] = useState(false)
  const [suggestionForm, setSuggestionForm] = useState({
    lead_id: '',
    industry: '',
    stage: '',
    channel: '',
    q: '',
  })
  const [suggestionLoading, setSuggestionLoading] = useState(false)
  const [suggestions, setSuggestions] = useState([])
  const canCreate = can('playbooks.create')
  const canUpdate = can('playbooks.update')
  const canDelete = can('playbooks.delete')
  const canSuggest = can('playbooks.suggest')

  const activeFilters = useMemo(() => ({
    ...filters,
    search: filters.search.trim(),
    industry: filters.industry.trim(),
    stage: filters.stage.trim(),
  }), [filters])

  const loadRows = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams({ per_page: '100' })
      if (activeFilters.search) params.set('search', activeFilters.search)
      if (activeFilters.industry) params.set('industry', activeFilters.industry)
      if (activeFilters.stage) params.set('stage', activeFilters.stage)
      if (activeFilters.channel) params.set('channel', activeFilters.channel)
      if (activeFilters.is_active !== '') params.set('is_active', activeFilters.is_active)

      const response = await apiRequest(`/api/admin/playbooks?${params.toString()}`, { token, tenantId })
      setRows(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }, [activeFilters, onNotify, tenantId, token])

  const runSuggestionPreview = useCallback(async () => {
    if (!canSuggest) {
      onNotify('You do not have permission to run playbook suggestions.', 'warning')
      return
    }

    setSuggestionLoading(true)
    try {
      const params = new URLSearchParams({ limit: '5' })
      if (suggestionForm.lead_id.trim()) params.set('lead_id', suggestionForm.lead_id.trim())
      if (suggestionForm.industry.trim()) params.set('industry', suggestionForm.industry.trim())
      if (suggestionForm.stage.trim()) params.set('stage', suggestionForm.stage.trim())
      if (suggestionForm.channel) params.set('channel', suggestionForm.channel)
      if (suggestionForm.q.trim()) params.set('q', suggestionForm.q.trim())

      const response = await apiRequest(`/api/admin/playbooks/suggestions?${params.toString()}`, { token, tenantId })
      setSuggestions(response.suggestions ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
      setSuggestions([])
    } finally {
      setSuggestionLoading(false)
    }
  }, [canSuggest, onNotify, suggestionForm, tenantId, token])

  useEffect(() => {
    loadRows()
  }, [loadRows, refreshKey])

  const openCreate = () => {
    if (!canCreate) {
      onNotify('You do not have permission to create playbooks.', 'warning')
      return
    }

    setEditingRow(null)
    setForm(createEmptyForm())
    setEditorOpen(true)
  }

  const openEdit = (row) => {
    if (!canUpdate) {
      onNotify('You do not have permission to update playbooks.', 'warning')
      return
    }

    setEditingRow(row)
    setForm({
      name: row.name ?? '',
      industry: row.industry ?? '',
      stage: row.stage ?? '',
      channel: row.channel ?? '',
      is_active: Boolean(row.is_active),
      scriptsText: Array.isArray(row.scripts) ? row.scripts.join('\n') : '',
      objectionsText: objectionsToText(row.objections),
      emailTemplate: templateContent(row.templates, 'email'),
      smsTemplate: templateContent(row.templates, 'sms'),
      whatsappTemplate: templateContent(row.templates, 'whatsapp'),
    })
    setEditorOpen(true)
  }

  const saveForm = async () => {
    if (editingRow && !canUpdate) {
      onNotify('You do not have permission to update playbooks.', 'warning')
      return
    }

    if (!editingRow && !canCreate) {
      onNotify('You do not have permission to create playbooks.', 'warning')
      return
    }

    if (form.name.trim() === '') {
      onNotify('Name is required.', 'warning')
      return
    }

    if (form.industry.trim() === '') {
      onNotify('Industry is required.', 'warning')
      return
    }

    const payload = {
      name: form.name.trim(),
      industry: form.industry.trim(),
      stage: form.stage.trim() || null,
      channel: form.channel || null,
      is_active: Boolean(form.is_active),
      scripts: splitLines(form.scriptsText),
      objections: objectionsFromText(form.objectionsText),
      templates: buildTemplates(form),
    }

    setSaving(true)
    try {
      if (editingRow) {
        await apiRequest(`/api/admin/playbooks/${editingRow.id}`, {
          method: 'PATCH',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Playbook updated.', 'success')
      } else {
        await apiRequest('/api/admin/playbooks', {
          method: 'POST',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Playbook created.', 'success')
      }

      setEditorOpen(false)
      setEditingRow(null)
      setForm(createEmptyForm())
      loadRows()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  const deleteRow = async (row) => {
    if (!canDelete) {
      onNotify('You do not have permission to delete playbooks.', 'warning')
      return
    }

    if (!window.confirm(`Delete playbook "${row.name}"?`)) {
      return
    }

    try {
      await apiRequest(`/api/admin/playbooks/${row.id}`, {
        method: 'DELETE',
        token,
        tenantId,
      })
      onNotify('Playbook deleted.', 'success')
      loadRows()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const loadStarters = async () => {
    if (!canCreate) {
      onNotify('You do not have permission to bootstrap playbooks.', 'warning')
      return
    }

    setBootstrapRunning(true)
    try {
      const response = await apiRequest('/api/admin/playbooks/bootstrap', {
        method: 'POST',
        token,
        tenantId,
        body: {
          industries: ['clinic', 'real_estate', 'restaurant'],
          overwrite: false,
        },
      })
      const created = response?.result?.created ?? 0
      const skipped = response?.result?.skipped ?? 0
      onNotify(`Starters loaded. Created: ${created}, skipped: ${skipped}.`, 'success')
      loadRows()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setBootstrapRunning(false)
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2} justifyContent="space-between">
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2}>
          <TextField
            size="small"
            label="Search"
            value={filters.search}
            onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
          />
          <TextField
            size="small"
            label="Industry"
            placeholder="clinic / real_estate / restaurant"
            value={filters.industry}
            onChange={(event) => setFilters((current) => ({ ...current, industry: event.target.value }))}
          />
          <TextField
            size="small"
            label="Stage"
            value={filters.stage}
            onChange={(event) => setFilters((current) => ({ ...current, stage: event.target.value }))}
          />
          <FormControl size="small" sx={{ minWidth: 130 }}>
            <InputLabel>Channel</InputLabel>
            <Select
              label="Channel"
              value={filters.channel}
              onChange={(event) => setFilters((current) => ({ ...current, channel: event.target.value }))}
            >
              {CHANNEL_OPTIONS.map((option) => (
                <MenuItem key={option || 'all'} value={option}>
                  {option || 'all'}
                </MenuItem>
              ))}
            </Select>
          </FormControl>
          <FormControl size="small" sx={{ minWidth: 120 }}>
            <InputLabel>Active</InputLabel>
            <Select
              label="Active"
              value={filters.is_active}
              onChange={(event) => setFilters((current) => ({ ...current, is_active: event.target.value }))}
            >
              <MenuItem value="">all</MenuItem>
              <MenuItem value="1">yes</MenuItem>
              <MenuItem value="0">no</MenuItem>
            </Select>
          </FormControl>
          <Button variant="outlined" startIcon={<RefreshIcon />} onClick={loadRows}>
            Refresh
          </Button>
        </Stack>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
          {canCreate && (
            <>
              <Button variant="outlined" onClick={loadStarters} disabled={bootstrapRunning}>
                {bootstrapRunning ? 'Loading starters...' : 'Load Industry Starters'}
              </Button>
              <Button variant="contained" startIcon={<AddIcon />} onClick={openCreate}>
                New Playbook
              </Button>
            </>
          )}
        </Stack>
      </Stack>

      <Card>
        <CardContent>
          <Typography variant="h6">Knowledge Base & Playbooks</Typography>
          <Typography variant="caption" color="text.secondary">
            Scripts, objections, and templates by industry/stage.
          </Typography>
          <Divider sx={{ my: 1.2 }} />
          <Stack spacing={1}>
            {loading && <Typography color="text.secondary">Loading playbooks...</Typography>}
            {!loading && rows.length === 0 && <Typography color="text.secondary">No playbooks found.</Typography>}
            {rows.map((row) => (
              <Paper key={row.id} variant="outlined" sx={{ p: 1.2 }}>
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2} justifyContent="space-between">
                  <Box>
                    <Typography variant="subtitle2">{row.name}</Typography>
                    <Typography variant="caption" color="text.secondary">
                      Industry: {row.industry} | Stage: {row.stage || 'any'} | Channel: {row.channel || 'any'} | Active: {row.is_active ? 'yes' : 'no'}
                    </Typography>
                    <Typography variant="caption" color="text.secondary" display="block">
                      Updated: {formatDate(row.updated_at)}
                    </Typography>
                  </Box>
                  <Stack direction="row" spacing={0.5}>
                    {canUpdate && (
                      <Button size="small" startIcon={<EditIcon />} onClick={() => openEdit(row)}>
                        Edit
                      </Button>
                    )}
                    {canDelete && (
                      <Button size="small" color="error" startIcon={<DeleteIcon />} onClick={() => deleteRow(row)}>
                        Delete
                      </Button>
                    )}
                  </Stack>
                </Stack>
              </Paper>
            ))}
          </Stack>
        </CardContent>
      </Card>

      {canSuggest && (
        <Card>
          <CardContent>
            <Typography variant="h6">Suggestion Preview</Typography>
            <Typography variant="caption" color="text.secondary">
              Simulate contextual suggestions for deal stage or conversation.
            </Typography>
            <Divider sx={{ my: 1.2 }} />
            <Grid container spacing={1.2}>
              <Grid size={{ xs: 12, sm: 6, md: 2.5 }}>
                <TextField
                  size="small"
                  fullWidth
                  label="Lead ID (optional)"
                  value={suggestionForm.lead_id}
                  onChange={(event) => setSuggestionForm((current) => ({ ...current, lead_id: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 2.5 }}>
                <TextField
                  size="small"
                  fullWidth
                  label="Industry"
                  value={suggestionForm.industry}
                  onChange={(event) => setSuggestionForm((current) => ({ ...current, industry: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 2.5 }}>
                <TextField
                  size="small"
                  fullWidth
                  label="Stage"
                  value={suggestionForm.stage}
                  onChange={(event) => setSuggestionForm((current) => ({ ...current, stage: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 2.5 }}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Channel</InputLabel>
                  <Select
                    label="Channel"
                    value={suggestionForm.channel}
                    onChange={(event) => setSuggestionForm((current) => ({ ...current, channel: event.target.value }))}
                  >
                    {CHANNEL_OPTIONS.map((option) => (
                      <MenuItem key={option || 'all'} value={option}>
                        {option || 'auto'}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, md: 10 }}>
                <TextField
                  size="small"
                  fullWidth
                  label="Conversation Query / Objection"
                  placeholder="e.g. budget is high"
                  value={suggestionForm.q}
                  onChange={(event) => setSuggestionForm((current) => ({ ...current, q: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, md: 2 }}>
                <Button fullWidth variant="contained" onClick={runSuggestionPreview} disabled={suggestionLoading}>
                  {suggestionLoading ? 'Running...' : 'Run'}
                </Button>
              </Grid>
            </Grid>

            <Stack spacing={1} sx={{ mt: 1.5 }}>
              {suggestions.length === 0 && (
                <Typography color="text.secondary">No suggestions yet.</Typography>
              )}
              {suggestions.map((item) => (
                <Paper key={`suggestion-${item.playbook_id}`} variant="outlined" sx={{ p: 1 }}>
                  <Typography variant="subtitle2">{item.name}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    score {item.score} | {item.industry} | {item.stage || 'any'} | {item.channel || 'any'}
                  </Typography>
                  {(item.scripts ?? []).length > 0 && (
                    <Typography variant="body2" sx={{ mt: 0.8 }}>
                      Script: {item.scripts[0]}
                    </Typography>
                  )}
                  {(item.objections ?? []).length > 0 && (
                    <Typography variant="body2" sx={{ mt: 0.5 }}>
                      Objection: {item.objections[0].objection} | Response: {item.objections[0].response || '-'}
                    </Typography>
                  )}
                </Paper>
              ))}
            </Stack>
          </CardContent>
        </Card>
      )}

      <Dialog open={editorOpen} onClose={() => setEditorOpen(false)} maxWidth="md" fullWidth>
        <DialogTitle>{editingRow ? `Edit Playbook #${editingRow.id}` : 'Create Playbook'}</DialogTitle>
        <DialogContent>
          <Stack spacing={1.4} sx={{ mt: 1 }}>
            <Grid container spacing={1.2}>
              <Grid size={{ xs: 12, md: 6 }}>
                <TextField
                  label="Playbook Name"
                  fullWidth
                  value={form.name}
                  onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, md: 6 }}>
                <TextField
                  label="Industry"
                  helperText="clinic / real_estate / restaurant"
                  fullWidth
                  value={form.industry}
                  onChange={(event) => setForm((current) => ({ ...current, industry: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, md: 4 }}>
                <TextField
                  label="Stage (optional)"
                  fullWidth
                  value={form.stage}
                  onChange={(event) => setForm((current) => ({ ...current, stage: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, md: 4 }}>
                <FormControl fullWidth>
                  <InputLabel>Channel</InputLabel>
                  <Select
                    label="Channel"
                    value={form.channel}
                    onChange={(event) => setForm((current) => ({ ...current, channel: event.target.value }))}
                  >
                    {CHANNEL_OPTIONS.map((option) => (
                      <MenuItem key={option || 'any'} value={option}>
                        {option || 'any'}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, md: 4 }}>
                <FormControl fullWidth>
                  <InputLabel>Active</InputLabel>
                  <Select
                    label="Active"
                    value={form.is_active ? '1' : '0'}
                    onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.value === '1' }))}
                  >
                    <MenuItem value="1">yes</MenuItem>
                    <MenuItem value="0">no</MenuItem>
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12 }}>
                <TextField
                  label="Scripts (one line per script)"
                  multiline
                  minRows={4}
                  fullWidth
                  value={form.scriptsText}
                  onChange={(event) => setForm((current) => ({ ...current, scriptsText: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12 }}>
                <TextField
                  label="Objections (format: objection => response, one per line)"
                  multiline
                  minRows={4}
                  fullWidth
                  value={form.objectionsText}
                  onChange={(event) => setForm((current) => ({ ...current, objectionsText: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12 }}>
                <TextField
                  label="Email Template"
                  multiline
                  minRows={3}
                  fullWidth
                  value={form.emailTemplate}
                  onChange={(event) => setForm((current) => ({ ...current, emailTemplate: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12 }}>
                <TextField
                  label="SMS Template"
                  multiline
                  minRows={2}
                  fullWidth
                  value={form.smsTemplate}
                  onChange={(event) => setForm((current) => ({ ...current, smsTemplate: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12 }}>
                <TextField
                  label="WhatsApp Template"
                  multiline
                  minRows={2}
                  fullWidth
                  value={form.whatsappTemplate}
                  onChange={(event) => setForm((current) => ({ ...current, whatsappTemplate: event.target.value }))}
                />
              </Grid>
            </Grid>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setEditorOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={saveForm} disabled={saving || (editingRow ? !canUpdate : !canCreate)}>
            {saving ? 'Saving...' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default PlaybooksPanel
