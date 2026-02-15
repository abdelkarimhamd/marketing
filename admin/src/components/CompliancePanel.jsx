import { useCallback, useEffect, useState } from 'react'
import {
  Button,
  Card,
  CardContent,
  Checkbox,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControl,
  FormControlLabel,
  Grid,
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
import { Add as AddIcon } from '@mui/icons-material'
import { apiRequest } from '../lib/api'

function emptyRuleForm() {
  return {
    country_code: '',
    channel: 'email',
    sender_id: '',
    opt_out_keywords_text: '',
    template_only: false,
    note: '',
    is_active: true,
  }
}

function parseCommaList(text) {
  return String(text ?? '')
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item !== '')
}

function CompliancePanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [compliance, setCompliance] = useState({
    quiet_hours: {
      enabled: true,
      start: '22:00',
      end: '08:00',
      timezone: 'UTC',
    },
    frequency_caps: {
      email: 5,
      sms: 3,
      whatsapp: 2,
    },
  })
  const [countryRules, setCountryRules] = useState([])
  const [ruleDialogOpen, setRuleDialogOpen] = useState(false)
  const [editingRule, setEditingRule] = useState(null)
  const [ruleForm, setRuleForm] = useState(emptyRuleForm())
  const [savingSettings, setSavingSettings] = useState(false)
  const [savingRule, setSavingRule] = useState(false)
  const canManage = can('settings.update') || can('compliance.create') || can('compliance.update') || can('compliance.delete')

  const loadCompliance = useCallback(async () => {
    try {
      const response = await apiRequest('/api/admin/compliance', { token, tenantId })
      setCompliance({
        quiet_hours: {
          enabled: Boolean(response.compliance?.quiet_hours?.enabled ?? true),
          start: response.compliance?.quiet_hours?.start ?? '22:00',
          end: response.compliance?.quiet_hours?.end ?? '08:00',
          timezone: response.compliance?.quiet_hours?.timezone ?? 'UTC',
        },
        frequency_caps: {
          email: Number(response.compliance?.frequency_caps?.email ?? 0),
          sms: Number(response.compliance?.frequency_caps?.sms ?? 0),
          whatsapp: Number(response.compliance?.frequency_caps?.whatsapp ?? 0),
        },
      })
      setCountryRules(response.country_rules ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadCompliance()
  }, [loadCompliance, refreshKey])

  const saveSettings = async () => {
    if (!canManage) {
      onNotify('You do not have permission to update compliance settings.', 'warning')
      return
    }

    const payload = {
      quiet_hours: {
        enabled: Boolean(compliance.quiet_hours.enabled),
        start: compliance.quiet_hours.start,
        end: compliance.quiet_hours.end,
        timezone: compliance.quiet_hours.timezone,
      },
      frequency_caps: {
        email: Number(compliance.frequency_caps.email),
        sms: Number(compliance.frequency_caps.sms),
        whatsapp: Number(compliance.frequency_caps.whatsapp),
      },
    }

    setSavingSettings(true)
    try {
      await apiRequest('/api/admin/compliance', {
        method: 'PUT',
        token,
        tenantId,
        body: payload,
      })
      onNotify('Compliance settings saved.', 'success')
      loadCompliance()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavingSettings(false)
    }
  }

  const openNewRule = () => {
    if (!canManage) {
      onNotify('You do not have permission to manage compliance rules.', 'warning')
      return
    }

    setEditingRule(null)
    setRuleForm(emptyRuleForm())
    setRuleDialogOpen(true)
  }

  const openEditRule = (rule) => {
    if (!canManage) {
      onNotify('You do not have permission to manage compliance rules.', 'warning')
      return
    }

    setEditingRule(rule)
    setRuleForm({
      country_code: rule.country_code ?? '',
      channel: rule.channel ?? 'email',
      sender_id: rule.sender_id ?? '',
      opt_out_keywords_text: (rule.opt_out_keywords ?? []).join(', '),
      template_only: Boolean(rule.template_constraints?.template_only ?? false),
      note: String(rule.settings?.note ?? ''),
      is_active: Boolean(rule.is_active),
    })
    setRuleDialogOpen(true)
  }

  const saveRule = async () => {
    if (!canManage) {
      onNotify('You do not have permission to manage compliance rules.', 'warning')
      return
    }

    const payload = {
      country_code: ruleForm.country_code.trim().toUpperCase(),
      channel: ruleForm.channel,
      sender_id: ruleForm.sender_id.trim() || null,
      opt_out_keywords: parseCommaList(ruleForm.opt_out_keywords_text),
      template_constraints: {
        template_only: Boolean(ruleForm.template_only),
      },
      settings: ruleForm.note.trim() ? { note: ruleForm.note.trim() } : {},
      is_active: Boolean(ruleForm.is_active),
    }

    if (!payload.country_code) {
      onNotify('Country code is required.', 'warning')
      return
    }

    setSavingRule(true)
    try {
      if (editingRule) {
        await apiRequest(`/api/admin/compliance/country-rules/${editingRule.id}`, {
          method: 'PUT',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Country rule updated.', 'success')
      } else {
        await apiRequest('/api/admin/compliance/country-rules', {
          method: 'POST',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Country rule created.', 'success')
      }

      setRuleDialogOpen(false)
      setEditingRule(null)
      setRuleForm(emptyRuleForm())
      loadCompliance()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavingRule(false)
    }
  }

  const deleteRule = async (rule) => {
    if (!canManage) {
      onNotify('You do not have permission to manage compliance rules.', 'warning')
      return
    }

    if (!window.confirm(`Delete compliance rule ${rule.country_code}/${rule.channel}?`)) {
      return
    }

    try {
      await apiRequest(`/api/admin/compliance/country-rules/${rule.id}`, {
        method: 'DELETE',
        token,
        tenantId,
      })
      onNotify('Country rule deleted.', 'success')
      loadCompliance()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between">
        <Typography variant="h5">Compliance</Typography>
        {canManage && (
          <Button variant="contained" startIcon={<AddIcon />} onClick={openNewRule}>
            New Country Rule
          </Button>
        )}
      </Stack>

      <Card>
        <CardContent>
          <Typography variant="h6">Quiet Hours + Frequency Caps</Typography>
          <Divider sx={{ my: 1.2 }} />

          <Grid container spacing={1.2}>
            <Grid size={{ xs: 12, md: 3 }}>
              <FormControlLabel
                control={(
                  <Checkbox
                    checked={Boolean(compliance.quiet_hours.enabled)}
                    onChange={(event) =>
                      setCompliance((current) => ({
                        ...current,
                        quiet_hours: {
                          ...current.quiet_hours,
                          enabled: event.target.checked,
                        },
                      }))
                    }
                  />
                )}
                label="Quiet Hours Enabled"
              />
            </Grid>
            <Grid size={{ xs: 12, md: 3 }}>
              <TextField
                fullWidth
                size="small"
                label="Start"
                type="time"
                InputLabelProps={{ shrink: true }}
                value={compliance.quiet_hours.start}
                onChange={(event) =>
                  setCompliance((current) => ({
                    ...current,
                    quiet_hours: {
                      ...current.quiet_hours,
                      start: event.target.value,
                    },
                  }))
                }
              />
            </Grid>
            <Grid size={{ xs: 12, md: 3 }}>
              <TextField
                fullWidth
                size="small"
                label="End"
                type="time"
                InputLabelProps={{ shrink: true }}
                value={compliance.quiet_hours.end}
                onChange={(event) =>
                  setCompliance((current) => ({
                    ...current,
                    quiet_hours: {
                      ...current.quiet_hours,
                      end: event.target.value,
                    },
                  }))
                }
              />
            </Grid>
            <Grid size={{ xs: 12, md: 3 }}>
              <TextField
                fullWidth
                size="small"
                label="Timezone"
                value={compliance.quiet_hours.timezone}
                onChange={(event) =>
                  setCompliance((current) => ({
                    ...current,
                    quiet_hours: {
                      ...current.quiet_hours,
                      timezone: event.target.value,
                    },
                  }))
                }
              />
            </Grid>

            <Grid size={{ xs: 12, md: 4 }}>
              <TextField
                fullWidth
                size="small"
                type="number"
                label="Email cap (per week)"
                value={compliance.frequency_caps.email}
                onChange={(event) =>
                  setCompliance((current) => ({
                    ...current,
                    frequency_caps: {
                      ...current.frequency_caps,
                      email: event.target.value,
                    },
                  }))
                }
              />
            </Grid>
            <Grid size={{ xs: 12, md: 4 }}>
              <TextField
                fullWidth
                size="small"
                type="number"
                label="SMS cap (per week)"
                value={compliance.frequency_caps.sms}
                onChange={(event) =>
                  setCompliance((current) => ({
                    ...current,
                    frequency_caps: {
                      ...current.frequency_caps,
                      sms: event.target.value,
                    },
                  }))
                }
              />
            </Grid>
            <Grid size={{ xs: 12, md: 4 }}>
              <TextField
                fullWidth
                size="small"
                type="number"
                label="WhatsApp cap (per week)"
                value={compliance.frequency_caps.whatsapp}
                onChange={(event) =>
                  setCompliance((current) => ({
                    ...current,
                    frequency_caps: {
                      ...current.frequency_caps,
                      whatsapp: event.target.value,
                    },
                  }))
                }
              />
            </Grid>
          </Grid>

          <Stack direction="row" sx={{ mt: 1.4 }}>
            <Button variant="contained" onClick={saveSettings} disabled={savingSettings || !canManage}>
              {savingSettings ? 'Saving...' : 'Save Compliance Settings'}
            </Button>
          </Stack>
        </CardContent>
      </Card>

      <Card>
        <CardContent sx={{ p: 0 }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Country</TableCell>
                <TableCell>Channel</TableCell>
                <TableCell>Sender ID</TableCell>
                <TableCell>Keywords</TableCell>
                <TableCell>Template-only</TableCell>
                <TableCell>Active</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {countryRules.map((rule) => (
                <TableRow key={rule.id}>
                  <TableCell>{rule.country_code}</TableCell>
                  <TableCell>{rule.channel}</TableCell>
                  <TableCell>{rule.sender_id ?? '-'}</TableCell>
                  <TableCell>{(rule.opt_out_keywords ?? []).join(', ') || '-'}</TableCell>
                  <TableCell>{rule.template_constraints?.template_only ? 'yes' : 'no'}</TableCell>
                  <TableCell>{rule.is_active ? 'yes' : 'no'}</TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={1} justifyContent="flex-end">
                      {canManage && (
                        <>
                          <Button size="small" onClick={() => openEditRule(rule)}>
                            Edit
                          </Button>
                          <Button size="small" color="error" onClick={() => deleteRule(rule)}>
                            Delete
                          </Button>
                        </>
                      )}
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
              {countryRules.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7}>
                    <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                      No country compliance rules.
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={ruleDialogOpen} onClose={() => setRuleDialogOpen(false)} maxWidth="md" fullWidth>
        <DialogTitle>{editingRule ? 'Edit Country Rule' : 'Create Country Rule'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2}>
              <TextField
                size="small"
                label="Country Code (e.g. SA)"
                value={ruleForm.country_code}
                onChange={(event) => setRuleForm((current) => ({ ...current, country_code: event.target.value }))}
              />
              <FormControl size="small" sx={{ minWidth: 160 }}>
                <InputLabel>Channel</InputLabel>
                <Select
                  label="Channel"
                  value={ruleForm.channel}
                  onChange={(event) => setRuleForm((current) => ({ ...current, channel: event.target.value }))}
                >
                  <MenuItem value="email">email</MenuItem>
                  <MenuItem value="sms">sms</MenuItem>
                  <MenuItem value="whatsapp">whatsapp</MenuItem>
                </Select>
              </FormControl>
              <TextField
                size="small"
                label="Sender ID"
                value={ruleForm.sender_id}
                onChange={(event) => setRuleForm((current) => ({ ...current, sender_id: event.target.value }))}
              />
              <FormControlLabel
                control={(
                  <Checkbox
                    checked={ruleForm.is_active}
                    onChange={(event) => setRuleForm((current) => ({ ...current, is_active: event.target.checked }))}
                  />
                )}
                label="Active"
              />
            </Stack>

            <TextField
              label="Opt-out Keywords (comma separated)"
              value={ruleForm.opt_out_keywords_text}
              onChange={(event) =>
                setRuleForm((current) => ({ ...current, opt_out_keywords_text: event.target.value }))
              }
            />
            <FormControlLabel
              control={(
                <Checkbox
                  checked={ruleForm.template_only}
                  onChange={(event) => setRuleForm((current) => ({ ...current, template_only: event.target.checked }))}
                />
              )}
              label="Only allow approved templates (block free-text sends)"
            />
            <TextField
              label="Note (optional)"
              value={ruleForm.note}
              onChange={(event) => setRuleForm((current) => ({ ...current, note: event.target.value }))}
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRuleDialogOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={saveRule} disabled={savingRule || !canManage}>
            {savingRule ? 'Saving...' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default CompliancePanel
