import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  CardContent,
  Divider,
  Grid,
  MenuItem,
  Paper,
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
import { apiRequest } from '../lib/api'

const ACTIONS = ['replace_text', 'set_href', 'set_attr', 'hide', 'show']

function emptyRule() {
  return {
    name: '',
    path_contains: '',
    utm_source: '',
    selector: '[data-personalize]',
    action: 'replace_text',
    value: '',
    attr: '',
    priority: 100,
  }
}

function PersonalizationPanel({ token, tenantId, refreshKey, onNotify, can = () => true }) {
  const [rules, setRules] = useState([])
  const [form, setForm] = useState(emptyRule())
  const [saving, setSaving] = useState(false)
  const [preview, setPreview] = useState(null)
  const canCreate = can('personalization.create')

  const loadRules = useCallback(async () => {
    if (!tenantId) return
    try {
      const response = await apiRequest('/api/admin/personalization/rules', { token, tenantId })
      setRules(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadRules()
  }, [loadRules, refreshKey])

  const createRule = async () => {
    if (!canCreate) {
      onNotify('You do not have permission to create personalization rules.', 'warning')
      return
    }

    if (!form.name.trim()) {
      onNotify('Rule name is required.', 'warning')
      return
    }

    setSaving(true)
    try {
      await apiRequest('/api/admin/personalization/rules', {
        method: 'POST',
        token,
        tenantId,
        body: {
          name: form.name,
          priority: Number(form.priority || 100),
          enabled: true,
          match_rules_json: {
            path_contains: form.path_contains ? [form.path_contains] : [],
            utm_source: form.utm_source ? [form.utm_source] : [],
          },
          variants: [
            {
              variant_key: 'a',
              weight: 100,
              is_control: false,
              changes_json: [
                {
                  selector: form.selector,
                  action: form.action,
                  value: form.value,
                  attr: form.attr || null,
                },
              ],
            },
          ],
        },
      })
      onNotify('Rule created successfully.', 'success')
      setForm(emptyRule())
      loadRules()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  const latestRule = useMemo(() => rules[0] ?? null, [rules])

  const runPreview = async () => {
    if (!latestRule) {
      onNotify('Create or select a rule first.', 'warning')
      return
    }

    try {
      const response = await apiRequest(`/api/admin/personalization/rules/${latestRule.id}/preview`, {
        method: 'POST',
        token,
        tenantId,
        body: {
          path: form.path_contains || '/demo',
          visitor_id: 'preview_visitor',
          source: form.utm_source || 'ad',
          device: 'desktop',
          utm: {
            utm_source: form.utm_source || 'ad',
          },
        },
      })
      setPreview(response.preview ?? null)
    } catch (error) {
      onNotify(error.message, 'error')
      setPreview(null)
    }
  }

  if (!tenantId) {
    return <Alert severity="info">Select a tenant to manage personalization.</Alert>
  }

  return (
    <Stack spacing={2}>
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 5 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">New Rule</Typography>
              <Stack spacing={1.2} sx={{ mt: 1 }}>
                <TextField label="Rule Name" value={form.name} onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))} />
                <TextField label="Path Contains" value={form.path_contains} onChange={(event) => setForm((prev) => ({ ...prev, path_contains: event.target.value }))} />
                <TextField label="UTM Source" value={form.utm_source} onChange={(event) => setForm((prev) => ({ ...prev, utm_source: event.target.value }))} />
                <TextField label="Selector" value={form.selector} onChange={(event) => setForm((prev) => ({ ...prev, selector: event.target.value }))} />
                <Select value={form.action} onChange={(event) => setForm((prev) => ({ ...prev, action: event.target.value }))}>
                  {ACTIONS.map((action) => (
                    <MenuItem key={action} value={action}>{action}</MenuItem>
                  ))}
                </Select>
                <TextField label="Value" value={form.value} onChange={(event) => setForm((prev) => ({ ...prev, value: event.target.value }))} />
                <TextField label="Attr (for set_attr)" value={form.attr} onChange={(event) => setForm((prev) => ({ ...prev, attr: event.target.value }))} />
                <TextField type="number" label="Priority" value={form.priority} onChange={(event) => setForm((prev) => ({ ...prev, priority: event.target.value }))} />
                <Stack direction="row" spacing={1}>
                  <Button variant="contained" onClick={createRule} disabled={saving || !canCreate}>Save Rule</Button>
                  <Button variant="outlined" onClick={runPreview}>Preview</Button>
                </Stack>
              </Stack>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 7 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Name</TableCell>
                    <TableCell>Priority</TableCell>
                    <TableCell>Enabled</TableCell>
                    <TableCell>Variants</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {rules.map((rule) => (
                    <TableRow key={rule.id}>
                      <TableCell>{rule.name}</TableCell>
                      <TableCell>{rule.priority}</TableCell>
                      <TableCell>{rule.enabled ? 'Yes' : 'No'}</TableCell>
                      <TableCell>{(rule.variants ?? []).length}</TableCell>
                    </TableRow>
                  ))}
                  {rules.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={4}><Typography align="center" color="text.secondary" sx={{ py: 2 }}>No rules configured.</Typography></TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card sx={{ mt: 2 }}>
            <CardContent>
              <Typography variant="subtitle1">Preview Result</Typography>
              <Divider sx={{ my: 1 }} />
              {!preview && <Typography color="text.secondary">Run preview to see matched variant and patch.</Typography>}
              {preview && (
                <Stack spacing={1}>
                  <Typography variant="body2">Matched: {preview.matched ? 'Yes' : 'No'}</Typography>
                  <Typography variant="body2">Variant: {preview.variant?.key || '-'}</Typography>
                  {(preview.variant?.patch ?? []).map((change, index) => (
                    <Paper key={`${change.selector}-${index}`} variant="outlined" sx={{ p: 1 }}>
                      <Typography variant="caption">
                        {change.selector} ? {change.action} {change.attr ? `(${change.attr})` : ''}
                      </Typography>
                    </Paper>
                  ))}
                </Stack>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </Stack>
  )
}

export default PersonalizationPanel
