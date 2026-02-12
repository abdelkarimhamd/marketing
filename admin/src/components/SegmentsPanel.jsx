import { useCallback, useEffect, useState } from 'react'
import {
  Button,
  Card,
  CardContent,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
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
import { Add as AddIcon } from '@mui/icons-material'
import { apiRequest } from '../lib/api'

function SegmentsPanel({ token, tenantId, refreshKey, onNotify }) {
  const [segments, setSegments] = useState([])
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState({
    name: '',
    description: '',
    rulesText: '',
    is_active: true,
  })

  const loadSegments = useCallback(async () => {
    try {
      const response = await apiRequest('/api/admin/segments?per_page=100', { token, tenantId })
      setSegments(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadSegments()
  }, [loadSegments, refreshKey])

  const openNew = () => {
    setEditing(null)
    setForm({ name: '', description: '', rulesText: '', is_active: true })
    setDialogOpen(true)
  }

  const openEdit = (segment) => {
    setEditing(segment)
    setForm({
      name: segment.name ?? '',
      description: segment.description ?? '',
      rulesText: JSON.stringify(segment.rules_json ?? {}, null, 2),
      is_active: Boolean(segment.is_active),
    })
    setDialogOpen(true)
  }

  const save = async () => {
    try {
      const payload = {
        name: form.name,
        description: form.description,
        is_active: form.is_active,
      }

      if (form.rulesText.trim() !== '') {
        payload.rules_json = JSON.parse(form.rulesText)
      }

      if (editing) {
        await apiRequest(`/api/admin/segments/${editing.id}`, {
          method: 'PATCH',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Segment updated.', 'success')
      } else {
        await apiRequest('/api/admin/segments', {
          method: 'POST',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Segment created.', 'success')
      }

      setDialogOpen(false)
      loadSegments()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const remove = async (segmentId) => {
    try {
      await apiRequest(`/api/admin/segments/${segmentId}`, { method: 'DELETE', token, tenantId })
      onNotify('Segment deleted.', 'success')
      loadSegments()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction="row" justifyContent="space-between">
        <Typography variant="h5">Segments</Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={openNew}>
          New Segment
        </Button>
      </Stack>

      <Card>
        <CardContent sx={{ p: 0 }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Name</TableCell>
                <TableCell>Slug</TableCell>
                <TableCell>Active</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {segments.map((segment) => (
                <TableRow key={segment.id}>
                  <TableCell>{segment.name}</TableCell>
                  <TableCell>{segment.slug}</TableCell>
                  <TableCell>{segment.is_active ? 'yes' : 'no'}</TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={1} justifyContent="flex-end">
                      <Button size="small" onClick={() => openEdit(segment)}>
                        Edit
                      </Button>
                      <Button size="small" color="error" onClick={() => remove(segment.id)}>
                        Delete
                      </Button>
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
              {segments.length === 0 && (
                <TableRow>
                  <TableCell colSpan={4}>
                    <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                      No segments yet.
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>{editing ? 'Edit Segment' : 'New Segment'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField label="Name" value={form.name} onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))} />
            <TextField
              label="Description"
              value={form.description}
              onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))}
            />
            <TextField
              label="rules_json (AND/OR schema)"
              value={form.rulesText}
              onChange={(event) => setForm((prev) => ({ ...prev, rulesText: event.target.value }))}
              multiline
              minRows={8}
            />
            <FormControl size="small">
              <InputLabel>Active</InputLabel>
              <Select
                label="Active"
                value={form.is_active ? 'yes' : 'no'}
                onChange={(event) => setForm((prev) => ({ ...prev, is_active: event.target.value === 'yes' }))}
              >
                <MenuItem value="yes">yes</MenuItem>
                <MenuItem value="no">no</MenuItem>
              </Select>
            </FormControl>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialogOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={save}>
            Save
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default SegmentsPanel
