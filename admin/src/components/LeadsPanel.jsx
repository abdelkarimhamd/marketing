import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Autocomplete,
  Box,
  Button,
  Card,
  CardContent,
  Checkbox,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  Drawer,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon, Edit as EditIcon, Save as SaveIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'
import { LEAD_IMPORT_FIELDS, mapImportRows, parseImportFile } from '../lib/importParser'

const STATUS_OPTIONS = ['new', 'contacted', 'won', 'lost']

function parseCsv(text) {
  return String(text ?? '')
    .split(',')
    .map((value) => value.trim())
    .filter((value) => value !== '')
    .filter((value, index, array) => array.indexOf(value) === index)
}

function createEmptyLeadForm() {
  return {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    company: '',
    city: '',
    interest: '',
    service: '',
    status: 'new',
    source: 'admin',
    tagsText: '',
    auto_assign: true,
  }
}

function LeadsPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [loading, setLoading] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [leadsPage, setLeadsPage] = useState({ data: [], total: 0, current_page: 1, last_page: 1 })
  const [assignmentOptions, setAssignmentOptions] = useState({ users: [], teams: [] })
  const [assignmentOptionsLoading, setAssignmentOptionsLoading] = useState(false)
  const [selectedLead, setSelectedLead] = useState(null)
  const [leadActivities, setLeadActivities] = useState([])
  const [playbookSuggestions, setPlaybookSuggestions] = useState([])
  const [playbookSuggestionsLoading, setPlaybookSuggestionsLoading] = useState(false)
  const [selectedLeadIds, setSelectedLeadIds] = useState([])
  const [leadDialogOpen, setLeadDialogOpen] = useState(false)
  const [editingLead, setEditingLead] = useState(null)
  const [leadForm, setLeadForm] = useState(createEmptyLeadForm())
  const [leadSaving, setLeadSaving] = useState(false)
  const [bulkAction, setBulkAction] = useState({
    action: '',
    status: 'contacted',
    tagsText: '',
    owner_id: '',
    team_id: '',
  })
  const [bulkRunning, setBulkRunning] = useState(false)
  const [importDialogOpen, setImportDialogOpen] = useState(false)
  const [importRows, setImportRows] = useState([])
  const [importColumns, setImportColumns] = useState([])
  const [importMapping, setImportMapping] = useState({})
  const [importing, setImporting] = useState(false)
  const canCreate = can('leads.create')
  const canUpdate = can('leads.update')
  const canDelete = can('leads.delete')
  const canSuggestPlaybooks = can('playbooks.suggest')

  const leadIdsOnPage = useMemo(
    () => (leadsPage.data ?? []).map((lead) => Number(lead.id)),
    [leadsPage.data],
  )
  const allPageSelected = leadIdsOnPage.length > 0 && leadIdsOnPage.every((id) => selectedLeadIds.includes(id))
  const somePageSelected = leadIdsOnPage.some((id) => selectedLeadIds.includes(id)) && !allPageSelected
  const selectedBulkOwner = useMemo(
    () => assignmentOptions.users.find((user) => String(user.id) === String(bulkAction.owner_id)) ?? null,
    [assignmentOptions.users, bulkAction.owner_id],
  )
  const selectedBulkTeam = useMemo(
    () => assignmentOptions.teams.find((team) => String(team.id) === String(bulkAction.team_id)) ?? null,
    [assignmentOptions.teams, bulkAction.team_id],
  )

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  const loadLeads = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams({
        page: String(page),
        per_page: '15',
      })

      if (debouncedSearch.trim() !== '') params.set('search', debouncedSearch.trim())
      if (status !== '') params.set('status', status)

      const response = await apiRequest(`/api/admin/leads?${params.toString()}`, { token, tenantId })
      setLeadsPage(response)
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }, [debouncedSearch, onNotify, page, status, tenantId, token])

  const loadAssignmentOptions = useCallback(async () => {
    if (!tenantId) {
      setAssignmentOptions({ users: [], teams: [] })
      return
    }

    setAssignmentOptionsLoading(true)
    try {
      const response = await apiRequest('/api/admin/leads/assignment-options?limit=200', { token, tenantId })
      setAssignmentOptions({
        users: response.users ?? [],
        teams: response.teams ?? [],
      })
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setAssignmentOptionsLoading(false)
    }
  }, [onNotify, tenantId, token])

  const loadLeadActivities = useCallback(
    async (leadId) => {
      try {
        const response = await apiRequest(`/api/admin/leads/${leadId}/activities`, { token, tenantId })
        setLeadActivities(response.data ?? [])
      } catch (error) {
        onNotify(error.message, 'error')
        setLeadActivities([])
      }
    },
    [onNotify, tenantId, token],
  )

  const loadLeadSuggestions = useCallback(
    async (lead) => {
      if (!canSuggestPlaybooks) {
        setPlaybookSuggestions([])
        return
      }

      if (!lead?.id) {
        setPlaybookSuggestions([])
        return
      }

      setPlaybookSuggestionsLoading(true)
      try {
        const params = new URLSearchParams({
          lead_id: String(lead.id),
          limit: '5',
        })

        if (lead.status) params.set('stage', String(lead.status))

        const q = [lead.company, lead.interest, lead.service].filter(Boolean).join(' ').trim()
        if (q) params.set('q', q)

        const response = await apiRequest(`/api/admin/playbooks/suggestions?${params.toString()}`, { token, tenantId })
        setPlaybookSuggestions(response.suggestions ?? [])
      } catch {
        // Keep suggestions non-blocking when permission is missing.
        setPlaybookSuggestions([])
      } finally {
        setPlaybookSuggestionsLoading(false)
      }
    },
    [canSuggestPlaybooks, tenantId, token],
  )

  useEffect(() => {
    loadLeads()
  }, [loadLeads, refreshKey])

  useEffect(() => {
    loadAssignmentOptions()
  }, [loadAssignmentOptions, refreshKey])

  useEffect(() => {
    setSelectedLeadIds((current) => current.filter((id) => leadIdsOnPage.includes(id)))
  }, [leadIdsOnPage])

  const openLead = (lead) => {
    setSelectedLead(lead)
    loadLeadActivities(lead.id)
    loadLeadSuggestions(lead)
  }

  const closeLead = () => {
    setSelectedLead(null)
    setLeadActivities([])
    setPlaybookSuggestions([])
  }

  const openNewLead = () => {
    if (!canCreate) {
      onNotify('You do not have permission to create leads.', 'warning')
      return
    }

    setEditingLead(null)
    setLeadForm(createEmptyLeadForm())
    setLeadDialogOpen(true)
  }

  const openEditLead = (lead) => {
    if (!canUpdate) {
      onNotify('You do not have permission to update leads.', 'warning')
      return
    }

    setEditingLead(lead)
    setLeadForm({
      first_name: lead.first_name ?? '',
      last_name: lead.last_name ?? '',
      email: lead.email ?? '',
      phone: lead.phone ?? '',
      company: lead.company ?? '',
      city: lead.city ?? '',
      interest: lead.interest ?? '',
      service: lead.service ?? '',
      status: lead.status ?? 'new',
      source: lead.source ?? 'admin',
      tagsText: (lead.tags ?? []).map((tag) => tag.name).join(', '),
      auto_assign: true,
    })
    setLeadDialogOpen(true)
  }

  const saveLead = async () => {
    if (editingLead && !canUpdate) {
      onNotify('You do not have permission to update leads.', 'warning')
      return
    }

    if (!editingLead && !canCreate) {
      onNotify('You do not have permission to create leads.', 'warning')
      return
    }

    const email = leadForm.email.trim()
    const phone = leadForm.phone.trim()
    const tags = parseCsv(leadForm.tagsText)

    if (!editingLead && email === '' && phone === '') {
      onNotify('Email or phone is required.', 'warning')
      return
    }

    const payload = {
      first_name: leadForm.first_name.trim() || null,
      last_name: leadForm.last_name.trim() || null,
      email: email || null,
      phone: phone || null,
      company: leadForm.company.trim() || null,
      city: leadForm.city.trim() || null,
      interest: leadForm.interest.trim() || null,
      service: leadForm.service.trim() || null,
      status: leadForm.status,
      source: leadForm.source.trim() || null,
      tags,
    }

    if (!editingLead) {
      payload.auto_assign = Boolean(leadForm.auto_assign)
    }

    setLeadSaving(true)
    try {
      if (editingLead) {
        await apiRequest(`/api/admin/leads/${editingLead.id}`, {
          method: 'PATCH',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Lead updated.', 'success')
      } else {
        await apiRequest('/api/admin/leads', {
          method: 'POST',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Lead created.', 'success')
      }

      setLeadDialogOpen(false)
      setEditingLead(null)
      setLeadForm(createEmptyLeadForm())
      loadLeads()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLeadSaving(false)
    }
  }

  const deleteLead = async (lead) => {
    if (!canDelete) {
      onNotify('You do not have permission to delete leads.', 'warning')
      return
    }

    if (!window.confirm(`Delete lead #${lead.id} (${lead.email ?? lead.phone ?? 'no contact'})?`)) {
      return
    }

    try {
      await apiRequest(`/api/admin/leads/${lead.id}`, { method: 'DELETE', token, tenantId })
      onNotify('Lead deleted.', 'success')
      setSelectedLeadIds((current) => current.filter((id) => id !== Number(lead.id)))
      if (selectedLead?.id === lead.id) {
        closeLead()
      }
      loadLeads()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const toggleLeadSelection = (leadId) => {
    setSelectedLeadIds((current) => {
      const id = Number(leadId)
      if (current.includes(id)) {
        return current.filter((rowId) => rowId !== id)
      }
      return [...current, id]
    })
  }

  const togglePageSelection = () => {
    setSelectedLeadIds((current) => {
      if (allPageSelected) {
        return current.filter((id) => !leadIdsOnPage.includes(id))
      }

      const merged = [...current]
      leadIdsOnPage.forEach((id) => {
        if (!merged.includes(id)) merged.push(id)
      })
      return merged
    })
  }

  const runBulkAction = async () => {
    if (!canUpdate) {
      onNotify('You do not have permission to update leads.', 'warning')
      return
    }

    if (selectedLeadIds.length === 0) {
      onNotify('Select at least one lead.', 'warning')
      return
    }

    if (bulkAction.action === '') {
      onNotify('Choose a bulk action.', 'warning')
      return
    }

    const payload = {
      action: bulkAction.action,
      lead_ids: selectedLeadIds,
    }

    if (bulkAction.action === 'status') {
      payload.status = bulkAction.status
    }

    if (bulkAction.action === 'tag') {
      const tags = parseCsv(bulkAction.tagsText)
      if (tags.length === 0) {
        onNotify('Enter at least one tag.', 'warning')
        return
      }
      payload.tags = tags
    }

    if (bulkAction.action === 'assign') {
      const ownerId = bulkAction.owner_id === '' ? null : Number(bulkAction.owner_id)
      const teamId = bulkAction.team_id === '' ? null : Number(bulkAction.team_id)

      if (ownerId !== null && (!Number.isInteger(ownerId) || ownerId <= 0)) {
        onNotify('Owner ID must be a positive number.', 'warning')
        return
      }

      if (teamId !== null && (!Number.isInteger(teamId) || teamId <= 0)) {
        onNotify('Team ID must be a positive number.', 'warning')
        return
      }

      if (ownerId === null && teamId === null) {
        onNotify('Provide owner ID or team ID.', 'warning')
        return
      }

      if (ownerId !== null) payload.owner_id = ownerId
      if (teamId !== null) payload.team_id = teamId
    }

    setBulkRunning(true)
    try {
      const response = await apiRequest('/api/admin/leads/bulk', {
        method: 'POST',
        token,
        tenantId,
        body: payload,
      })

      onNotify(response.message ?? 'Bulk action completed.', 'success')
      setSelectedLeadIds([])
      setBulkAction((current) => ({ ...current, owner_id: '', team_id: '', tagsText: '' }))
      loadLeads()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setBulkRunning(false)
    }
  }

  const handleFileSelection = async (file) => {
    try {
      const parsed = await parseImportFile(file)
      setImportRows(parsed.rows)
      setImportColumns(parsed.columns)
      setImportMapping(parsed.mapping)
      onNotify('File parsed. Map columns and import.', 'success')
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const runImport = async () => {
    if (!canCreate) {
      onNotify('You do not have permission to import leads.', 'warning')
      return
    }

    const leads = mapImportRows(importRows, importMapping)

    if (leads.length === 0) {
      onNotify('No valid rows to import after mapping.', 'warning')
      return
    }

    setImporting(true)
    try {
      await apiRequest('/api/admin/leads/import', {
        method: 'POST',
        token,
        tenantId,
        body: {
          auto_assign: true,
          leads,
        },
      })

      onNotify(`Imported ${leads.length} leads.`, 'success')
      setImportDialogOpen(false)
      setImportRows([])
      setImportColumns([])
      setImportMapping({})
      setPage(1)
      loadLeads()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setImporting(false)
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', lg: 'row' }} spacing={1.2} justifyContent="space-between">
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2} alignItems={{ xs: 'stretch', md: 'center' }}>
          <TextField
            label="Search leads"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            size="small"
            sx={{ minWidth: 220 }}
          />
          <FormControl size="small" sx={{ minWidth: 140 }}>
            <InputLabel>Status</InputLabel>
            <Select
              label="Status"
              value={status}
              onChange={(event) => {
                setStatus(event.target.value)
                setPage(1)
              }}
            >
              <MenuItem value="">All</MenuItem>
              {STATUS_OPTIONS.map((item) => (
                <MenuItem key={item} value={item}>
                  {item}
                </MenuItem>
              ))}
            </Select>
          </FormControl>
          <Typography variant="caption" color="text.secondary">
            Filters auto-apply.
          </Typography>
        </Stack>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
          {canCreate && (
            <>
              <Button variant="outlined" startIcon={<AddIcon />} onClick={() => setImportDialogOpen(true)}>
                Import CSV/XLSX
              </Button>
              <Button variant="contained" startIcon={<AddIcon />} onClick={openNewLead}>
                Create Lead
              </Button>
            </>
          )}
        </Stack>
      </Stack>

      {canUpdate && selectedLeadIds.length > 0 && (
        <Card
          variant="outlined"
          sx={{
            position: 'sticky',
            top: { xs: 70, md: 80 },
            zIndex: 10,
            borderColor: 'primary.light',
          }}
        >
          <CardContent>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2} alignItems={{ xs: 'stretch', md: 'center' }}>
              <Typography variant="subtitle2">Selected: {selectedLeadIds.length}</Typography>
              <Button
                size="small"
                onClick={() => setSelectedLeadIds([])}
                sx={{ alignSelf: { xs: 'flex-start', md: 'center' } }}
              >
                Clear Selection
              </Button>
              <FormControl size="small" sx={{ minWidth: 160 }}>
                <InputLabel>Bulk Action</InputLabel>
                <Select
                  label="Bulk Action"
                  value={bulkAction.action}
                  onChange={(event) =>
                    setBulkAction((current) => ({
                      ...current,
                      action: event.target.value,
                    }))
                  }
                >
                  <MenuItem value="">Select...</MenuItem>
                  <MenuItem value="assign">Assign</MenuItem>
                  <MenuItem value="tag">Tag</MenuItem>
                  <MenuItem value="status">Status</MenuItem>
                </Select>
              </FormControl>

              {bulkAction.action === 'assign' && (
                <>
                  <Autocomplete
                    size="small"
                    sx={{ minWidth: 260 }}
                    options={assignmentOptions.users}
                    loading={assignmentOptionsLoading}
                    value={selectedBulkOwner}
                    onChange={(_, userOption) =>
                      setBulkAction((current) => ({
                        ...current,
                        owner_id: userOption ? String(userOption.id) : '',
                      }))
                    }
                    getOptionLabel={(option) => `${option.name} (${option.email})`}
                    isOptionEqualToValue={(option, value) => option.id === value.id}
                    renderInput={(params) => (
                      <TextField
                        {...params}
                        label="Owner"
                        placeholder="Select owner"
                        InputProps={{
                          ...params.InputProps,
                          endAdornment: (
                            <>
                              {assignmentOptionsLoading ? <CircularProgress color="inherit" size={16} /> : null}
                              {params.InputProps.endAdornment}
                            </>
                          ),
                        }}
                      />
                    )}
                  />
                  <Autocomplete
                    size="small"
                    sx={{ minWidth: 220 }}
                    options={assignmentOptions.teams}
                    loading={assignmentOptionsLoading}
                    value={selectedBulkTeam}
                    onChange={(_, teamOption) =>
                      setBulkAction((current) => ({
                        ...current,
                        team_id: teamOption ? String(teamOption.id) : '',
                      }))
                    }
                    getOptionLabel={(option) => option.name}
                    isOptionEqualToValue={(option, value) => option.id === value.id}
                    renderInput={(params) => (
                      <TextField
                        {...params}
                        label="Team"
                        placeholder="Select team"
                        InputProps={{
                          ...params.InputProps,
                          endAdornment: (
                            <>
                              {assignmentOptionsLoading ? <CircularProgress color="inherit" size={16} /> : null}
                              {params.InputProps.endAdornment}
                            </>
                          ),
                        }}
                      />
                    )}
                  />
                </>
              )}

              {bulkAction.action === 'tag' && (
                <TextField
                  size="small"
                  label="Tags (comma-separated)"
                  sx={{ minWidth: 260 }}
                  value={bulkAction.tagsText}
                  onChange={(event) =>
                    setBulkAction((current) => ({
                      ...current,
                      tagsText: event.target.value,
                    }))
                  }
                />
              )}

              {bulkAction.action === 'status' && (
                <FormControl size="small" sx={{ minWidth: 150 }}>
                  <InputLabel>Status</InputLabel>
                  <Select
                    label="Status"
                    value={bulkAction.status}
                    onChange={(event) =>
                      setBulkAction((current) => ({
                        ...current,
                        status: event.target.value,
                      }))
                    }
                  >
                    {STATUS_OPTIONS.map((item) => (
                      <MenuItem key={item} value={item}>
                        {item}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              )}

              <Button variant="contained" onClick={runBulkAction} disabled={bulkRunning || selectedLeadIds.length === 0}>
                {bulkRunning ? 'Applying...' : 'Apply Bulk'}
              </Button>
            </Stack>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardContent sx={{ p: 0 }}>
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell padding="checkbox">
                    <Checkbox
                      indeterminate={somePageSelected}
                      checked={allPageSelected}
                      disabled={!canUpdate}
                      onChange={togglePageSelection}
                      inputProps={{ 'aria-label': 'select all leads on page' }}
                    />
                  </TableCell>
                  <TableCell>Name</TableCell>
                  <TableCell>Email</TableCell>
                  <TableCell>Phone</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Owner</TableCell>
                  <TableCell>Tags</TableCell>
                  <TableCell align="right">Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {(leadsPage.data ?? []).map((lead) => (
                  <TableRow key={lead.id} hover>
                    <TableCell padding="checkbox">
                      <Checkbox
                        checked={selectedLeadIds.includes(Number(lead.id))}
                        disabled={!canUpdate}
                        onChange={() => toggleLeadSelection(lead.id)}
                        inputProps={{ 'aria-label': `select lead ${lead.id}` }}
                      />
                    </TableCell>
                    <TableCell>{`${lead.first_name ?? ''} ${lead.last_name ?? ''}`.trim() || '-'}</TableCell>
                    <TableCell>{lead.email ?? '-'}</TableCell>
                    <TableCell>{lead.phone ?? '-'}</TableCell>
                    <TableCell>{lead.status ?? '-'}</TableCell>
                    <TableCell>{lead.owner?.name ?? '-'}</TableCell>
                    <TableCell>{(lead.tags ?? []).map((tag) => tag.name).join(', ') || '-'}</TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={0.5} justifyContent="flex-end">
                        <Button size="small" onClick={() => openLead(lead)}>
                          View
                        </Button>
                        {canUpdate && (
                          <Button size="small" startIcon={<EditIcon />} onClick={() => openEditLead(lead)}>
                            Edit
                          </Button>
                        )}
                        {canDelete && (
                          <Button size="small" color="error" startIcon={<DeleteIcon />} onClick={() => deleteLead(lead)}>
                            Delete
                          </Button>
                        )}
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}
                {(leadsPage.data ?? []).length === 0 && (
                  <TableRow>
                    <TableCell colSpan={8}>
                      <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                        {loading ? 'Loading leads...' : 'No leads found.'}
                      </Typography>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>
          <Box sx={{ p: 1.5, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <Typography variant="body2" color="text.secondary">
              Total: {leadsPage.total ?? 0}
            </Typography>
            <Stack direction="row" spacing={1}>
              <Button disabled={(leadsPage.current_page ?? 1) <= 1} onClick={() => setPage((prev) => Math.max(1, prev - 1))}>
                Prev
              </Button>
              <Typography sx={{ alignSelf: 'center' }}>
                Page {leadsPage.current_page ?? 1} / {leadsPage.last_page ?? 1}
              </Typography>
              <Button
                disabled={(leadsPage.current_page ?? 1) >= (leadsPage.last_page ?? 1)}
                onClick={() => setPage((prev) => prev + 1)}
              >
                Next
              </Button>
            </Stack>
          </Box>
        </CardContent>
      </Card>

      <Drawer anchor="right" open={Boolean(selectedLead)} onClose={closeLead}>
        <Box sx={{ width: { xs: '100vw', sm: 420 }, p: 2.2 }}>
          <Typography variant="h6">Lead Details</Typography>
          <Divider sx={{ my: 1.5 }} />
          {selectedLead && (
            <Stack spacing={1}>
              <Typography><strong>Name:</strong> {`${selectedLead.first_name ?? ''} ${selectedLead.last_name ?? ''}`.trim() || '-'}</Typography>
              <Typography><strong>Email:</strong> {selectedLead.email ?? '-'}</Typography>
              <Typography><strong>Phone:</strong> {selectedLead.phone ?? '-'}</Typography>
              <Typography><strong>Status:</strong> {selectedLead.status ?? '-'}</Typography>
              <Typography><strong>Source:</strong> {selectedLead.source ?? '-'}</Typography>
              <Typography><strong>Company:</strong> {selectedLead.company ?? '-'}</Typography>
              <Typography><strong>City:</strong> {selectedLead.city ?? '-'}</Typography>

              {(canUpdate || canDelete) && (
                <Stack direction="row" spacing={1}>
                  {canUpdate && (
                    <Button size="small" startIcon={<EditIcon />} onClick={() => openEditLead(selectedLead)}>
                      Edit
                    </Button>
                  )}
                  {canDelete && (
                    <Button size="small" color="error" startIcon={<DeleteIcon />} onClick={() => deleteLead(selectedLead)}>
                      Delete
                    </Button>
                  )}
                </Stack>
              )}

              <Divider sx={{ my: 1 }} />
              <Typography variant="subtitle1">Activities</Typography>
              <Stack spacing={1}>
                {leadActivities.length === 0 && (
                  <Typography color="text.secondary">No activity found for this lead.</Typography>
                )}
                {leadActivities.map((activity) => (
                  <Paper key={activity.id} variant="outlined" sx={{ p: 1 }}>
                    <Typography variant="body2">{activity.type}</Typography>
                    <Typography variant="caption" color="text.secondary">
                      {formatDate(activity.created_at)}
                    </Typography>
                  </Paper>
                ))}
              </Stack>

              <Divider sx={{ my: 1 }} />
              {canSuggestPlaybooks && (
                <>
                  <Typography variant="subtitle1">Playbook Suggestions</Typography>
                  <Stack spacing={1}>
                    {playbookSuggestionsLoading && (
                      <Typography color="text.secondary">Loading suggestions...</Typography>
                    )}
                    {!playbookSuggestionsLoading && playbookSuggestions.length === 0 && (
                      <Typography color="text.secondary">No suggestions for this stage/context.</Typography>
                    )}
                    {playbookSuggestions.map((suggestion) => (
                      <Paper key={`lead-suggestion-${suggestion.playbook_id}`} variant="outlined" sx={{ p: 1 }}>
                        <Typography variant="body2">
                          <strong>{suggestion.name}</strong> ({suggestion.industry})
                        </Typography>
                        <Typography variant="caption" color="text.secondary" display="block">
                          Stage: {suggestion.stage || 'any'} | Channel: {suggestion.channel || 'any'}
                        </Typography>
                        {(suggestion.scripts ?? []).length > 0 && (
                          <Typography variant="caption" display="block">
                            Script: {suggestion.scripts[0]}
                          </Typography>
                        )}
                        {(suggestion.objections ?? []).length > 0 && (
                          <Typography variant="caption" display="block">
                            Response: {suggestion.objections[0].response || '-'}
                          </Typography>
                        )}
                      </Paper>
                    ))}
                  </Stack>
                </>
              )}
            </Stack>
          )}
        </Box>
      </Drawer>

      <Dialog open={leadDialogOpen} onClose={() => setLeadDialogOpen(false)} maxWidth="md" fullWidth>
        <DialogTitle>{editingLead ? `Edit Lead #${editingLead.id}` : 'Create Lead'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <Grid container spacing={1.2}>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label="First Name"
                  fullWidth
                  value={leadForm.first_name}
                  onChange={(event) => setLeadForm((current) => ({ ...current, first_name: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label="Last Name"
                  fullWidth
                  value={leadForm.last_name}
                  onChange={(event) => setLeadForm((current) => ({ ...current, last_name: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label="Email"
                  fullWidth
                  value={leadForm.email}
                  onChange={(event) => setLeadForm((current) => ({ ...current, email: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label="Phone"
                  fullWidth
                  value={leadForm.phone}
                  onChange={(event) => setLeadForm((current) => ({ ...current, phone: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label="Company"
                  fullWidth
                  value={leadForm.company}
                  onChange={(event) => setLeadForm((current) => ({ ...current, company: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label="City"
                  fullWidth
                  value={leadForm.city}
                  onChange={(event) => setLeadForm((current) => ({ ...current, city: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label="Interest"
                  fullWidth
                  value={leadForm.interest}
                  onChange={(event) => setLeadForm((current) => ({ ...current, interest: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label="Service"
                  fullWidth
                  value={leadForm.service}
                  onChange={(event) => setLeadForm((current) => ({ ...current, service: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Status</InputLabel>
                  <Select
                    label="Status"
                    value={leadForm.status}
                    onChange={(event) => setLeadForm((current) => ({ ...current, status: event.target.value }))}
                  >
                    {STATUS_OPTIONS.map((item) => (
                      <MenuItem key={item} value={item}>
                        {item}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label="Source"
                  fullWidth
                  value={leadForm.source}
                  onChange={(event) => setLeadForm((current) => ({ ...current, source: event.target.value }))}
                />
              </Grid>
              <Grid size={{ xs: 12 }}>
                <TextField
                  label="Tags (comma-separated)"
                  fullWidth
                  value={leadForm.tagsText}
                  onChange={(event) => setLeadForm((current) => ({ ...current, tagsText: event.target.value }))}
                />
              </Grid>
            </Grid>

            {!editingLead && (
              <Stack direction="row" spacing={1} alignItems="center">
                <Checkbox
                  checked={leadForm.auto_assign}
                  onChange={(event) => setLeadForm((current) => ({ ...current, auto_assign: event.target.checked }))}
                />
                <Typography variant="body2">Auto assign using active assignment rules</Typography>
              </Stack>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setLeadDialogOpen(false)}>Cancel</Button>
          <Button
            variant="contained"
            startIcon={<SaveIcon />}
            onClick={saveLead}
            disabled={leadSaving || (editingLead ? !canUpdate : !canCreate)}
          >
            {leadSaving ? 'Saving...' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={importDialogOpen} onClose={() => setImportDialogOpen(false)} maxWidth="md" fullWidth>
        <DialogTitle>Import Leads (CSV/XLSX)</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <Button variant="outlined" component="label">
              Choose File
              <input
                hidden
                type="file"
                accept=".csv,.xlsx,.xls"
                onChange={(event) => handleFileSelection(event.target.files?.[0])}
              />
            </Button>

            {importColumns.length > 0 && (
              <>
                <Typography variant="subtitle2">Column Mapping</Typography>
                <Grid container spacing={1.2}>
                  {importColumns.map((column) => (
                    <Grid key={column} size={{ xs: 12, sm: 6 }}>
                      <Stack direction="row" spacing={1} alignItems="center">
                        <Typography sx={{ minWidth: 120 }} variant="body2">
                          {column}
                        </Typography>
                        <FormControl size="small" fullWidth>
                          <Select
                            value={importMapping[column] ?? ''}
                            onChange={(event) =>
                              setImportMapping((prev) => ({
                                ...prev,
                                [column]: event.target.value,
                              }))
                            }
                          >
                            <MenuItem value="">Ignore</MenuItem>
                            {LEAD_IMPORT_FIELDS.map((field) => (
                              <MenuItem key={field} value={field}>
                                {field}
                              </MenuItem>
                            ))}
                          </Select>
                        </FormControl>
                      </Stack>
                    </Grid>
                  ))}
                </Grid>
                <Typography variant="caption" color="text.secondary">
                  Rows ready: {mapImportRows(importRows, importMapping).length} / {importRows.length}
                </Typography>
              </>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setImportDialogOpen(false)}>Cancel</Button>
          <Button
            variant="contained"
            startIcon={<SaveIcon />}
            onClick={runImport}
            disabled={importing || importColumns.length === 0 || !canCreate}
          >
            {importing ? 'Importing...' : 'Import'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default LeadsPanel
