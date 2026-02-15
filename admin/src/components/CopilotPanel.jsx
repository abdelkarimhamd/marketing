import { useState } from 'react'
import {
  Alert,
  Button,
  Card,
  CardContent,
  Divider,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { apiRequest, formatDate } from '../lib/api'

function CopilotPanel({ token, tenantId, onNotify, can = () => true }) {
  const [leadId, setLeadId] = useState('')
  const [panel, setPanel] = useState(null)
  const [loading, setLoading] = useState(false)
  const canView = can('copilot.view')
  const canGenerate = can('copilot.create')

  const load = async () => {
    if (!canView) {
      onNotify('You do not have permission to view copilot.', 'warning')
      return
    }

    if (!leadId.trim()) {
      onNotify('Lead ID is required.', 'warning')
      return
    }

    setLoading(true)
    try {
      const response = await apiRequest(`/api/admin/copilot/leads/${leadId.trim()}`, { token, tenantId })
      setPanel(response)
    } catch (error) {
      onNotify(error.message, 'error')
      setPanel(null)
    } finally {
      setLoading(false)
    }
  }

  const generate = async (sync = false) => {
    if (!canGenerate) {
      onNotify('You do not have permission to generate copilot output.', 'warning')
      return
    }

    if (!leadId.trim()) {
      onNotify('Lead ID is required.', 'warning')
      return
    }

    setLoading(true)
    try {
      await apiRequest(`/api/admin/copilot/leads/${leadId.trim()}/generate`, {
        method: 'POST',
        token,
        tenantId,
        body: { sync },
      })
      onNotify(sync ? 'Copilot generated.' : 'Copilot generation queued.', 'success')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }

  if (!tenantId) {
    return <Alert severity="info">Select a tenant to use AI copilot.</Alert>
  }

  return (
    <Stack spacing={2}>
      <Card>
        <CardContent>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1}>
            <TextField label="Lead ID" value={leadId} onChange={(event) => setLeadId(event.target.value)} sx={{ maxWidth: 180 }} />
            <Button variant="outlined" onClick={load} disabled={loading}>Load</Button>
            <Button variant="contained" onClick={() => generate(false)} disabled={loading || !canGenerate}>Generate Async</Button>
            <Button variant="contained" color="secondary" onClick={() => generate(true)} disabled={loading || !canGenerate}>Generate Sync</Button>
          </Stack>
        </CardContent>
      </Card>

      {panel && (
        <Card>
          <CardContent>
            <Typography variant="h6">Lead Copilot</Typography>
            <Typography variant="caption" color="text.secondary">
              Last generated: {formatDate(panel.last_generated_at)} • Provider: {panel.provider || '-'}
            </Typography>
            <Divider sx={{ my: 1 }} />
            <Typography variant="body2">{panel.summary?.summary || 'No summary yet.'}</Typography>

            <Stack spacing={1} sx={{ mt: 1.5 }}>
              {(panel.recommendations ?? []).map((item) => (
                <Paper key={item.id} variant="outlined" sx={{ p: 1 }}>
                  <Typography variant="body2">{item.type} • score {Number(item.score || 0).toFixed(2)}</Typography>
                  <Typography variant="caption" color="text.secondary">{item.payload_json?.reason || '-'}</Typography>
                </Paper>
              ))}
            </Stack>
          </CardContent>
        </Card>
      )}
    </Stack>
  )
}

export default CopilotPanel
