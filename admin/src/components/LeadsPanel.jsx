import { useCallback, useEffect, useState } from 'react'
import {
  Box,
  Button,
  Card,
  CardContent,
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
import { Add as AddIcon, Save as SaveIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'
import { LEAD_IMPORT_FIELDS, mapImportRows, parseImportFile } from '../lib/importParser'

function LeadsPanel({ token, tenantId, refreshKey, onNotify }) {
  const [loading, setLoading] = useState(false)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [leadsPage, setLeadsPage] = useState({ data: [], total: 0, current_page: 1, last_page: 1 })
  const [selectedLead, setSelectedLead] = useState(null)
  const [leadActivities, setLeadActivities] = useState([])
  const [importDialogOpen, setImportDialogOpen] = useState(false)
  const [importRows, setImportRows] = useState([])
  const [importColumns, setImportColumns] = useState([])
  const [importMapping, setImportMapping] = useState({})
  const [importing, setImporting] = useState(false)

  const loadLeads = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams({
        page: String(page),
        per_page: '15',
      })

      if (search.trim() !== '') params.set('search', search.trim())
      if (status !== '') params.set('status', status)

      const response = await apiRequest(`/api/admin/leads?${params.toString()}`, { token, tenantId })
      setLeadsPage(response)
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }, [onNotify, page, search, status, tenantId, token])

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

  useEffect(() => {
    loadLeads()
  }, [loadLeads, refreshKey])

  const openLead = (lead) => {
    setSelectedLead(lead)
    loadLeadActivities(lead.id)
  }

  const closeLead = () => {
    setSelectedLead(null)
    setLeadActivities([])
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
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2} justifyContent="space-between">
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2}>
          <TextField
            label="Search leads"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            size="small"
            sx={{ minWidth: 220 }}
          />
          <FormControl size="small" sx={{ minWidth: 140 }}>
            <InputLabel>Status</InputLabel>
            <Select label="Status" value={status} onChange={(event) => setStatus(event.target.value)}>
              <MenuItem value="">All</MenuItem>
              <MenuItem value="new">new</MenuItem>
              <MenuItem value="contacted">contacted</MenuItem>
              <MenuItem value="won">won</MenuItem>
              <MenuItem value="lost">lost</MenuItem>
            </Select>
          </FormControl>
          <Button variant="outlined" onClick={() => { setPage(1); loadLeads() }}>
            Apply
          </Button>
        </Stack>
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setImportDialogOpen(true)}>
          Import CSV/XLSX
        </Button>
      </Stack>

      <Card>
        <CardContent sx={{ p: 0 }}>
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Name</TableCell>
                  <TableCell>Email</TableCell>
                  <TableCell>Phone</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Source</TableCell>
                  <TableCell align="right">Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {(leadsPage.data ?? []).map((lead) => (
                  <TableRow key={lead.id} hover>
                    <TableCell>{`${lead.first_name ?? ''} ${lead.last_name ?? ''}`.trim() || '-'}</TableCell>
                    <TableCell>{lead.email ?? '-'}</TableCell>
                    <TableCell>{lead.phone ?? '-'}</TableCell>
                    <TableCell>{lead.status ?? '-'}</TableCell>
                    <TableCell>{lead.source ?? '-'}</TableCell>
                    <TableCell align="right">
                      <Button size="small" onClick={() => openLead(lead)}>
                        View
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
                {(leadsPage.data ?? []).length === 0 && (
                  <TableRow>
                    <TableCell colSpan={6}>
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
            </Stack>
          )}
        </Box>
      </Drawer>

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
            disabled={importing || importColumns.length === 0}
          >
            {importing ? 'Importing...' : 'Import'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default LeadsPanel
