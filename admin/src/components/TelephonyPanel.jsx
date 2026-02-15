import { useCallback, useEffect, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  CardContent,
  Divider,
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

function TelephonyPanel({ token, tenantId, refreshKey, onNotify, can = () => true }) {
  const [calls, setCalls] = useState([])
  const [leadId, setLeadId] = useState('')
  const [toNumber, setToNumber] = useState('')
  const [accessToken, setAccessToken] = useState(null)
  const [dispositionByCall, setDispositionByCall] = useState({})
  const canCreate = can('telephony.create')
  const canUpdate = can('telephony.update')

  const load = useCallback(async () => {
    if (!tenantId) return
    try {
      const response = await apiRequest('/api/admin/telephony/calls?per_page=50', { token, tenantId })
      setCalls(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    load()
  }, [load, refreshKey])

  const startCall = async () => {
    if (!canCreate) {
      onNotify('You do not have permission to start calls.', 'warning')
      return
    }

    if (!leadId.trim()) {
      onNotify('Lead ID is required.', 'warning')
      return
    }

    try {
      await apiRequest('/api/admin/telephony/calls/start', {
        method: 'POST',
        token,
        tenantId,
        body: {
          lead_id: Number(leadId),
          to: toNumber.trim() || undefined,
        },
      })
      onNotify('Call started/queued.', 'success')
      setLeadId('')
      setToNumber('')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const updateDisposition = async (call) => {
    if (!canUpdate) {
      onNotify('You do not have permission to update calls.', 'warning')
      return
    }

    const disposition = (dispositionByCall[call.id] || '').trim()

    try {
      await apiRequest(`/api/admin/telephony/calls/${call.id}/disposition`, {
        method: 'POST',
        token,
        tenantId,
        body: {
          status: disposition ? 'completed' : call.status,
          disposition: disposition || null,
        },
      })
      onNotify('Call updated.', 'success')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const fetchToken = async () => {
    try {
      const response = await apiRequest('/api/admin/telephony/access-token', { token, tenantId })
      setAccessToken(response)
      onNotify('Access token fetched.', 'success')
    } catch (error) {
      onNotify(error.message, 'error')
      setAccessToken(null)
    }
  }

  if (!tenantId) {
    return <Alert severity="info">Select a tenant to use telephony.</Alert>
  }

  return (
    <Stack spacing={2}>
      <Card>
        <CardContent>
          <Typography variant="h6">Start Outbound Call</Typography>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} sx={{ mt: 1 }}>
            <TextField label="Lead ID" value={leadId} onChange={(event) => setLeadId(event.target.value)} sx={{ maxWidth: 150 }} />
            <TextField label="Override To Number (optional)" value={toNumber} onChange={(event) => setToNumber(event.target.value)} sx={{ minWidth: 280 }} />
            <Button variant="contained" onClick={startCall} disabled={!canCreate}>Start</Button>
            <Button variant="outlined" onClick={fetchToken}>Get Access Token</Button>
          </Stack>
          {accessToken && (
            <Paper variant="outlined" sx={{ p: 1, mt: 1 }}>
              <Typography variant="caption" color="text.secondary">Provider: {accessToken.provider || '-'} • Expires: {accessToken.expires_at || '-'}</Typography>
            </Paper>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent sx={{ p: 0 }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>ID</TableCell>
                <TableCell>Lead</TableCell>
                <TableCell>Status</TableCell>
                <TableCell>Provider</TableCell>
                <TableCell>Started</TableCell>
                <TableCell>Disposition</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {calls.map((call) => (
                <TableRow key={call.id}>
                  <TableCell>{call.id}</TableCell>
                  <TableCell>{call.lead_id || '-'}</TableCell>
                  <TableCell>{call.status}</TableCell>
                  <TableCell>{call.provider || '-'}</TableCell>
                  <TableCell>{formatDate(call.started_at || call.created_at)}</TableCell>
                  <TableCell>
                    <Stack direction="row" spacing={0.6}>
                      <TextField
                        size="small"
                        placeholder="Disposition"
                        value={dispositionByCall[call.id] || ''}
                        onChange={(event) => setDispositionByCall((prev) => ({ ...prev, [call.id]: event.target.value }))}
                        sx={{ minWidth: 150 }}
                      />
                      <Button size="small" onClick={() => updateDisposition(call)} disabled={!canUpdate}>Save</Button>
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
              {calls.length === 0 && (
                <TableRow>
                  <TableCell colSpan={6}>
                    <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                      No calls yet.
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
          <Divider />
        </CardContent>
      </Card>
    </Stack>
  )
}

export default TelephonyPanel
