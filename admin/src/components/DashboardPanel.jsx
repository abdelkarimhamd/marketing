import { useCallback, useEffect, useState } from 'react'
import { Box, Button, Card, CardContent, Divider, Grid, Paper, Stack, Typography } from '@mui/material'
import { Refresh as RefreshIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

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

function DashboardPanel({ token, tenantId, refreshKey, onNotify }) {
  const [loading, setLoading] = useState(false)
  const [data, setData] = useState({
    metrics: {},
    recent_activities: [],
  })

  const loadDashboard = useCallback(async () => {
    setLoading(true)
    try {
      const response = await apiRequest('/api/admin/dashboard', { token, tenantId })
      setData(response)
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadDashboard()
  }, [loadDashboard, refreshKey])

  const metrics = data.metrics ?? {}

  return (
    <Stack spacing={2.4}>
      <Stack direction="row" alignItems="center" justifyContent="space-between">
        <Typography variant="h5">Dashboard</Typography>
        <Button startIcon={<RefreshIcon />} onClick={loadDashboard} disabled={loading}>
          Refresh
        </Button>
      </Stack>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>{statCard('Leads', metrics.leads_total ?? 0, 'Tenant-visible total')}</Grid>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>{statCard('Campaigns', metrics.campaigns_total ?? 0, 'Broadcast + drip')}</Grid>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>{statCard('Messages Sent', metrics.messages_sent ?? 0, 'Sent/delivered/open/read')}</Grid>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>{statCard('Pending Webhooks', metrics.webhooks_pending ?? 0, 'Inbox pending')}</Grid>
      </Grid>

      <Card>
        <CardContent>
          <Typography variant="h6" gutterBottom>
            Recent Activity
          </Typography>
          <Divider sx={{ mb: 1.5 }} />
          <Stack spacing={1.2}>
            {(data.recent_activities ?? []).length === 0 && (
              <Typography color="text.secondary">No activity yet.</Typography>
            )}
            {(data.recent_activities ?? []).map((activity) => (
              <Paper key={activity.id} variant="outlined" sx={{ p: 1.2 }}>
                <Typography variant="body2">
                  <strong>{activity.type}</strong> · {activity.description ?? 'No description'}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  #{activity.id} · {formatDate(activity.created_at)}
                </Typography>
              </Paper>
            ))}
          </Stack>
        </CardContent>
      </Card>
      <Box />
    </Stack>
  )
}

export default DashboardPanel
