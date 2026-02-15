import { useCallback, useEffect, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  Divider,
  Grid,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material'
import { apiRequest, formatDate } from '../lib/api'

function statusColor(status) {
  const normalized = String(status || '').toLowerCase()
  if (normalized === 'completed') return 'success'
  if (normalized === 'failed' || normalized === 'rejected') return 'error'
  if (normalized === 'running' || normalized === 'in_progress') return 'warning'
  return 'default'
}

function DataQualityPanel({ token, tenantId, refreshKey, onNotify, can = () => true }) {
  const [runs, setRuns] = useState([])
  const [suggestions, setSuggestions] = useState([])
  const [starting, setStarting] = useState(false)
  const canStart = can('data_quality.create')
  const canReview = can('data_quality.review')

  const load = useCallback(async () => {
    if (!tenantId) return

    try {
      const [runsResponse, suggestionsResponse] = await Promise.all([
        apiRequest('/api/admin/data-quality/runs?per_page=20', { token, tenantId }),
        apiRequest('/api/admin/data-quality/merge-suggestions?per_page=30', { token, tenantId }),
      ])
      setRuns(runsResponse.data ?? [])
      setSuggestions(suggestionsResponse.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    load()
  }, [load, refreshKey])

  const startRun = async () => {
    if (!canStart) {
      onNotify('You do not have permission to start data quality runs.', 'warning')
      return
    }

    setStarting(true)
    try {
      await apiRequest('/api/admin/data-quality/runs', {
        method: 'POST',
        token,
        tenantId,
        body: { run_type: 'full' },
      })
      onNotify('Data quality run queued.', 'success')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setStarting(false)
    }
  }

  const review = async (id, status) => {
    if (!canReview) {
      onNotify('You do not have permission to review merge suggestions.', 'warning')
      return
    }

    try {
      await apiRequest(`/api/admin/data-quality/merge-suggestions/${id}/review`, {
        method: 'POST',
        token,
        tenantId,
        body: { status },
      })
      onNotify(`Suggestion marked as ${status}.`, 'success')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  if (!tenantId) {
    return <Alert severity="info">Select a tenant to manage data quality.</Alert>
  }

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1}>
        <Button variant="contained" onClick={startRun} disabled={starting || !canStart}>
          {starting ? 'Queuing...' : 'Run Data Quality'}
        </Button>
      </Stack>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 5 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>ID</TableCell>
                    <TableCell>Type</TableCell>
                    <TableCell>Status</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {runs.map((run) => (
                    <TableRow key={run.id}>
                      <TableCell>{run.id}</TableCell>
                      <TableCell>{run.run_type}</TableCell>
                      <TableCell><Chip size="small" label={run.status} color={statusColor(run.status)} /></TableCell>
                    </TableRow>
                  ))}
                  {runs.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={3}><Typography align="center" color="text.secondary" sx={{ py: 2 }}>No runs yet.</Typography></TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 7 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Merge Suggestions</Typography>
              <Divider sx={{ my: 1 }} />
              <Stack spacing={1}>
                {suggestions.map((suggestion) => (
                  <Paper key={suggestion.id} variant="outlined" sx={{ p: 1 }}>
                    <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" spacing={1}>
                      <Stack>
                        <Typography variant="body2">
                          #{suggestion.id} • {suggestion.reason} • confidence {Number(suggestion.confidence || 0).toFixed(2)}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          A: {suggestion.candidate_a?.email || suggestion.candidate_a?.phone || `lead#${suggestion.candidate_a_id}`}
                          {' '}| B: {suggestion.candidate_b?.email || suggestion.candidate_b?.phone || `lead#${suggestion.candidate_b_id}`}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          Status: {suggestion.status} • Updated {formatDate(suggestion.updated_at)}
                        </Typography>
                      </Stack>
                      {canReview && (
                        <Stack direction="row" spacing={0.6}>
                          <Button size="small" onClick={() => review(suggestion.id, 'approved')}>Approve</Button>
                          <Button size="small" onClick={() => review(suggestion.id, 'merged')}>Merge</Button>
                          <Button size="small" color="inherit" onClick={() => review(suggestion.id, 'skipped')}>Skip</Button>
                          <Button size="small" color="error" onClick={() => review(suggestion.id, 'rejected')}>Reject</Button>
                        </Stack>
                      )}
                    </Stack>
                  </Paper>
                ))}
                {suggestions.length === 0 && (
                  <Typography color="text.secondary">No merge suggestions.</Typography>
                )}
              </Stack>
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </Stack>
  )
}

export default DataQualityPanel
