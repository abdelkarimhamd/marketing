import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Autocomplete,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Delete as DeleteIcon, Save as SaveIcon, Search as SearchIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

const DEFAULT_TYPES = ['leads', 'deals', 'conversations', 'activities']
const TYPE_OPTIONS = [
  { value: 'leads', label: 'Leads' },
  { value: 'deals', label: 'Deals' },
  { value: 'conversations', label: 'Conversations' },
  { value: 'activities', label: 'Activities' },
]
const STATUS_OPTIONS = ['new', 'contacted', 'won', 'lost']
const CHANNEL_OPTIONS = ['email', 'sms', 'whatsapp']

function parseCsv(text) {
  return String(text ?? '')
    .split(',')
    .map((value) => value.trim())
    .filter((value) => value !== '')
    .filter((value, index, values) => values.indexOf(value) === index)
}

function normalizeStringArray(value) {
  if (!Array.isArray(value)) return []

  return value
    .map((item) => String(item ?? '').trim())
    .filter((item) => item !== '')
    .filter((item, index, values) => values.indexOf(item) === index)
}

function buildDefaultFilters() {
  return {
    q: '',
    types: [...DEFAULT_TYPES],
    status: [],
    sourceText: '',
    channel: [],
    owner_id: '',
    team_id: '',
    min_score: '',
    max_score: '',
    no_response_days: '',
    date_from: '',
    date_to: '',
    per_type: '10',
  }
}

