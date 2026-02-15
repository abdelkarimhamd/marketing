import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Alert,
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
  TextField,
  Typography,
} from '@mui/material'
import { Download as DownloadIcon, Refresh as RefreshIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

function formatCurrency(value) {
  const amount = Number(value ?? 0)

  if (!Number.isFinite(amount)) {
    return '0.00'
  }

  return amount.toFixed(2)
}

function formatPercent(value) {
  const amount = Number(value ?? 0)

  if (!Number.isFinite(amount)) {
    return '0%'
  }

  return `${amount.toFixed(2)}%`
}

function statCard(label, value, caption) {
  return (
    <Card>
      <CardContent>
        <Typography variant="body2" color="text.secondary" gutterBottom>
          {label}
        </Typography>
        <Typography variant="h4">{value}</Typography>
        <Typography variant="caption" color="text.secondary">
          {caption}
        </Typography>
      </CardContent>
    </Card>
  )
}

function toDateInputValue(value) {
  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return ''
  }

  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

function buildQueryString(filters) {
  const params = new URLSearchParams()

  if (filters.dateFrom) {
    params.set('date_from', filters.dateFrom)
  }

  if (filters.dateTo) {
    params.set('date_to', filters.dateTo)
  }

  if (filters.channel) {
    params.set('channel', filters.channel)
  }

  return params.toString()
}

function WorkspaceAnalyticsPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const today = useMemo(() => new Date(), [])
  const defaultDateTo = useMemo(() => toDateInputValue(today), [today])
  const defaultDateFrom = useMemo(() => {
    const from = new Date(today)
    from.setDate(from.getDate() - 29)

    return toDateInputValue(from)
  }, [today])

  const [loading, setLoading] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [filters, setFilters] = useState({
    dateFrom: defaultDateFrom,
    dateTo: defaultDateTo,
    channel: '',
  })
  const [data, setData] = useState({
    period: null,
    summary: {},
    by_tenant: [],
    by_channel: [],
    trend: [],
  })
  const canExport = can('billing.export') || can('settings.view')

  const loadAnalytics = useCallback(async () => {
    setLoading(true)
    try {
      const query = buildQueryString(filters)
      const response = await apiRequest(`/api/admin/billing/workspace-analytics${query ? `?${query}` : ''}`, {
        token,
        tenantId: tenantId || undefined,
      })

      setData({
        period: response.period ?? null,
        summary: response.summary ?? {},
        by_tenant: response.by_tenant ?? [],
        by_channel: response.by_channel ?? [],
        trend: response.trend ?? [],
      })
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }, [filters, onNotify, tenantId, token])

  useEffect(() => {
    loadAnalytics()
  }, [loadAnalytics, refreshKey])

  const exportCsv = async () => {
    if (!canExport) {
      onNotify('You do not have permission to export workspace analytics.', 'warning')
      return
    }

    setExporting(true)
    try {
      const query = buildQueryString(filters)
      const csv = await apiRequest(`/api/admin/billing/workspace-analytics/export${query ? `?${query}` : ''}`, {
        token,
        tenantId: tenantId || undefined,
      })

      const content = typeof csv === 'string' ? csv : String(csv ?? '')
      const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = `workspace-analytics-${filters.dateFrom || 'from'}-${filters.dateTo || 'to'}.csv`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
      onNotify('Workspace analytics CSV exported.', 'success')
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setExporting(false)
    }
  }

  const summary = data.summary ?? {}
  const period = data.period ?? {}
  const trendRows = (data.trend ?? []).slice(-10)

  return (
    <Stack spacing={2.2}>
      <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" spacing={1}>
        <Stack>
          <Typography variant="h5">Workspace Analytics</Typography>
          <Typography variant="caption" color="text.secondary">
            Agency-wide view across all tenants, channels, ROI, and growth.
          </Typography>
        </Stack>
        <Stack direction="row" spacing={1}>
          <Button startIcon={<RefreshIcon />} onClick={loadAnalytics} disabled={loading}>
            Refresh
          </Button>
          <Button
            variant="contained"
            startIcon={<DownloadIcon />}
            onClick={exportCsv}
            disabled={exporting || !canExport}
          >
            {exporting ? 'Exporting...' : 'Export CSV'}
          </Button>
        </Stack>
      </Stack>

      {tenantId && (
        <Alert severity="info">
          Analytics is global (all tenants). Current tenant selection is ignored for this panel.
        </Alert>
      )}

      <Card>
        <CardContent>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={1}>
            <TextField
              size="small"
              label="Date From"
              type="date"
              value={filters.dateFrom}
              onChange={(event) => setFilters((prev) => ({ ...prev, dateFrom: event.target.value }))}
              InputLabelProps={{ shrink: true }}
            />
            <TextField
              size="small"
              label="Date To"
              type="date"
              value={filters.dateTo}
              onChange={(event) => setFilters((prev) => ({ ...prev, dateTo: event.target.value }))}
              InputLabelProps={{ shrink: true }}
            />
            <FormControl size="small" sx={{ minWidth: 180 }}>
              <InputLabel>Channel</InputLabel>
              <Select
                label="Channel"
                value={filters.channel}
                onChange={(event) => setFilters((prev) => ({ ...prev, channel: event.target.value }))}
              >
                <MenuItem value="">All Channels</MenuItem>
                <MenuItem value="email">email</MenuItem>
                <MenuItem value="sms">sms</MenuItem>
                <MenuItem value="whatsapp">whatsapp</MenuItem>
              </Select>
            </FormControl>
            <Button variant="outlined" onClick={loadAnalytics} disabled={loading}>
              Apply
            </Button>
          </Stack>
          <Typography variant="caption" color="text.secondary" sx={{ mt: 1, display: 'block' }}>
            Current period: {period.date_from ?? '-'} to {period.date_to ?? '-'} | Previous: {period.previous_date_from ?? '-'} to {period.previous_date_to ?? '-'}
          </Typography>
        </CardContent>
      </Card>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
          {statCard('Active Tenants', summary.active_tenants ?? 0, `Growth ${formatPercent(summary.active_tenants_growth_percent)}`)}
        </Grid>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
          {statCard('Revenue', formatCurrency(summary.revenue_total), `Growth ${formatPercent(summary.revenue_growth_percent)}`)}
        </Grid>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
          {statCard('Profit', formatCurrency(summary.profit_total), `Growth ${formatPercent(summary.profit_growth_percent)}`)}
        </Grid>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
          {statCard('ROI', formatPercent(summary.roi_percent), `Growth ${formatPercent(summary.roi_growth_percent)}`)}
        </Grid>
      </Grid>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 8 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Stack sx={{ p: 1.2 }}>
                <Typography variant="h6">Tenant Comparison</Typography>
              </Stack>
              <Divider />
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Rank</TableCell>
                    <TableCell>Tenant</TableCell>
                    <TableCell align="right">Messages</TableCell>
                    <TableCell align="right">Revenue</TableCell>
                    <TableCell align="right">Profit</TableCell>
                    <TableCell align="right">Margin</TableCell>
                    <TableCell align="right">ROI</TableCell>
                    <TableCell align="right">Revenue Growth</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {(data.by_tenant ?? []).map((row) => (
                    <TableRow key={row.tenant_id}>
                      <TableCell>{row.rank ?? '-'}</TableCell>
                      <TableCell>{row.tenant_name ?? '-'}</TableCell>
                      <TableCell align="right">{row.messages_count ?? 0}</TableCell>
                      <TableCell align="right">{formatCurrency(row.revenue_total)}</TableCell>
                      <TableCell align="right">{formatCurrency(row.profit_total)}</TableCell>
                      <TableCell align="right">{formatPercent(row.margin_percent)}</TableCell>
                      <TableCell align="right">{formatPercent(row.roi_percent)}</TableCell>
                      <TableCell align="right">{formatPercent(row.revenue_growth_percent)}</TableCell>
                    </TableRow>
                  ))}
                  {(data.by_tenant ?? []).length === 0 && (
                    <TableRow>
                      <TableCell colSpan={8}>
                        <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                          No tenant activity for this period.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 4 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Stack sx={{ p: 1.2 }}>
                <Typography variant="h6">Channel Comparison</Typography>
              </Stack>
              <Divider />
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Channel</TableCell>
                    <TableCell align="right">Revenue</TableCell>
                    <TableCell align="right">ROI</TableCell>
                    <TableCell align="right">Growth</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {(data.by_channel ?? []).map((row) => (
                    <TableRow key={row.channel}>
                      <TableCell>{row.channel ?? '-'}</TableCell>
                      <TableCell align="right">{formatCurrency(row.revenue_total)}</TableCell>
                      <TableCell align="right">{formatPercent(row.roi_percent)}</TableCell>
                      <TableCell align="right">{formatPercent(row.revenue_growth_percent)}</TableCell>
                    </TableRow>
                  ))}
                  {(data.by_channel ?? []).length === 0 && (
                    <TableRow>
                      <TableCell colSpan={4}>
                        <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                          No channel data.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card>
        <CardContent sx={{ p: 0 }}>
          <Stack sx={{ p: 1.2 }}>
            <Typography variant="h6">Recent Trend (Last 10 Days In Range)</Typography>
          </Stack>
          <Divider />
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Date</TableCell>
                <TableCell align="right">Messages</TableCell>
                <TableCell align="right">Cost</TableCell>
                <TableCell align="right">Revenue</TableCell>
                <TableCell align="right">Profit</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {trendRows.map((row) => (
                <TableRow key={row.date}>
                  <TableCell>{formatDate(row.date)}</TableCell>
                  <TableCell align="right">{row.messages_count ?? 0}</TableCell>
                  <TableCell align="right">{formatCurrency(row.total_cost)}</TableCell>
                  <TableCell align="right">{formatCurrency(row.revenue_total)}</TableCell>
                  <TableCell align="right">{formatCurrency(row.profit_total)}</TableCell>
                </TableRow>
              ))}
              {trendRows.length === 0 && (
                <TableRow>
                  <TableCell colSpan={5}>
                    <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                      No trend data for this period.
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </Stack>
  )
}

export default WorkspaceAnalyticsPanel
