import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Button,
  Card,
  CardContent,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  Step,
  StepLabel,
  Stepper,
  TextField,
  Typography,
  Chip,
} from '@mui/material'
import { Add as AddIcon, PlayArrow as LaunchIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

function CampaignsPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [campaigns, setCampaigns] = useState([])
  const [segments, setSegments] = useState([])
  const [templates, setTemplates] = useState([])
  const [selectedCampaignId, setSelectedCampaignId] = useState('')
  const [logs, setLogs] = useState([])
  const [createDialogOpen, setCreateDialogOpen] = useState(false)
  const [createForm, setCreateForm] = useState({
    name: '',
    segment_id: '',
    template_id: '',
    campaign_type: 'broadcast',
  })
  const [wizard, setWizard] = useState({
    segment_id: '',
    template_id: '',
    channel: 'email',
    journey_type: 'default',
    campaign_type: 'broadcast',
    start_at: '',
    end_at: '',
    stop_opt_out: true,
    stop_won_lost: true,
    stop_replied: true,
    stop_fatigue_enabled: false,
    stop_fatigue_threshold_messages: 6,
    stop_fatigue_reengagement_messages: 1,
    stop_fatigue_sunset: true,
  })
  const canCreate = can('campaigns.create')
  const canUpdate = can('campaigns.update')
  const canSend = can('campaigns.send')

  const selectedCampaign = useMemo(
    () => campaigns.find((campaign) => String(campaign.id) === String(selectedCampaignId)) ?? null,
    [campaigns, selectedCampaignId],
  )

  const loadDependencies = useCallback(async () => {
    try {
      const [campaignResponse, segmentResponse, templateResponse] = await Promise.all([
        apiRequest('/api/admin/campaigns?per_page=100', { token, tenantId }),
        apiRequest('/api/admin/segments?per_page=100', { token, tenantId }),
        apiRequest('/api/admin/templates?per_page=100', { token, tenantId }),
      ])
      setCampaigns(campaignResponse.data ?? [])
      setSegments(segmentResponse.data ?? [])
      setTemplates(templateResponse.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  const loadLogs = useCallback(async () => {
    if (!selectedCampaignId) {
      setLogs([])
      return
    }
    try {
      const response = await apiRequest(`/api/admin/campaigns/${selectedCampaignId}/logs?per_page=50`, { token, tenantId })
      setLogs(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
      setLogs([])
    }
  }, [onNotify, selectedCampaignId, tenantId, token])

  useEffect(() => {
    loadDependencies()
  }, [loadDependencies, refreshKey])

  useEffect(() => {
    if (!selectedCampaign) return
    setWizard({
      segment_id: selectedCampaign.segment_id ? String(selectedCampaign.segment_id) : '',
      template_id: selectedCampaign.template_id ? String(selectedCampaign.template_id) : '',
      channel: selectedCampaign.channel ?? 'email',
      journey_type: selectedCampaign.settings?.journey_type ?? 'default',
      campaign_type: selectedCampaign.campaign_type ?? 'broadcast',
      start_at: selectedCampaign.start_at ? selectedCampaign.start_at.slice(0, 16) : '',
      end_at: selectedCampaign.end_at ? selectedCampaign.end_at.slice(0, 16) : '',
      stop_opt_out: selectedCampaign.settings?.stop_rules?.opt_out ?? true,
      stop_won_lost: selectedCampaign.settings?.stop_rules?.won_lost ?? true,
      stop_replied: selectedCampaign.settings?.stop_rules?.replied ?? true,
      stop_fatigue_enabled: selectedCampaign.settings?.stop_rules?.fatigue_enabled ?? false,
      stop_fatigue_threshold_messages: selectedCampaign.settings?.stop_rules?.fatigue_threshold_messages ?? 6,
      stop_fatigue_reengagement_messages: selectedCampaign.settings?.stop_rules?.fatigue_reengagement_messages ?? 1,
      stop_fatigue_sunset: selectedCampaign.settings?.stop_rules?.fatigue_sunset ?? true,
    })
    loadLogs()
  }, [loadLogs, selectedCampaign])

  const createCampaign = async () => {
    if (!canCreate) {
      onNotify('You do not have permission to create campaigns.', 'warning')
      return
    }

    try {
      const payload = {
        name: createForm.name,
        segment_id: createForm.segment_id ? Number(createForm.segment_id) : null,
        template_id: createForm.template_id ? Number(createForm.template_id) : null,
        campaign_type: createForm.campaign_type,
      }
      const response = await apiRequest('/api/admin/campaigns', {
        method: 'POST',
        token,
        tenantId,
        body: payload,
      })
      onNotify('Campaign created.', 'success')
      setCreateDialogOpen(false)
      setSelectedCampaignId(String(response.campaign.id))
      loadDependencies()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const saveWizardStep = async (step) => {
    if (!canUpdate) {
      onNotify('You do not have permission to update campaigns.', 'warning')
      return
    }

    if (!selectedCampaignId) {
      onNotify('Select campaign first.', 'warning')
      return
    }

    try {
      const payload = { step }

      if (step === 'audience') {
        payload.segment_id = Number(wizard.segment_id)
      } else if (step === 'content') {
        payload.template_id = Number(wizard.template_id)
        payload.channel = wizard.channel
      } else if (step === 'schedule') {
        const fatigueThreshold = Number(wizard.stop_fatigue_threshold_messages)
        const reengagementAttempts = Number(wizard.stop_fatigue_reengagement_messages)

        payload.campaign_type = wizard.campaign_type
        payload.journey_type = wizard.journey_type
        payload.start_at = wizard.start_at ? new Date(wizard.start_at).toISOString() : null
        payload.end_at = wizard.end_at ? new Date(wizard.end_at).toISOString() : null
        payload.stop_rules = {
          opt_out: wizard.stop_opt_out,
          won_lost: wizard.stop_won_lost,
          replied: wizard.stop_replied,
          fatigue_enabled: wizard.stop_fatigue_enabled,
          fatigue_threshold_messages: Number.isFinite(fatigueThreshold) && fatigueThreshold > 0 ? fatigueThreshold : 6,
          fatigue_reengagement_messages: Number.isFinite(reengagementAttempts) && reengagementAttempts >= 0 ? reengagementAttempts : 1,
          fatigue_sunset: wizard.stop_fatigue_sunset,
        }
      }

      await apiRequest(`/api/admin/campaigns/${selectedCampaignId}/wizard`, {
        method: 'POST',
        token,
        tenantId,
        body: payload,
      })
      onNotify(`Saved ${step} step.`, 'success')
      loadDependencies()
      loadLogs()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const launchCampaign = async () => {
    if (!canSend) {
      onNotify('You do not have permission to launch campaigns.', 'warning')
      return
    }

    if (!selectedCampaignId) return
    try {
      await apiRequest(`/api/admin/campaigns/${selectedCampaignId}/launch`, {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify('Campaign launch queued.', 'success')
      loadDependencies()
      loadLogs()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', lg: 'row' }} spacing={2}>
        <Card sx={{ flex: 1 }}>
          <CardContent>
            <Stack direction="row" justifyContent="space-between" alignItems="center">
              <Typography variant="h6">Campaigns</Typography>
              {canCreate && (
                <Button variant="contained" startIcon={<AddIcon />} onClick={() => setCreateDialogOpen(true)}>
                  New Campaign
                </Button>
              )}
            </Stack>
            <Divider sx={{ my: 1.5 }} />
            <FormControl size="small" fullWidth sx={{ mb: 1.2 }}>
              <InputLabel>Select Campaign</InputLabel>
              <Select
                label="Select Campaign"
                value={selectedCampaignId}
                onChange={(event) => setSelectedCampaignId(event.target.value)}
              >
                <MenuItem value="">Choose...</MenuItem>
                {campaigns.map((campaign) => (
                  <MenuItem key={campaign.id} value={String(campaign.id)}>
                    {campaign.name} ({campaign.status})
                  </MenuItem>
                ))}
              </Select>
            </FormControl>

            {!selectedCampaign && <Typography color="text.secondary">Select a campaign to use the wizard.</Typography>}

            {selectedCampaign && (
              <Stack spacing={2}>
                <Stepper alternativeLabel>
                  <Step><StepLabel>Audience</StepLabel></Step>
                  <Step><StepLabel>Content</StepLabel></Step>
                  <Step><StepLabel>Schedule</StepLabel></Step>
                </Stepper>

                <Stack spacing={1.2}>
                  <Typography variant="subtitle2">Audience</Typography>
                  <FormControl size="small" fullWidth>
                    <InputLabel>Segment</InputLabel>
                    <Select
                      label="Segment"
                      value={wizard.segment_id}
                      disabled={!canUpdate}
                      onChange={(event) => setWizard((prev) => ({ ...prev, segment_id: event.target.value }))}
                    >
                      {segments.map((segment) => (
                        <MenuItem key={segment.id} value={String(segment.id)}>
                          {segment.name}
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                  <Button variant="outlined" onClick={() => saveWizardStep('audience')} disabled={!canUpdate}>Save Audience</Button>
                </Stack>

                <Stack spacing={1.2}>
                  <Typography variant="subtitle2">Content</Typography>
                  <FormControl size="small" fullWidth>
                    <InputLabel>Template</InputLabel>
                    <Select
                      label="Template"
                      value={wizard.template_id}
                      disabled={!canUpdate}
                      onChange={(event) => setWizard((prev) => ({ ...prev, template_id: event.target.value }))}
                    >
                      {templates.map((template) => (
                        <MenuItem key={template.id} value={String(template.id)}>
                          {template.name} ({template.channel})
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                  <FormControl size="small" fullWidth>
                    <InputLabel>Channel</InputLabel>
                    <Select
                      label="Channel"
                      value={wizard.channel}
                      disabled={!canUpdate}
                      onChange={(event) => setWizard((prev) => ({ ...prev, channel: event.target.value }))}
                    >
                      <MenuItem value="email">email</MenuItem>
                      <MenuItem value="sms">sms</MenuItem>
                      <MenuItem value="whatsapp">whatsapp</MenuItem>
                    </Select>
                  </FormControl>
                  <Button variant="outlined" onClick={() => saveWizardStep('content')} disabled={!canUpdate}>Save Content</Button>
                </Stack>

                <Stack spacing={1.2}>
                  <Typography variant="subtitle2">Schedule + Rules</Typography>
                  <FormControl size="small" fullWidth>
                    <InputLabel>Campaign Type</InputLabel>
                    <Select
                      label="Campaign Type"
                      value={wizard.campaign_type}
                      disabled={!canUpdate}
                      onChange={(event) => setWizard((prev) => ({ ...prev, campaign_type: event.target.value }))}
                    >
                      <MenuItem value="broadcast">broadcast</MenuItem>
                      <MenuItem value="scheduled">scheduled</MenuItem>
                      <MenuItem value="drip">drip</MenuItem>
                    </Select>
                  </FormControl>
                  <FormControl size="small" fullWidth>
                    <InputLabel>Journey Type</InputLabel>
                    <Select
                      label="Journey Type"
                      value={wizard.journey_type}
                      disabled={!canUpdate}
                      onChange={(event) => setWizard((prev) => ({ ...prev, journey_type: event.target.value }))}
                    >
                      <MenuItem value="default">default</MenuItem>
                      <MenuItem value="reengagement">reengagement</MenuItem>
                    </Select>
                  </FormControl>
                  <TextField
                    size="small"
                    type="datetime-local"
                    label="Start At"
                    InputLabelProps={{ shrink: true }}
                    value={wizard.start_at}
                    disabled={!canUpdate}
                    onChange={(event) => setWizard((prev) => ({ ...prev, start_at: event.target.value }))}
                  />
                  <TextField
                    size="small"
                    type="datetime-local"
                    label="End At"
                    InputLabelProps={{ shrink: true }}
                    value={wizard.end_at}
                    disabled={!canUpdate}
                    onChange={(event) => setWizard((prev) => ({ ...prev, end_at: event.target.value }))}
                  />
                  <Stack direction="row" spacing={1}>
                    <Chip
                      color={wizard.stop_opt_out ? 'primary' : 'default'}
                      label="Stop opt-out"
                      disabled={!canUpdate}
                      onClick={() => setWizard((prev) => ({ ...prev, stop_opt_out: !prev.stop_opt_out }))}
                    />
                    <Chip
                      color={wizard.stop_won_lost ? 'primary' : 'default'}
                      label="Stop won/lost"
                      disabled={!canUpdate}
                      onClick={() => setWizard((prev) => ({ ...prev, stop_won_lost: !prev.stop_won_lost }))}
                    />
                    <Chip
                      color={wizard.stop_replied ? 'primary' : 'default'}
                      label="Stop replied"
                      disabled={!canUpdate}
                      onClick={() => setWizard((prev) => ({ ...prev, stop_replied: !prev.stop_replied }))}
                    />
                    <Chip
                      color={wizard.stop_fatigue_enabled ? 'primary' : 'default'}
                      label="Fatigue control"
                      disabled={!canUpdate}
                      onClick={() => setWizard((prev) => ({ ...prev, stop_fatigue_enabled: !prev.stop_fatigue_enabled }))}
                    />
                    <Chip
                      color={wizard.stop_fatigue_sunset ? 'primary' : 'default'}
                      label="Sunset policy"
                      disabled={!canUpdate}
                      onClick={() => setWizard((prev) => ({ ...prev, stop_fatigue_sunset: !prev.stop_fatigue_sunset }))}
                    />
                  </Stack>
                  <Stack direction={{ xs: 'column', md: 'row' }} spacing={1}>
                    <TextField
                      size="small"
                      type="number"
                      label="Fatigue After Messages"
                      value={wizard.stop_fatigue_threshold_messages}
                      disabled={!canUpdate}
                      onChange={(event) => setWizard((prev) => ({
                        ...prev,
                        stop_fatigue_threshold_messages: event.target.value,
                      }))}
                      inputProps={{ min: 1, max: 1000 }}
                      fullWidth
                    />
                    <TextField
                      size="small"
                      type="number"
                      label="Re-engagement Attempts"
                      value={wizard.stop_fatigue_reengagement_messages}
                      disabled={!canUpdate}
                      onChange={(event) => setWizard((prev) => ({
                        ...prev,
                        stop_fatigue_reengagement_messages: event.target.value,
                      }))}
                      inputProps={{ min: 0, max: 50 }}
                      fullWidth
                    />
                  </Stack>
                  <Stack direction="row" spacing={1}>
                    <Button variant="outlined" onClick={() => saveWizardStep('schedule')} disabled={!canUpdate}>
                      Save Schedule
                    </Button>
                    <Button variant="contained" color="secondary" startIcon={<LaunchIcon />} onClick={launchCampaign} disabled={!canSend}>
                      Launch
                    </Button>
                  </Stack>
                </Stack>
              </Stack>
            )}
          </CardContent>
        </Card>

        <Card sx={{ flex: 1 }}>
          <CardContent>
            <Typography variant="h6">Campaign Logs</Typography>
            <Divider sx={{ my: 1.5 }} />
            <Stack spacing={1}>
              {logs.length === 0 && (
                <Typography color="text.secondary">No logs for selected campaign.</Typography>
              )}
              {logs.map((log) => (
                <Paper key={log.id} variant="outlined" sx={{ p: 1 }}>
                  <Typography variant="body2">
                    <strong>{log.type}</strong> · {log.description ?? 'No description'}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {formatDate(log.created_at)}
                  </Typography>
                </Paper>
              ))}
            </Stack>
          </CardContent>
        </Card>
      </Stack>

      <Dialog open={createDialogOpen} onClose={() => setCreateDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>New Campaign</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              label="Campaign Name"
              value={createForm.name}
              onChange={(event) => setCreateForm((prev) => ({ ...prev, name: event.target.value }))}
            />
            <FormControl size="small">
              <InputLabel>Segment</InputLabel>
              <Select
                label="Segment"
                value={createForm.segment_id}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, segment_id: event.target.value }))}
              >
                <MenuItem value="">None</MenuItem>
                {segments.map((segment) => (
                  <MenuItem key={segment.id} value={String(segment.id)}>
                    {segment.name}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            <FormControl size="small">
              <InputLabel>Template</InputLabel>
              <Select
                label="Template"
                value={createForm.template_id}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, template_id: event.target.value }))}
              >
                <MenuItem value="">None</MenuItem>
                {templates.map((template) => (
                  <MenuItem key={template.id} value={String(template.id)}>
                    {template.name}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            <FormControl size="small">
              <InputLabel>Type</InputLabel>
              <Select
                label="Type"
                value={createForm.campaign_type}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, campaign_type: event.target.value }))}
              >
                <MenuItem value="broadcast">broadcast</MenuItem>
                <MenuItem value="scheduled">scheduled</MenuItem>
                <MenuItem value="drip">drip</MenuItem>
              </Select>
            </FormControl>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCreateDialogOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={createCampaign} disabled={!canCreate}>Create</Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default CampaignsPanel