function SearchPanel({ token, tenantId, refreshKey, onNotify }) {
  const [filters, setFilters] = useState(buildDefaultFilters())
  const [searchLoading, setSearchLoading] = useState(false)
  const [results, setResults] = useState(null)
  const [savedViews, setSavedViews] = useState([])
  const [savedViewsLoading, setSavedViewsLoading] = useState(false)
  const [viewSaving, setViewSaving] = useState(false)
  const [viewDeleting, setViewDeleting] = useState(false)
  const [selectedViewId, setSelectedViewId] = useState('')
  const [newViewName, setNewViewName] = useState('')
  const [newViewScope, setNewViewScope] = useState('user')
  const [newViewTeamId, setNewViewTeamId] = useState('')
  const [assignmentOptions, setAssignmentOptions] = useState({ users: [], teams: [] })

  const selectedOwner = useMemo(
    () => assignmentOptions.users.find((user) => String(user.id) === String(filters.owner_id)) ?? null,
    [assignmentOptions.users, filters.owner_id],
  )
  const selectedTeam = useMemo(
    () => assignmentOptions.teams.find((team) => String(team.id) === String(filters.team_id)) ?? null,
    [assignmentOptions.teams, filters.team_id],
  )
  const selectedSavedView = useMemo(
    () => savedViews.find((savedView) => String(savedView.id) === String(selectedViewId)) ?? null,
    [savedViews, selectedViewId],
  )
  const selectedScopeTeam = useMemo(
    () => assignmentOptions.teams.find((team) => String(team.id) === String(newViewTeamId)) ?? null,
    [assignmentOptions.teams, newViewTeamId],
  )

  const buildSearchParams = useCallback((state) => {
    const params = new URLSearchParams()
    const q = String(state.q ?? '').trim()

    if (q !== '') params.set('q', q)

    const types = normalizeStringArray(state.types)
    if (types.length > 0) {
      types.forEach((type) => params.append('types[]', type))
    } else {
      DEFAULT_TYPES.forEach((type) => params.append('types[]', type))
    }

    const statuses = normalizeStringArray(state.status)
    statuses.forEach((status) => params.append('status[]', status))

    const channels = normalizeStringArray(state.channel)
    channels.forEach((channel) => params.append('channel[]', channel))

    const sources = parseCsv(state.sourceText)
    sources.forEach((source) => params.append('source[]', source))

    const ownerId = Number(state.owner_id)
    if (Number.isInteger(ownerId) && ownerId > 0) {
      params.set('owner_id', String(ownerId))
    }

    const teamId = Number(state.team_id)
    if (Number.isInteger(teamId) && teamId > 0) {
      params.set('team_id', String(teamId))
    }

    const minScore = Number(state.min_score)
    if (Number.isInteger(minScore) && minScore >= 0) {
      params.set('min_score', String(minScore))
    }

    const maxScore = Number(state.max_score)
    if (Number.isInteger(maxScore) && maxScore >= 0) {
      params.set('max_score', String(maxScore))
    }

    const noResponseDays = Number(state.no_response_days)
    if (Number.isInteger(noResponseDays) && noResponseDays > 0) {
      params.set('no_response_days', String(noResponseDays))
    }

    const dateFrom = String(state.date_from ?? '').trim()
    if (dateFrom !== '') params.set('date_from', dateFrom)

    const dateTo = String(state.date_to ?? '').trim()
    if (dateTo !== '') params.set('date_to', dateTo)

    const perType = Number(state.per_type)
    if (Number.isInteger(perType) && perType >= 1 && perType <= 50) {
      params.set('per_type', String(perType))
    }

    return params
  }, [])

  const buildSavedViewFilters = useCallback((state) => {
    const payload = {
      types: normalizeStringArray(state.types),
      status: normalizeStringArray(state.status),
      source: parseCsv(state.sourceText),
      channel: normalizeStringArray(state.channel),
    }

    const ownerId = Number(state.owner_id)
    if (Number.isInteger(ownerId) && ownerId > 0) payload.owner_id = ownerId

    const teamId = Number(state.team_id)
    if (Number.isInteger(teamId) && teamId > 0) payload.team_id = teamId

    const minScore = Number(state.min_score)
    if (Number.isInteger(minScore) && minScore >= 0) payload.min_score = minScore

    const maxScore = Number(state.max_score)
    if (Number.isInteger(maxScore) && maxScore >= 0) payload.max_score = maxScore

    const noResponseDays = Number(state.no_response_days)
    if (Number.isInteger(noResponseDays) && noResponseDays > 0) {
      payload.no_response_days = noResponseDays
    }

    if (String(state.date_from ?? '').trim() !== '') payload.date_from = state.date_from
    if (String(state.date_to ?? '').trim() !== '') payload.date_to = state.date_to

    return payload
  }, [])

  const executeSearch = useCallback(
    async (state) => {
      setSearchLoading(true)
      try {
        const params = buildSearchParams(state)
        const response = await apiRequest(`/api/admin/search?${params.toString()}`, { token, tenantId })
        setResults(response)
      } catch (error) {
        onNotify(error.message, 'error')
      } finally {
        setSearchLoading(false)
      }
    },
    [buildSearchParams, onNotify, tenantId, token],
  )

  const loadSavedViews = useCallback(async () => {
    setSavedViewsLoading(true)
    try {
      const response = await apiRequest('/api/admin/saved-views?entity=global_search', { token, tenantId })
      const rows = response.data ?? []
      setSavedViews(rows)
      if (rows.length === 0) {
        setSelectedViewId('')
      } else if (!rows.find((row) => String(row.id) === String(selectedViewId))) {
        setSelectedViewId(String(rows[0].id))
      }
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavedViewsLoading(false)
    }
  }, [onNotify, selectedViewId, tenantId, token])

  const loadAssignmentOptions = useCallback(async () => {
    try {
      const response = await apiRequest('/api/admin/leads/assignment-options?limit=200', { token, tenantId })
      setAssignmentOptions({
        users: response.users ?? [],
        teams: response.teams ?? [],
      })
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadSavedViews()
    loadAssignmentOptions()
  }, [loadAssignmentOptions, loadSavedViews, refreshKey])

  useEffect(() => {
    executeSearch(buildDefaultFilters())
  }, [executeSearch])

  const handleSearchClick = () => {
    executeSearch(filters)
  }

  const handleResetFilters = () => {
    const reset = buildDefaultFilters()
    setFilters(reset)
    executeSearch(reset)
  }

  const handleApplySavedView = () => {
    if (!selectedSavedView) {
      onNotify('Choose a saved view first.', 'warning')
      return
    }

    const viewFilters = selectedSavedView.filters ?? {}
    const nextFilters = {
      ...buildDefaultFilters(),
      q: String(selectedSavedView.query ?? ''),
      types: normalizeStringArray(viewFilters.types).length > 0
        ? normalizeStringArray(viewFilters.types)
        : [...DEFAULT_TYPES],
      status: normalizeStringArray(viewFilters.status),
      sourceText: normalizeStringArray(viewFilters.source).join(', '),
      channel: normalizeStringArray(viewFilters.channel),
      owner_id: viewFilters.owner_id ? String(viewFilters.owner_id) : '',
      team_id: viewFilters.team_id ? String(viewFilters.team_id) : '',
      min_score: viewFilters.min_score ?? '',
      max_score: viewFilters.max_score ?? '',
      no_response_days: viewFilters.no_response_days ?? '',
      date_from: String(viewFilters.date_from ?? ''),
      date_to: String(viewFilters.date_to ?? ''),
      per_type: '10',
    }

    setFilters(nextFilters)
    executeSearch(nextFilters)
    onNotify(`Applied "${selectedSavedView.name}".`, 'success')
  }

  const handleSaveCurrentView = async () => {
    if (newViewName.trim() === '') {
      onNotify('Saved view name is required.', 'warning')
      return
    }

    if (newViewScope === 'team' && (!newViewTeamId || Number(newViewTeamId) <= 0)) {
      onNotify('Choose a team for team scope.', 'warning')
      return
    }

    const payload = {
      name: newViewName.trim(),
      scope: newViewScope,
      entity: 'global_search',
      query: filters.q.trim(),
      filters: buildSavedViewFilters(filters),
    }

    if (newViewScope === 'team') {
      payload.team_id = Number(newViewTeamId)
    }

    setViewSaving(true)
    try {
      await apiRequest('/api/admin/saved-views', {
        method: 'POST',
        token,
        tenantId,
        body: payload,
      })
      onNotify('Saved view created.', 'success')
      setNewViewName('')
      if (newViewScope === 'team') setNewViewTeamId('')
      await loadSavedViews()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setViewSaving(false)
    }
  }

  const handleDeleteSavedView = async () => {
    if (!selectedSavedView) {
      onNotify('Choose a saved view first.', 'warning')
      return
    }

    if (!selectedSavedView.can_edit) {
      onNotify('You cannot delete this saved view.', 'warning')
      return
    }

    if (!window.confirm(`Delete saved view "${selectedSavedView.name}"?`)) {
      return
    }

    setViewDeleting(true)
    try {
      await apiRequest(`/api/admin/saved-views/${selectedSavedView.id}`, {
        method: 'DELETE',
        token,
        tenantId,
      })
      onNotify('Saved view deleted.', 'success')
      setSelectedViewId('')
      await loadSavedViews()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setViewDeleting(false)
    }
  }

  const counts = results?.counts ?? {}

  return (
    <Stack spacing={2}>
      <Card>
        <CardContent>
          <Stack spacing={1.4}>
            <Typography variant="h6">Global Search</Typography>
            <Grid container spacing={1.2}>
              <Grid size={{ xs: 12, md: 6 }}>
                <TextField
                  fullWidth
                  size="small"
                  label="Search"
                  placeholder="Name, email, company, message, activity..."
                  value={filters.q}
                  onChange={(event) => setFilters((current) => ({ ...current, q: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Types</InputLabel>
                  <Select
                    multiple
                    label="Types"
                    value={filters.types}
                    onChange={(event) => setFilters((current) => ({ ...current, types: event.target.value }))}
                    renderValue={(selected) => selected.join(', ')}
                  >
                    {TYPE_OPTIONS.map((option) => (
                      <MenuItem key={option.value} value={option.value}>
                        {option.label}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Per Type</InputLabel>
                  <Select
                    label="Per Type"
                    value={filters.per_type}
                    onChange={(event) => setFilters((current) => ({ ...current, per_type: event.target.value }))}
                  >
                    <MenuItem value="5">5</MenuItem>
                    <MenuItem value="10">10</MenuItem>
                    <MenuItem value="20">20</MenuItem>
                    <MenuItem value="50">50</MenuItem>
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Status</InputLabel>
                  <Select
                    multiple
                    label="Status"
                    value={filters.status}
                    onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}
                    renderValue={(selected) => selected.join(', ')}
                  >
                    {STATUS_OPTIONS.map((status) => (
                      <MenuItem key={status} value={status}>
                        {status}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <TextField
                  fullWidth
                  size="small"
                  label="Sources"
                  placeholder="website, referral"
                  value={filters.sourceText}
                  onChange={(event) => setFilters((current) => ({ ...current, sourceText: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Channels</InputLabel>
                  <Select
                    multiple
                    label="Channels"
                    value={filters.channel}
                    onChange={(event) => setFilters((current) => ({ ...current, channel: event.target.value }))}
                    renderValue={(selected) => selected.join(', ')}
                  >
                    {CHANNEL_OPTIONS.map((channel) => (
                      <MenuItem key={channel} value={channel}>
                        {channel}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, md: 3 }}>
                <Autocomplete
                  size="small"
                  options={assignmentOptions.users}
                  value={selectedOwner}
                  onChange={(_, option) =>
                    setFilters((current) => ({
                      ...current,
                      owner_id: option ? String(option.id) : '',
                    }))}
                  getOptionLabel={(option) => `${option.name} (${option.email})`}
                  isOptionEqualToValue={(option, value) => option.id === value.id}
                  renderInput={(params) => <TextField {...params} label="Owner" />}
                />
              </Grid>
              <Grid size={{ xs: 12, md: 3 }}>
                <Autocomplete
                  size="small"
                  options={assignmentOptions.teams}
                  value={selectedTeam}
                  onChange={(_, option) =>
                    setFilters((current) => ({
                      ...current,
                      team_id: option ? String(option.id) : '',
                    }))}
                  getOptionLabel={(option) => option.name}
                  isOptionEqualToValue={(option, value) => option.id === value.id}
                  renderInput={(params) => <TextField {...params} label="Team" />}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 4, md: 2 }}>
                <TextField
                  size="small"
                  fullWidth
                  label="Min Score"
                  value={filters.min_score}
                  onChange={(event) => setFilters((current) => ({ ...current, min_score: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 4, md: 2 }}>
                <TextField
                  size="small"
                  fullWidth
                  label="Max Score"
                  value={filters.max_score}
                  onChange={(event) => setFilters((current) => ({ ...current, max_score: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 4, md: 2 }}>
                <TextField
                  size="small"
                  fullWidth
                  label="No Response (days)"
                  value={filters.no_response_days}
                  onChange={(event) => setFilters((current) => ({ ...current, no_response_days: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <TextField
                  size="small"
                  type="date"
                  fullWidth
                  label="From Date"
                  value={filters.date_from}
                  onChange={(event) => setFilters((current) => ({ ...current, date_from: event.target.value }))}
                  InputLabelProps={{ shrink: true }}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <TextField
                  size="small"
                  type="date"
                  fullWidth
                  label="To Date"
                  value={filters.date_to}
                  onChange={(event) => setFilters((current) => ({ ...current, date_to: event.target.value }))}
                  InputLabelProps={{ shrink: true }}
                />
              </Grid>
            </Grid>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
              <Button variant="contained" startIcon={<SearchIcon />} onClick={handleSearchClick} disabled={searchLoading}>
                Search
              </Button>
              <Button variant="outlined" onClick={handleResetFilters} disabled={searchLoading}>
                Reset
              </Button>
            </Stack>
          </Stack>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Stack spacing={1.4}>
            <Typography variant="h6">Saved Views</Typography>
            <Grid container spacing={1.2}>
              <Grid size={{ xs: 12, md: 4 }}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Saved View</InputLabel>
                  <Select
                    label="Saved View"
                    value={selectedViewId}
                    onChange={(event) => setSelectedViewId(event.target.value)}
                    disabled={savedViewsLoading}
                  >
                    {savedViews.map((view) => (
                      <MenuItem key={view.id} value={String(view.id)}>
                        {view.name} ({view.scope})
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, md: 8 }}>
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                  <Button variant="outlined" onClick={handleApplySavedView} disabled={!selectedSavedView}>
                    Apply
                  </Button>
                  <Button
                    color="error"
                    variant="outlined"
                    startIcon={<DeleteIcon />}
                    onClick={handleDeleteSavedView}
                    disabled={!selectedSavedView || !selectedSavedView?.can_edit || viewDeleting}
                  >
                    {viewDeleting ? 'Deleting...' : 'Delete'}
                  </Button>
                  {savedViewsLoading && (
                    <Stack direction="row" alignItems="center" spacing={1}>
                      <CircularProgress size={18} />
                      <Typography variant="body2" color="text.secondary">Loading saved views...</Typography>
                    </Stack>
                  )}
                </Stack>
              </Grid>
              <Grid size={{ xs: 12, md: 4 }}>
                <TextField
                  size="small"
                  fullWidth
                  label="New View Name"
                  value={newViewName}
                  onChange={(event) => setNewViewName(event.target.value)}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Scope</InputLabel>
                  <Select label="Scope" value={newViewScope} onChange={(event) => setNewViewScope(event.target.value)}>
                    <MenuItem value="user">User (Private)</MenuItem>
                    <MenuItem value="team">Team</MenuItem>
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <Autocomplete
                  size="small"
                  options={assignmentOptions.teams}
                  value={selectedScopeTeam}
                  onChange={(_, option) => setNewViewTeamId(option ? String(option.id) : '')}
                  getOptionLabel={(option) => option.name}
                  isOptionEqualToValue={(option, value) => option.id === value.id}
                  disabled={newViewScope !== 'team'}
                  renderInput={(params) => <TextField {...params} label="Team (for team scope)" />}
                />
              </Grid>
              <Grid size={{ xs: 12, md: 2 }}>
                <Button
                  fullWidth
                  variant="contained"
                  startIcon={<SaveIcon />}
                  disabled={viewSaving}
                  onClick={handleSaveCurrentView}
                >
                  {viewSaving ? 'Saving...' : 'Save Current'}
                </Button>
              </Grid>
            </Grid>
          </Stack>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Stack spacing={1.2}>
            <Typography variant="h6">Results</Typography>
            <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
              <Chip label={`Leads: ${counts.leads ?? 0}`} />
              <Chip label={`Deals: ${counts.deals ?? 0}`} />
              <Chip label={`Conversations: ${counts.conversations ?? 0}`} />
              <Chip label={`Activities: ${counts.activities ?? 0}`} />
            </Stack>

            {searchLoading && (
              <Stack direction="row" spacing={1} alignItems="center">
                <CircularProgress size={20} />
                <Typography color="text.secondary">Searching...</Typography>
              </Stack>
            )}

            {!searchLoading && (
              <Stack spacing={1.2}>
                {results?.results?.leads?.length > 0 && (
                  <Box>
                    <Typography variant="subtitle1">Leads</Typography>
                    <Stack spacing={1}>
                      {results.results.leads.map((row) => (
                        <Paper key={`lead-${row.id}`} variant="outlined" sx={{ p: 1.2 }}>
                          <Typography variant="body2">
                            <strong>{row.name ?? 'Unnamed lead'}</strong> - {row.email ?? row.phone ?? '-'}
                          </Typography>
                          <Typography variant="caption" color="text.secondary">
                            {row.company ?? '-'} | {row.status ?? '-'} | score {row.score ?? 0}
                          </Typography>
                        </Paper>
                      ))}
                    </Stack>
                  </Box>
                )}

                {results?.results?.deals?.length > 0 && (
                  <Box>
                    <Typography variant="subtitle1">Deals</Typography>
                    <Stack spacing={1}>
                      {results.results.deals.map((row) => (
                        <Paper key={`deal-${row.id}`} variant="outlined" sx={{ p: 1.2 }}>
                          <Typography variant="body2">
                            <strong>{row.name ?? 'Unnamed deal lead'}</strong> - {row.company ?? '-'}
                          </Typography>
                          <Typography variant="caption" color="text.secondary">
                            Stage: {row.status ?? '-'} | owner: {row.owner?.name ?? '-'}
                          </Typography>
                        </Paper>
                      ))}
                    </Stack>
                  </Box>
                )}

                {results?.results?.conversations?.length > 0 && (
                  <Box>
                    <Typography variant="subtitle1">Conversations</Typography>
                    <Stack spacing={1}>
                      {results.results.conversations.map((row) => (
                        <Paper key={`conversation-${row.id}`} variant="outlined" sx={{ p: 1.2 }}>
                          <Typography variant="body2">
                            <strong>{row.subject ?? '(No subject)'}</strong> [{row.channel}]
                          </Typography>
                          <Typography variant="body2" color="text.secondary">
                            {row.body_excerpt || '-'}
                          </Typography>
                          <Typography variant="caption" color="text.secondary">
                            {row.lead?.name ?? 'No lead'} | {formatDate(row.created_at)}
                          </Typography>
                        </Paper>
                      ))}
                    </Stack>
                  </Box>
                )}

                {results?.results?.activities?.length > 0 && (
                  <Box>
                    <Typography variant="subtitle1">Activities</Typography>
                    <Stack spacing={1}>
                      {results.results.activities.map((row) => (
                        <Paper key={`activity-${row.id}`} variant="outlined" sx={{ p: 1.2 }}>
                          <Typography variant="body2">
                            <strong>{row.type}</strong> - {row.description ?? '-'}
                          </Typography>
                          <Typography variant="caption" color="text.secondary">
                            Actor: {row.actor?.name ?? '-'} | {formatDate(row.created_at)}
                          </Typography>
                        </Paper>
                      ))}
                    </Stack>
                  </Box>
                )}

                {results && (
                  <Typography variant="caption" color="text.secondary">
                    Total matches: {counts.total ?? 0}
                  </Typography>
                )}

                {!results && (
                  <Typography color="text.secondary">Run a search to see results.</Typography>
                )}

                {results && (counts.total ?? 0) === 0 && (
                  <Typography color="text.secondary">No results match current filters.</Typography>
                )}
              </Stack>
            )}
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  )
}

export default SearchPanel
