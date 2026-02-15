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
  Grid,
  IconButton,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon } from '@mui/icons-material'
import { apiRequest } from '../lib/api'

function createVariableRow(key = '', value = '') {
  return { key, value }
}

function variablesToRows(variables) {
  const entries = Object.entries(variables ?? {})
  if (entries.length === 0) return [createVariableRow('first_name', '{{first_name}}')]
  return entries.map(([key, value]) => createVariableRow(String(key), String(value ?? '')))
}

function rowsToVariables(rows) {
  return rows.reduce((result, row) => {
    const key = String(row.key ?? '').trim()
    if (key === '') return result
    result[key] = String(row.value ?? '')
    return result
  }, {})
}

function TemplatesPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [templates, setTemplates] = useState([])
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [renderPreview, setRenderPreview] = useState(null)
  const [previewLeadId, setPreviewLeadId] = useState('')
  const [form, setForm] = useState({
    name: '',
    channel: 'email',
    subject: '',
    html: '',
    text: '',
    whatsapp_template_name: '',
    whatsappVariables: [createVariableRow('first_name', '{{first_name}}')],
  })
  const canCreate = can('templates.create')
  const canUpdate = can('templates.update')
  const canSend = can('templates.send')
  const previewPayload = renderPreview && typeof renderPreview === 'object' ? renderPreview : null
  const preview = previewPayload && typeof previewPayload.rendered === 'object'
    ? previewPayload.rendered
    : (previewPayload ?? null)
  const personalization = previewPayload && typeof previewPayload.personalization === 'object'
    ? previewPayload.personalization
    : null

  const loadTemplates = useCallback(async () => {
    try {
      const response = await apiRequest('/api/admin/templates?per_page=100', { token, tenantId })
      setTemplates(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadTemplates()
  }, [loadTemplates, refreshKey])

  const openNew = () => {
    if (!canCreate) {
      onNotify('You do not have permission to create templates.', 'warning')
      return
    }

    setEditing(null)
    setRenderPreview(null)
    setPreviewLeadId('')
    setForm({
      name: '',
      channel: 'email',
      subject: '',
      html: '',
      text: '',
      whatsapp_template_name: '',
      whatsappVariables: [createVariableRow('first_name', '{{first_name}}')],
    })
    setDialogOpen(true)
  }

  const openEdit = (template) => {
    if (!canUpdate) {
      onNotify('You do not have permission to update templates.', 'warning')
      return
    }

    setEditing(template)
    setRenderPreview(null)
    setPreviewLeadId('')
    setForm({
      name: template.name ?? '',
      channel: template.channel ?? 'email',
      subject: template.subject ?? '',
      html: template.content ?? '',
      text: template.body_text ?? template.content ?? '',
      whatsapp_template_name: template.whatsapp_template_name ?? '',
      whatsappVariables: variablesToRows(template.whatsapp_variables),
    })
    setDialogOpen(true)
  }

  const updateVariable = (index, key, value) => {
    setForm((current) => ({
      ...current,
      whatsappVariables: current.whatsappVariables.map((row, rowIndex) => (
        rowIndex === index ? { ...row, [key]: value } : row
      )),
    }))
  }

  const addVariable = () => {
    if (!canUpdate && editing) {
      onNotify('You do not have permission to update templates.', 'warning')
      return
    }

    if (!canCreate && !editing) {
      onNotify('You do not have permission to create templates.', 'warning')
      return
    }

    setForm((current) => ({
      ...current,
      whatsappVariables: [...current.whatsappVariables, createVariableRow()],
    }))
  }

  const removeVariable = (index) => {
    if (!canUpdate && editing) {
      onNotify('You do not have permission to update templates.', 'warning')
      return
    }

    if (!canCreate && !editing) {
      onNotify('You do not have permission to create templates.', 'warning')
      return
    }

    setForm((current) => {
      const nextRows = current.whatsappVariables.filter((_, rowIndex) => rowIndex !== index)
      return {
        ...current,
        whatsappVariables: nextRows.length > 0 ? nextRows : [createVariableRow()],
      }
    })
  }

  const save = async () => {
    if (editing && !canUpdate) {
      onNotify('You do not have permission to update templates.', 'warning')
      return
    }

    if (!editing && !canCreate) {
      onNotify('You do not have permission to create templates.', 'warning')
      return
    }

    try {
      const payload = {
        name: form.name,
        channel: form.channel,
      }

      if (form.channel === 'email') {
        payload.subject = form.subject
        payload.html = form.html
      } else if (form.channel === 'sms') {
        payload.text = form.text
      } else {
        payload.whatsapp_template_name = form.whatsapp_template_name
        payload.whatsapp_variables = rowsToVariables(form.whatsappVariables)
      }

      if (editing) {
        await apiRequest(`/api/admin/templates/${editing.id}`, {
          method: 'PATCH',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Template updated.', 'success')
      } else {
        await apiRequest('/api/admin/templates', {
          method: 'POST',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Template created.', 'success')
      }

      setDialogOpen(false)
      loadTemplates()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const previewTemplate = async () => {
    if (!canSend) {
      onNotify('You do not have permission to render template previews.', 'warning')
      return
    }

    if (!editing) return
    try {
      const previewLead = Number(previewLeadId)
      const payload = {
        variables: {
          first_name: 'Demo',
          company: 'Smart Cedra',
          city: 'Riyadh',
          service: 'consulting',
          locale: 'en',
        },
      }

      if (previewLeadId.trim() !== '' && Number.isFinite(previewLead) && previewLead > 0) {
        payload.lead_id = previewLead
      }

      const response = await apiRequest(`/api/admin/templates/${editing.id}/render`, {
        method: 'POST',
        token,
        tenantId,
        body: payload,
      })
      setRenderPreview({
        rendered: response.rendered ?? response,
        personalization: response.personalization ?? null,
      })
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction="row" justifyContent="space-between">
        <Typography variant="h5">Templates</Typography>
        {canCreate && (
          <Button variant="contained" startIcon={<AddIcon />} onClick={openNew}>
            New Template
          </Button>
        )}
      </Stack>

      <Grid container spacing={1.6}>
        {templates.map((template) => (
          <Grid key={template.id} size={{ xs: 12, md: 6 }}>
            <Card>
              <CardContent>
                <Stack direction="row" justifyContent="space-between" alignItems="center">
                  <Typography variant="h6">{template.name}</Typography>
                  <Typography variant="caption">{template.channel}</Typography>
                </Stack>
                <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                  {template.subject ?? template.whatsapp_template_name ?? template.content ?? '-'}
                </Typography>
                <Stack direction="row" spacing={1} sx={{ mt: 1.5 }}>
                  {canUpdate && (
                    <Button size="small" onClick={() => openEdit(template)}>
                      Edit
                    </Button>
                  )}
                </Stack>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="md" fullWidth>
        <DialogTitle>{editing ? 'Edit Template' : 'New Template'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField label="Name" value={form.name} onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))} />
            <FormControl size="small">
              <InputLabel>Channel</InputLabel>
              <Select
                label="Channel"
                value={form.channel}
                onChange={(event) => setForm((prev) => ({ ...prev, channel: event.target.value }))}
              >
                <MenuItem value="email">email</MenuItem>
                <MenuItem value="sms">sms</MenuItem>
                <MenuItem value="whatsapp">whatsapp</MenuItem>
              </Select>
            </FormControl>

            {form.channel === 'email' && (
              <>
                <TextField label="Subject" value={form.subject} onChange={(event) => setForm((prev) => ({ ...prev, subject: event.target.value }))} />
                <TextField
                  label="HTML"
                  multiline
                  minRows={7}
                  value={form.html}
                  onChange={(event) => setForm((prev) => ({ ...prev, html: event.target.value }))}
                />
              </>
            )}

            {form.channel === 'sms' && (
              <TextField
                label="SMS Text"
                multiline
                minRows={5}
                value={form.text}
                onChange={(event) => setForm((prev) => ({ ...prev, text: event.target.value }))}
              />
            )}

            {form.channel === 'whatsapp' && (
              <>
                <TextField
                  label="WhatsApp Template Name"
                  value={form.whatsapp_template_name}
                  onChange={(event) => setForm((prev) => ({ ...prev, whatsapp_template_name: event.target.value }))}
                />
                <Stack direction="row" justifyContent="space-between" alignItems="center">
                  <Typography variant="subtitle2">WhatsApp Variables</Typography>
                <Button size="small" variant="outlined" onClick={addVariable}>
                  Add Variable
                </Button>
                </Stack>
                {form.whatsappVariables.map((row, index) => (
                  <Stack key={index} direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ xs: 'stretch', sm: 'center' }}>
                    <TextField
                      size="small"
                      label="Variable Key"
                      value={row.key}
                      onChange={(event) => updateVariable(index, 'key', event.target.value)}
                      sx={{ minWidth: 200 }}
                    />
                    <TextField
                      size="small"
                      label="Value / Token"
                      value={row.value}
                      onChange={(event) => updateVariable(index, 'value', event.target.value)}
                      fullWidth
                    />
                    <IconButton color="error" onClick={() => removeVariable(index)} disabled={form.whatsappVariables.length === 1}>
                      <DeleteIcon />
                    </IconButton>
                  </Stack>
                ))}
              </>
            )}

            {editing && canSend && (
              <Stack spacing={1}>
                <TextField
                  size="small"
                  label="Preview Lead ID (optional)"
                  value={previewLeadId}
                  onChange={(event) => setPreviewLeadId(event.target.value)}
                  placeholder="Use saved lead id for per-lead preview"
                />
                <Button variant="outlined" onClick={previewTemplate}>
                  Render Preview
                </Button>
              </Stack>
            )}

            {editing && !canSend && (
              <Stack spacing={1}>
                <TextField
                  size="small"
                  label="Preview Lead ID (optional)"
                  value={previewLeadId}
                  onChange={(event) => setPreviewLeadId(event.target.value)}
                  placeholder="Use saved lead id for per-lead preview"
                  disabled
                />
                <Button variant="outlined" disabled>
                  Render Preview
                </Button>
              </Stack>
            )}

            {preview && (
              <Paper variant="outlined" sx={{ p: 1.4 }}>
                <Typography variant="subtitle2">Preview</Typography>
                {'subject' in preview && (
                  <Typography variant="body2" sx={{ mt: 0.6 }}>
                    <strong>Subject:</strong> {preview.subject}
                  </Typography>
                )}
                {'html' in preview && (
                  <Typography variant="body2" sx={{ mt: 0.6 }}>
                    <strong>HTML:</strong> {preview.html}
                  </Typography>
                )}
                {'text' in preview && (
                  <Typography variant="body2" sx={{ mt: 0.6 }}>
                    <strong>Text:</strong> {preview.text}
                  </Typography>
                )}
                {'template_name' in preview && (
                  <Typography variant="body2" sx={{ mt: 0.6 }}>
                    <strong>Template Name:</strong> {preview.template_name}
                  </Typography>
                )}
                {'variables' in preview && (
                  <Stack spacing={0.4} sx={{ mt: 0.6 }}>
                    <Typography variant="body2"><strong>Variables:</strong></Typography>
                    {Object.entries(preview.variables ?? {}).map(([key, value]) => (
                      <Typography key={key} variant="body2" color="text.secondary">
                        {key}: {String(value)}
                      </Typography>
                    ))}
                  </Stack>
                )}
                {personalization && (
                  <Stack spacing={0.4} sx={{ mt: 0.8 }}>
                    <Typography variant="body2"><strong>Locale:</strong> {String(personalization.locale ?? '-')}</Typography>
                    <Typography variant="body2">
                      <strong>Conditions:</strong> {String(personalization.conditions?.matched ?? 0)} matched / {String(personalization.conditions?.evaluated ?? 0)} checked
                    </Typography>
                    <Typography variant="body2">
                      <strong>Language Blocks:</strong> {String(personalization.localization?.matched ?? 0)} matched / {String(personalization.localization?.evaluated ?? 0)} checked
                    </Typography>
                    {Array.isArray(personalization.fallbacks_used) && personalization.fallbacks_used.length > 0 && (
                      <Typography variant="body2">
                        <strong>Fallbacks:</strong> {personalization.fallbacks_used.map((entry) => `${entry.key}->${entry.fallback}`).join(', ')}
                      </Typography>
                    )}
                    {Array.isArray(personalization.missing_variables) && personalization.missing_variables.length > 0 && (
                      <Typography variant="body2" color="warning.main">
                        <strong>Missing Variables:</strong> {personalization.missing_variables.join(', ')}
                      </Typography>
                    )}
                  </Stack>
                )}
              </Paper>
            )}

            <Typography variant="caption" color="text.secondary">
              Personalization syntax: {'{{first_name|Customer}}'}, {'{{#if city=Riyadh}}...{{else}}...{{/if}}'}, {'{{#lang ar}}...{{/lang}}{{#lang en}}...{{/lang}}'}
            </Typography>
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

export default TemplatesPanel
