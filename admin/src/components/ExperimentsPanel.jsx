import { useCallback, useEffect, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  CardContent,
  Divider,
  Grid,
  Paper,
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

function emptyForm() {
  return {
    name: '',
    scope: 'landing',
    holdout_pct: 10,
    weightA: 50,
    weightB: 50,
  }
}

function ExperimentsPanel({ token, tenantId, refreshKey, onNotify, can = () => true }) {
  const [rows, setRows] = useState([])
  const [selectedId, setSelectedId] = useState(null)
  const [results, setResults] = useState(null)
  const [form, setForm] = useState(emptyForm())
  const [saving, setSaving] = useState(false)
  const canCreate = can('experiments.create')

  const load = useCallback(async () => {
    if (!tenantId) return

    try {
      const response = await apiRequest('/api/admin/experiments', { token, tenantId })
      const data = response.data ?? []
      setRows(data)
      if (!selectedId && data.length > 0) setSelectedId(data[0].id)
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, selectedId, tenantId, token])

  const loadResults = useCallback(async () => {
    if (!tenantId || !selectedId) {
      setResults(null)
      return
    }

    try {
      const response = await apiRequest(`/api/admin/experiments/${selectedId}/results`, { token, tenantId })
      setResults(response)
    } catch (error) {
      onNotify(error.message, 'error')
      setResults(null)
    }
  }, [onNotify, selectedId, tenantId, token])

  useEffect(() => {
    load()
  }, [load, refreshKey])

  useEffect(() => {
    loadResults()
  }, [loadResults])

  const create = async () => {
    if (!canCreate) {
      onNotify('You do not have permission to create experiments.', 'warning')
      return
    }

    setSaving(true)
    try {
      await apiRequest('/api/admin/experiments', {
        method: 'POST',
        token,
        tenantId,
        body: {
          name: form.name,
          scope: form.scope,
          status: 'running',
          holdout_pct: Number(form.holdout_pct || 0),
          variants: [
            { key: 'A', weight: Number(form.weightA || 50), is_control: true, config_json: {} },
            { key: 'B', weight: Number(form.weightB || 50), is_control: false, config_json: {} },
          ],
        },
      })
      onNotify('Experiment created.', 'success')
      setForm(emptyForm())
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  if (!tenantId) {
    return <Alert severity="info">Select a tenant to manage experiments.</Alert>
  }

  return (
    <Grid container spacing={2}>
      <Grid size={{ xs: 12, lg: 5 }}>
        <Card>
          <CardContent>
            <Typography variant="h6">Create Experiment</Typography>
            <Stack spacing={1.1} sx={{ mt: 1 }}>
              <TextField label="Name" value={form.name} onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))} />
              <TextField label="Scope" value={form.scope} onChange={(event) => setForm((prev) => ({ ...prev, scope: event.target.value }))} />
              <TextField type="number" label="Holdout %" value={form.holdout_pct} onChange={(event) => setForm((prev) => ({ ...prev, holdout_pct: event.target.value }))} />
              <Stack direction="row" spacing={1}>
                <TextField type="number" label="Variant A Weight" value={form.weightA} onChange={(event) => setForm((prev) => ({ ...prev, weightA: event.target.value }))} fullWidth />
                <TextField type="number" label="Variant B Weight" value={form.weightB} onChange={(event) => setForm((prev) => ({ ...prev, weightB: event.target.value }))} fullWidth />
              </Stack>
              <Button variant="contained" onClick={create} disabled={saving || !canCreate}>{saving ? 'Saving...' : 'Create'}</Button>
            </Stack>
          </CardContent>
        </Card>

        <Card sx={{ mt: 2 }}>
          <CardContent sx={{ p: 0 }}>
            <Table size="small">
              <TableHead><TableRow><TableCell>Name</TableCell><TableCell>Scope</TableCell><TableCell>Status</TableCell></TableRow></TableHead>
              <TableBody>
                {rows.map((row) => (
                  <TableRow
                    key={row.id}
                    hover
                    selected={String(row.id) === String(selectedId)}
                    onClick={() => setSelectedId(row.id)}
                    sx={{ cursor: 'pointer' }}
                  >
                    <TableCell>{row.name}</TableCell>
                    <TableCell>{row.scope}</TableCell>
                    <TableCell>{row.status}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </Grid>

      <Grid size={{ xs: 12, lg: 7 }}>
        <Card>
          <CardContent>
            <Typography variant="h6">Results</Typography>
            <Divider sx={{ my: 1 }} />
            {!results && <Typography color="text.secondary">Select an experiment to view results.</Typography>}

            {results && (
              <Stack spacing={1}>
                <Typography variant="body2">
                  Assignments: {results.summary?.assignments ?? 0} • Metrics: {results.summary?.metrics_count ?? 0}
                </Typography>
                {(results.variants ?? []).map((variant) => (
                  <Paper key={variant.variant_key} variant="outlined" sx={{ p: 1 }}>
                    <Typography variant="body2">Variant {variant.variant_key}</Typography>
                    <Typography variant="caption" color="text.secondary">
                      Assignments {variant.assignments} • CR {(Number(variant.conversion_rate || 0) * 100).toFixed(2)}%
                      {variant.lift_vs_holdout === null ? '' : ` • Lift ${Number(variant.lift_vs_holdout).toFixed(2)}%`}
                    </Typography>
                  </Paper>
                ))}
              </Stack>
            )}
          </CardContent>
        </Card>
      </Grid>
    </Grid>
  )
}

export default ExperimentsPanel
