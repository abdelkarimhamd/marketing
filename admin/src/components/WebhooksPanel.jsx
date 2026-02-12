import { useCallback, useEffect, useState } from 'react'
import {
  Button,
  Card,
  CardContent,
  Divider,
  FormControl,
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
  Typography,
  Paper,
} from '@mui/material'
import { apiRequest, formatDate } from '../lib/api'

function WebhooksPanel({ token, tenantId, refreshKey, onNotify }) {
  const [provider, setProvider] = useState('')
  const [status, setStatus] = useState('')
  const [rows, setRows] = useState([])
  const [selected, setSelected] = useState(null)

  const load = useCallback(async () => {
    try {
      const params = new URLSearchParams({ per_page: '100' })
      if (provider) params.set('provider', provider)
      if (status) params.set('status', status)

      const response = await apiRequest(`/api/admin/webhooks-inbox?${params.toString()}`, { token, tenantId })
      setRows(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, provider, status, tenantId, token])

  useEffect(() => {
    load()
  }, [load, refreshKey])

  const openDetails = async (rowId) => {
    try {
      const response = await apiRequest(`/api/admin/webhooks-inbox/${rowId}`, { token, tenantId })
      setSelected(response.webhook ?? null)
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2} alignItems={{ xs: 'stretch', md: 'center' }}>
        <Typography variant="h5" sx={{ flex: 1 }}>Webhooks Inbox</Typography>
        <FormControl size="small" sx={{ minWidth: 140 }}>
          <InputLabel>Provider</InputLabel>
          <Select label="Provider" value={provider} onChange={(event) => setProvider(event.target.value)}>
            <MenuItem value="">all</MenuItem>
            <MenuItem value="mock">mock</MenuItem>
            <MenuItem value="twilio">twilio</MenuItem>
            <MenuItem value="meta">meta</MenuItem>
            <MenuItem value="smtp">smtp</MenuItem>
          </Select>
        </FormControl>
        <FormControl size="small" sx={{ minWidth: 140 }}>
          <InputLabel>Status</InputLabel>
          <Select label="Status" value={status} onChange={(event) => setStatus(event.target.value)}>
            <MenuItem value="">all</MenuItem>
            <MenuItem value="pending">pending</MenuItem>
            <MenuItem value="processed">processed</MenuItem>
            <MenuItem value="ignored">ignored</MenuItem>
            <MenuItem value="failed">failed</MenuItem>
          </Select>
        </FormControl>
        <Button variant="outlined" onClick={load}>Apply</Button>
      </Stack>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 7 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>ID</TableCell>
                    <TableCell>Provider</TableCell>
                    <TableCell>Event</TableCell>
                    <TableCell>Status</TableCell>
                    <TableCell>Received</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {rows.map((row) => (
                    <TableRow
                      key={row.id}
                      hover
                      selected={selected?.id === row.id}
                      onClick={() => openDetails(row.id)}
                      sx={{ cursor: 'pointer' }}
                    >
                      <TableCell>{row.id}</TableCell>
                      <TableCell>{row.provider}</TableCell>
                      <TableCell>{row.event ?? '-'}</TableCell>
                      <TableCell>{row.status}</TableCell>
                      <TableCell>{formatDate(row.received_at)}</TableCell>
                    </TableRow>
                  ))}
                  {rows.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={5}>
                        <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                          No webhook rows.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>
        <Grid size={{ xs: 12, lg: 5 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Webhook Detail</Typography>
              <Divider sx={{ my: 1 }} />
              {!selected && <Typography color="text.secondary">Pick a row to inspect payload.</Typography>}
              {selected && (
                <Stack spacing={1}>
                  <Typography variant="body2"><strong>Provider:</strong> {selected.provider}</Typography>
                  <Typography variant="body2"><strong>Status:</strong> {selected.status}</Typography>
                  <Typography variant="body2"><strong>External ID:</strong> {selected.external_id || '-'}</Typography>
                  <Typography variant="body2"><strong>Error:</strong> {selected.error_message || '-'}</Typography>
                  <Paper variant="outlined" sx={{ p: 1, maxHeight: 380, overflow: 'auto' }}>
                    <Typography component="pre" sx={{ m: 0, whiteSpace: 'pre-wrap', fontFamily: '"IBM Plex Mono", monospace', fontSize: 12 }}>
                      {String(selected.payload ?? '').trim() || '{}'}
                    </Typography>
                  </Paper>
                </Stack>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </Stack>
  )
}

export default WebhooksPanel
