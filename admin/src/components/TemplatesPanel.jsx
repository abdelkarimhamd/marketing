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
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Add as AddIcon } from '@mui/icons-material'
import { apiRequest } from '../lib/api'

function TemplatesPanel({ token, tenantId, refreshKey, onNotify }) {
  const [templates, setTemplates] = useState([])
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [renderPreview, setRenderPreview] = useState(null)
  const [form, setForm] = useState({
    name: '',
    channel: 'email',
    subject: '',
    html: '',
    text: '',
    whatsapp_template_name: '',
    whatsapp_variables: '{\n  "first_name": "{{first_name}}"\n}',
  })

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
    setEditing(null)
    setRenderPreview(null)
    setForm({
      name: '',
      channel: 'email',
      subject: '',
      html: '',
      text: '',
      whatsapp_template_name: '',
      whatsapp_variables: '{\n  "first_name": "{{first_name}}"\n}',
    })
    setDialogOpen(true)
  }

  const openEdit = (template) => {
    setEditing(template)
    setRenderPreview(null)
    setForm({
      name: template.name ?? '',
      channel: template.channel ?? 'email',
      subject: template.subject ?? '',
      html: template.content ?? '',
      text: template.body_text ?? template.content ?? '',
      whatsapp_template_name: template.whatsapp_template_name ?? '',
      whatsapp_variables: JSON.stringify(template.whatsapp_variables ?? {}, null, 2),
    })
    setDialogOpen(true)
  }

  const save = async () => {
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
        payload.whatsapp_variables = JSON.parse(form.whatsapp_variables || '{}')
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
    if (!editing) return
    try {
      const response = await apiRequest(`/api/admin/templates/${editing.id}/render`, {
        method: 'POST',
        token,
        tenantId,
        body: {
          variables: {
            first_name: 'Demo',
            company: 'Smart Cedra',
            city: 'Riyadh',
            service: 'consulting',
          },
        },
      })
      setRenderPreview(response.rendered ?? response)
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction="row" justifyContent="space-between">
        <Typography variant="h5">Templates</Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={openNew}>
          New Template
        </Button>
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
                  <Button size="small" onClick={() => openEdit(template)}>
                    Edit
                  </Button>
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
                <TextField
                  label="WhatsApp Variables (JSON)"
                  multiline
                  minRows={7}
                  value={form.whatsapp_variables}
                  onChange={(event) => setForm((prev) => ({ ...prev, whatsapp_variables: event.target.value }))}
                />
              </>
            )}

            {editing && (
              <Button variant="outlined" onClick={previewTemplate}>
                Render Preview
              </Button>
            )}

            {renderPreview && (
              <Paper variant="outlined" sx={{ p: 1.4 }}>
                <Typography variant="subtitle2">Preview Payload</Typography>
                <Typography component="pre" sx={{ m: 0, mt: 0.6, whiteSpace: 'pre-wrap' }}>
                  {JSON.stringify(renderPreview, null, 2)}
                </Typography>
              </Paper>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialogOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={save}>
            Save
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default TemplatesPanel
