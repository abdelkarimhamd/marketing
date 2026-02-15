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
import { apiRequest, formatDate } from '../lib/api'

function TrackingPanel({ token, tenantId, refreshKey, onNotify }) {
  const [analytics, setAnalytics] = useState(null)
  const [loading, setLoading] = useState(false)
  const [leadId, setLeadId] = useState('')
  const [leadEvents, setLeadEvents] = useState([])

  const loadAnalytics = useCallback(async () => {
    if (!tenantId) return

    setLoading(true)
    try {
      const response = await apiRequest('/api/admin/tracking/analytics', { token, tenantId })
      setAnalytics(response)
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }, [onNotify, tenantId, token])

  const loadLeadEvents = async () => {
    if (!tenantId || !leadId.trim()) {
      setLeadEvents([])
      return
    }

    try {
      const response = await apiRequest(`/api/admin/leads/${leadId.trim()}/web-activity?per_page=50`, { token, tenantId })
      setLeadEvents(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
      setLeadEvents([])
    }
  }

  useEffect(() => {
    loadAnalytics()
  }, [loadAnalytics, refreshKey])

  if (!tenantId) {
    return <Alert severity="info">Select a tenant to view tracking analytics.</Alert>
  }

  const summary = analytics?.summary ?? {}

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1}>
        <Button variant="outlined" onClick={loadAnalytics} disabled={loading}>Refresh</Button>
      </Stack>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, sm: 4 }}>
          <Card><CardContent><Typography variant="body2">Events</Typography><Typography variant="h4">{summary.events ?? 0}</Typography></CardContent></Card>
        </Grid>
        <Grid size={{ xs: 12, sm: 4 }}>
          <Card><CardContent><Typography variant="body2">Unique Visitors</Typography><Typography variant="h4">{summary.unique_visitors ?? 0}</Typography></CardContent></Card>
        </Grid>
        <Grid size={{ xs: 12, sm: 4 }}>
          <Card><CardContent><Typography variant="body2">Conversions</Typography><Typography variant="h4">{summary.conversions ?? 0}</Typography></CardContent></Card>
        </Grid>
      </Grid>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 6 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Table size="small">
                <TableHead><TableRow><TableCell>Top Page</TableCell><TableCell>Hits</TableCell></TableRow></TableHead>
                <TableBody>
                  {(analytics?.top_pages ?? []).map((row) => (
                    <TableRow key={row.path}><TableCell>{row.path}</TableCell><TableCell>{row.hits}</TableCell></TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 6 }}>
          <Card>
            <CardContent>
              <Typography variant="subtitle1">UTM Sources</Typography>
              <Divider sx={{ my: 1 }} />
              <Stack spacing={0.8}>
                {(analytics?.utm?.utm_source ?? []).map((row) => (
                  <Paper key={row.value} variant="outlined" sx={{ p: 1 }}>
                    <Typography variant="body2">{row.value}</Typography>
                    <Typography variant="caption" color="text.secondary">{row.count} events</Typography>
                  </Paper>
                ))}
                {(analytics?.utm?.utm_source ?? []).length === 0 && (
                  <Typography color="text.secondary">No UTM source data yet.</Typography>
                )}
              </Stack>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card>
        <CardContent>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
            <TextField label="Lead ID" value={leadId} onChange={(event) => setLeadId(event.target.value)} sx={{ maxWidth: 180 }} />
            <Button variant="outlined" onClick={loadLeadEvents}>Load Web Activity</Button>
          </Stack>

          <Stack spacing={1} sx={{ mt: 1.5, maxHeight: 280, overflow: 'auto' }}>
            {leadEvents.map((eventRow) => (
              <Paper key={eventRow.id} variant="outlined" sx={{ p: 1 }}>
                <Typography variant="body2">{eventRow.event_type} • {eventRow.path || eventRow.url || '-'}</Typography>
                <Typography variant="caption" color="text.secondary">
                  Visitor: {eventRow.visitor_id} • {formatDate(eventRow.occurred_at || eventRow.created_at)}
                </Typography>
              </Paper>
            ))}
            {leadEvents.length === 0 && (
              <Typography color="text.secondary">No lead web activity loaded.</Typography>
            )}
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  )
}

export default TrackingPanel
