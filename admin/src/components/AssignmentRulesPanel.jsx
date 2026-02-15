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
  FormControl,
  FormControlLabel,
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

const STRATEGIES = [
  { value: 'round_robin', label: 'round_robin' },
  { value: 'city', label: 'city' },
  { value: 'interest_service', label: 'interest_service' },
]

function parseCommaList(text) {
  return String(text ?? '')
    .split(',')
    .map((item) => item.trim().toLowerCase())
    .filter(Boolean)
}

function emptyForm() {
  return {
    name: '',
    strategy: 'round_robin',
    priority: 100,
    is_active: true,
    auto_assign_on_intake: true,
    auto_assign_on_import: true,
    team_id: '',
    fallback_owner_id: '',
    citiesText: '',
    interestsText: '',
    servicesText: '',
  }
}

function AssignmentRulesPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [rules, setRules] = useState([])
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(emptyForm())
  const [saving, setSaving] = useState(false)
  const canCreate = can('assignment_rules.create')
  const canUpdate = can('assignment_rules.update')
  const canDelete = can('assignment_rules.delete')

  const loadRules = useCallback(async () => {
    try {
      const response = await apiRequest('/api/admin/assignment-rules', { token, tenantId })
      setRules(response.rules ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadRules()
  }, [loadRules, refreshKey])

  const openNew = () => {
    if (!canCreate) {
      onNotify('You do not have permission to create assignment rules.', 'warning')
      return
    }

    setEditing(null)
    setForm(emptyForm())
    setDialogOpen(true)
  }

  const openEdit = (rule) => {
    if (!canUpdate) {
      onNotify('You do not have permission to update assignment rules.', 'warning')
      return
    }

    setEditing(rule)
    setForm({
      name: rule.name ?? '',
      strategy: rule.strategy ?? 'round_robin',
      priority: Number(rule.priority ?? 100),
      is_active: Boolean(rule.is_active),
      auto_assign_on_intake: Boolean(rule.auto_assign_on_intake),
      auto_assign_on_import: Boolean(rule.auto_assign_on_import),
      team_id: rule.team_id ? String(rule.team_id) : '',
      fallback_owner_id: rule.fallback_owner_id ? String(rule.fallback_owner_id) : '',
      citiesText: (rule.conditions?.cities ?? []).join(', '),
      interestsText: (rule.conditions?.interests ?? []).join(', '),
      servicesText: (rule.conditions?.services ?? []).join(', '),
    })
    setDialogOpen(true)
  }

  const saveRule = async () => {
    if (editing && !canUpdate) {
      onNotify('You do not have permission to update assignment rules.', 'warning')
      return
    }

    if (!editing && !canCreate) {
      onNotify('You do not have permission to create assignment rules.', 'warning')
      return
    }

    const conditions = {}

    if (form.strategy === 'city') {
      const cities = parseCommaList(form.citiesText)
      if (cities.length === 0) {
        onNotify('Enter at least one city for city strategy.', 'warning')
        return
      }
      conditions.cities = cities
    }

    if (form.strategy === 'interest_service') {
      const interests = parseCommaList(form.interestsText)
      const services = parseCommaList(form.servicesText)

      if (interests.length === 0 && services.length === 0) {
        onNotify('Enter at least one interest or service for interest_service strategy.', 'warning')
        return
      }

      if (interests.length > 0) conditions.interests = interests
      if (services.length > 0) conditions.services = services
    }

    const payload = {
      name: form.name.trim(),
      strategy: form.strategy,
      priority: Number(form.priority),
      is_active: Boolean(form.is_active),
      auto_assign_on_intake: Boolean(form.auto_assign_on_intake),
      auto_assign_on_import: Boolean(form.auto_assign_on_import),
      team_id: form.team_id.trim() === '' ? null : Number(form.team_id),
      fallback_owner_id: form.fallback_owner_id.trim() === '' ? null : Number(form.fallback_owner_id),
      conditions,
      settings: {},
    }

    if (!payload.name) {
      onNotify('Rule name is required.', 'warning')
      return
    }

    if (!Number.isInteger(payload.priority) || payload.priority <= 0) {
      onNotify('Priority must be a positive integer.', 'warning')
      return
    }

    if (payload.team_id !== null && (!Number.isInteger(payload.team_id) || payload.team_id <= 0)) {
      onNotify('Team ID must be a positive integer.', 'warning')
      return
    }

    if (
      payload.fallback_owner_id !== null
      && (!Number.isInteger(payload.fallback_owner_id) || payload.fallback_owner_id <= 0)
    ) {
      onNotify('Fallback owner ID must be a positive integer.', 'warning')
      return
    }

    setSaving(true)
    try {
      if (editing) {
        await apiRequest(`/api/admin/assignment-rules/${editing.id}`, {
          method: 'PATCH',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Assignment rule updated.', 'success')
      } else {
        await apiRequest('/api/admin/assignment-rules', {
          method: 'POST',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Assignment rule created.', 'success')
      }

      setDialogOpen(false)
      setEditing(null)
      setForm(emptyForm())
      loadRules()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  const removeRule = async (rule) => {
    if (!canDelete) {
      onNotify('You do not have permission to delete assignment rules.', 'warning')
      return
    }

    if (!window.confirm(`Delete assignment rule "${rule.name}"?`)) {
      return
    }

    try {
      await apiRequest(`/api/admin/assignment-rules/${rule.id}`, {
        method: 'DELETE',
        token,
        tenantId,
      })
      onNotify('Assignment rule deleted.', 'success')
      loadRules()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between">
        <Typography variant="h5">Assignment Rules</Typography>
        {canCreate && (
          <Button variant="contained" startIcon={<AddIcon />} onClick={openNew}>
            New Rule
          </Button>
        )}
      </Stack>

      <Card>
        <CardContent sx={{ p: 0 }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Name</TableCell>
                <TableCell>Strategy</TableCell>
                <TableCell>Priority</TableCell>
                <TableCell>Team</TableCell>
                <TableCell>Fallback Owner</TableCell>
                <TableCell>Active</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {rules.map((rule) => (
                <TableRow key={rule.id}>
                  <TableCell>{rule.name}</TableCell>
                  <TableCell>{rule.strategy}</TableCell>
                  <TableCell>{rule.priority}</TableCell>
                  <TableCell>{rule.team?.name ?? (rule.team_id ? `#${rule.team_id}` : '-')}</TableCell>
                  <TableCell>{rule.fallback_owner?.name ?? (rule.fallback_owner_id ? `#${rule.fallback_owner_id}` : '-')}</TableCell>
                  <TableCell>{rule.is_active ? 'yes' : 'no'}</TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={1} justifyContent="flex-end">
                      {canUpdate && (
                        <Button size="small" onClick={() => openEdit(rule)}>
                          Edit
                        </Button>
                      )}
                      {canDelete && (
                        <Button size="small" color="error" onClick={() => removeRule(rule)}>
                          Delete
                        </Button>
                      )}
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
              {rules.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7}>
                    <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                      No assignment rules found.
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="md" fullWidth>
        <DialogTitle>{editing ? `Edit Rule: ${editing.name}` : 'Create Assignment Rule'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              label="Rule Name"
              value={form.name}
              onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
            />
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2}>
              <FormControl size="small" sx={{ minWidth: 220 }}>
                <InputLabel>Strategy</InputLabel>
                <Select
                  label="Strategy"
                  value={form.strategy}
                  onChange={(event) => setForm((current) => ({ ...current, strategy: event.target.value }))}
                >
                  {STRATEGIES.map((strategy) => (
                    <MenuItem key={strategy.value} value={strategy.value}>
                      {strategy.label}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <TextField
                size="small"
                label="Priority"
                type="number"
                value={form.priority}
                onChange={(event) => setForm((current) => ({ ...current, priority: event.target.value }))}
              />
              <TextField
                size="small"
                label="Team ID (optional)"
                value={form.team_id}
                onChange={(event) => setForm((current) => ({ ...current, team_id: event.target.value }))}
              />
              <TextField
                size="small"
                label="Fallback Owner ID (optional)"
                value={form.fallback_owner_id}
                onChange={(event) => setForm((current) => ({ ...current, fallback_owner_id: event.target.value }))}
              />
            </Stack>

            <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2}>
              <FormControlLabel
                control={(
                  <Checkbox
                    checked={form.is_active}
                    onChange={(event) => setForm((current) => ({ ...current, is_active: event.target.checked }))}
                  />
                )}
                label="Active"
              />
              <FormControlLabel
                control={(
                  <Checkbox
                    checked={form.auto_assign_on_intake}
                    onChange={(event) =>
                      setForm((current) => ({ ...current, auto_assign_on_intake: event.target.checked }))
                    }
                  />
                )}
                label="Auto Assign On Intake"
              />
              <FormControlLabel
                control={(
                  <Checkbox
                    checked={form.auto_assign_on_import}
                    onChange={(event) =>
                      setForm((current) => ({ ...current, auto_assign_on_import: event.target.checked }))
                    }
                  />
                )}
                label="Auto Assign On Import"
              />
            </Stack>

            {form.strategy === 'city' && (
              <TextField
                label="Cities (comma separated)"
                value={form.citiesText}
                onChange={(event) => setForm((current) => ({ ...current, citiesText: event.target.value }))}
                helperText="Example: riyadh, jeddah"
              />
            )}

            {form.strategy === 'interest_service' && (
              <>
                <TextField
                  label="Interests (comma separated)"
                  value={form.interestsText}
                  onChange={(event) => setForm((current) => ({ ...current, interestsText: event.target.value }))}
                  helperText="Optional. Example: solar, crm"
                />
                <TextField
                  label="Services (comma separated)"
                  value={form.servicesText}
                  onChange={(event) => setForm((current) => ({ ...current, servicesText: event.target.value }))}
                  helperText="Optional. Example: implementation, consulting"
                />
              </>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialogOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={saveRule} disabled={saving || (editing ? !canUpdate : !canCreate)}>
            {saving ? 'Saving...' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default AssignmentRulesPanel
