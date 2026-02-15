import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  Grid,
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
import { Add as AddIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

function emptyForm() {
  return {
    name: '',
    domain: '',
    industry: '',
    city: '',
    country: '',
    owner_user_id: '',
  }
}

function AccountsPanel({ token, tenantId, refreshKey, onNotify, can = () => true }) {
  const [loading, setLoading] = useState(false)
  const [search, setSearch] = useState('')
  const [accounts, setAccounts] = useState([])
  const [selectedId, setSelectedId] = useState(null)
  const [details, setDetails] = useState(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState(emptyForm())
  const [attachLeadId, setAttachLeadId] = useState('')
  const canCreate = can('accounts.create')
  const canLink = can('accounts.link')

  const selectedAccount = useMemo(
    () => accounts.find((row) => String(row.id) === String(selectedId)) ?? null,
    [accounts, selectedId],
  )

  const loadAccounts = useCallback(async () => {
    if (!tenantId) return
    setLoading(true)
    try {
      const params = new URLSearchParams({ per_page: '100' })
      if (search.trim()) params.set('search', search.trim())
      const response = await apiRequest(`/api/admin/accounts?${params.toString()}`, { token, tenantId })
      setAccounts(response.data ?? [])
      if ((response.data ?? []).length > 0 && !selectedId) {
        setSelectedId(response.data[0].id)
      }
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }, [onNotify, search, selectedId, tenantId, token])

  const loadDetails = useCallback(async () => {
    if (!tenantId || !selectedId) {
      setDetails(null)
      return
    }

    try {
      const response = await apiRequest(`/api/admin/accounts/${selectedId}`, { token, tenantId })
      setDetails(response)
    } catch (error) {
      onNotify(error.message, 'error')
      setDetails(null)
    }
  }, [onNotify, selectedId, tenantId, token])

  useEffect(() => {
    loadAccounts()
  }, [loadAccounts, refreshKey])

  useEffect(() => {
    loadDetails()
  }, [loadDetails])

  const createAccount = async () => {
    if (!canCreate) {
      onNotify('You do not have permission to create accounts.', 'warning')
      return
    }

    setSaving(true)
    try {
      await apiRequest('/api/admin/accounts', {
        method: 'POST',
        token,
        tenantId,
        body: {
          ...form,
          owner_user_id: form.owner_user_id ? Number(form.owner_user_id) : null,
        },
      })
      onNotify('Account created successfully.', 'success')
      setDialogOpen(false)
      setForm(emptyForm())
      loadAccounts()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  const attachLead = async () => {
    if (!canLink || !selectedId || !attachLeadId.trim()) return

    try {
      await apiRequest(`/api/admin/accounts/${selectedId}/contacts/attach`, {
        method: 'POST',
        token,
        tenantId,
        body: {
          lead_id: Number(attachLeadId),
          is_primary: false,
        },
      })
      onNotify('Lead attached to account.', 'success')
      setAttachLeadId('')
      loadDetails()
      loadAccounts()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  if (!tenantId) {
    return <Alert severity="info">Select a tenant to manage accounts.</Alert>
  }

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.2} justifyContent="space-between">
        <TextField
          label="Search Accounts"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          onBlur={loadAccounts}
          sx={{ maxWidth: 420 }}
        />
        {canCreate && (
          <Button startIcon={<AddIcon />} variant="contained" onClick={() => setDialogOpen(true)}>
            New Account
          </Button>
        )}
      </Stack>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 5 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Name</TableCell>
                    <TableCell>Domain</TableCell>
                    <TableCell>Contacts</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {accounts.map((account) => (
                    <TableRow
                      key={account.id}
                      hover
                      selected={String(account.id) === String(selectedId)}
                      onClick={() => setSelectedId(account.id)}
                      sx={{ cursor: 'pointer' }}
                    >
                      <TableCell>{account.name}</TableCell>
                      <TableCell>{account.domain || '-'}</TableCell>
                      <TableCell>{account.contacts_count ?? 0}</TableCell>
                    </TableRow>
                  ))}
                  {accounts.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={3}>
                        <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                          {loading ? 'Loading accounts...' : 'No accounts found.'}
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 7 }}>
          <Card>
            <CardContent>
              {!selectedAccount && (
                <Typography color="text.secondary">Select an account to view details.</Typography>
              )}

              {selectedAccount && (
                <Stack spacing={1.4}>
                  <Typography variant="h6">{selectedAccount.name}</Typography>
                  <Typography variant="body2" color="text.secondary">
                    {selectedAccount.domain || '-'} • {selectedAccount.industry || '-'} • {selectedAccount.city || '-'}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    Updated: {formatDate(selectedAccount.updated_at)}
                  </Typography>

                  {canLink && (
                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                      <TextField
                        size="small"
                        label="Attach Lead ID"
                        value={attachLeadId}
                        onChange={(event) => setAttachLeadId(event.target.value)}
                      />
                      <Button variant="outlined" onClick={attachLead}>Attach</Button>
                    </Stack>
                  )}

                  <Divider />

                  <Typography variant="subtitle2">Contacts</Typography>
                  <Stack spacing={0.6}>
                    {(details?.account?.contacts ?? []).map((contact) => (
                      <Paper key={contact.id} variant="outlined" sx={{ p: 1 }}>
                        <Typography variant="body2">
                          {contact.first_name || ''} {contact.last_name || ''}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {contact.email || '-'} • {contact.phone || '-'}
                        </Typography>
                      </Paper>
                    ))}
                    {(details?.account?.contacts ?? []).length === 0 && (
                      <Typography color="text.secondary">No contacts attached yet.</Typography>
                    )}
                  </Stack>

                  <Divider />

                  <Typography variant="subtitle2">Timeline</Typography>
                  <Stack spacing={0.6} sx={{ maxHeight: 240, overflow: 'auto' }}>
                    {(details?.timeline ?? []).map((item) => (
                      <Paper key={item.id} variant="outlined" sx={{ p: 1 }}>
                        <Typography variant="body2">{item.type}</Typography>
                        <Typography variant="caption" color="text.secondary">
                          {item.description || '-'} • {formatDate(item.created_at)}
                        </Typography>
                      </Paper>
                    ))}
                    {(details?.timeline ?? []).length === 0 && (
                      <Typography color="text.secondary">No activity yet.</Typography>
                    )}
                  </Stack>
                </Stack>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Create Account</DialogTitle>
        <DialogContent>
          <Stack spacing={1.2} sx={{ mt: 1 }}>
            <TextField
              label="Name"
              required
              value={form.name}
              onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
            />
            <TextField
              label="Domain"
              value={form.domain}
              onChange={(event) => setForm((prev) => ({ ...prev, domain: event.target.value }))}
            />
            <TextField
              label="Industry"
              value={form.industry}
              onChange={(event) => setForm((prev) => ({ ...prev, industry: event.target.value }))}
            />
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 1 }}>
              <TextField
                label="City"
                value={form.city}
                onChange={(event) => setForm((prev) => ({ ...prev, city: event.target.value }))}
              />
              <TextField
                label="Country"
                value={form.country}
                onChange={(event) => setForm((prev) => ({ ...prev, country: event.target.value }))}
              />
            </Box>
            <TextField
              label="Owner User ID"
              value={form.owner_user_id}
              onChange={(event) => setForm((prev) => ({ ...prev, owner_user_id: event.target.value }))}
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialogOpen(false)}>Cancel</Button>
          <Button onClick={createAccount} disabled={saving} variant="contained">
            {saving ? 'Saving...' : 'Create'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default AccountsPanel
