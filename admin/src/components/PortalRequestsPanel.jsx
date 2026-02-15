import { useCallback, useEffect, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  CardContent,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material'
import { apiRequest, formatDate } from '../lib/api'

const STATUSES = ['new', 'in_progress', 'qualified', 'converted', 'closed']

function PortalRequestsPanel({ token, tenantId, refreshKey, onNotify, can = () => true }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(false)
  const canUpdate = can('portal_requests.update')
  const canConvert = can('portal_requests.convert')

  const load = useCallback(async () => {
    if (!tenantId) return

    setLoading(true)
    try {
      const response = await apiRequest('/api/admin/portal/requests?per_page=50', { token, tenantId })
      setRows(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    load()
  }, [load, refreshKey])

  const updateStatus = async (row, status) => {
    if (!canUpdate) {
      onNotify('You do not have permission to update portal requests.', 'warning')
      return
    }

    try {
      await apiRequest(`/api/admin/portal/requests/${row.id}`, {
        method: 'PATCH',
        token,
        tenantId,
        body: { status },
      })
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const convert = async (row) => {
    if (!canConvert) {
      onNotify('You do not have permission to convert portal requests.', 'warning')
      return
    }

    try {
      await apiRequest(`/api/admin/portal/requests/${row.id}/convert`, {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify('Portal request converted.', 'success')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  if (!tenantId) {
    return <Alert severity="info">Select a tenant to manage portal requests.</Alert>
  }

  return (
    <Card>
      <CardContent sx={{ p: 0 }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>ID</TableCell>
              <TableCell>Type</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Lead</TableCell>
              <TableCell>Created</TableCell>
              <TableCell align="right">Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.map((row) => (
              <TableRow key={row.id}>
                <TableCell>{row.id}</TableCell>
                <TableCell>{row.request_type}</TableCell>
                <TableCell>
                  <Select
                    size="small"
                    value={row.status || 'new'}
                    onChange={(event) => updateStatus(row, event.target.value)}
                    disabled={!canUpdate}
                    sx={{ minWidth: 140 }}
                  >
                    {STATUSES.map((status) => (
                      <MenuItem key={status} value={status}>{status}</MenuItem>
                    ))}
                  </Select>
                </TableCell>
                <TableCell>
                  {row.lead ? (
                    <Stack>
                      <Typography variant="body2">#{row.lead.id}</Typography>
                      <Typography variant="caption" color="text.secondary">{row.lead.email || row.lead.phone || '-'}</Typography>
                    </Stack>
                  ) : '-'}
                </TableCell>
                <TableCell>{formatDate(row.created_at)}</TableCell>
                <TableCell align="right">
                  <Button size="small" variant="outlined" onClick={() => convert(row)} disabled={!canConvert || row.status === 'converted'}>
                    Convert
                  </Button>
                </TableCell>
              </TableRow>
            ))}
            {rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={6}>
                  <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                    {loading ? 'Loading...' : 'No portal requests found.'}
                  </Typography>
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}

export default PortalRequestsPanel
