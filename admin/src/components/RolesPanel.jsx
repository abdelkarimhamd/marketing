import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Autocomplete,
  Button,
  Card,
  CardContent,
  Checkbox,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControlLabel,
  Grid,
  MenuItem,
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
import { apiRequest } from '../lib/api'

function mergePermissionShape(shape, matrix) {
  if (!matrix || typeof matrix !== 'object') return

  Object.entries(matrix).forEach(([resource, actions]) => {
    if (!actions || typeof actions !== 'object') return
    if (!shape[resource]) shape[resource] = {}

    Object.keys(actions).forEach((action) => {
      shape[resource][action] = false
    })
  })
}

function buildBlankPermissions(shape) {
  return Object.entries(shape).reduce((acc, [resource, actions]) => {
    acc[resource] = Object.keys(actions).reduce((resourceActions, action) => {
      resourceActions[action] = false
      return resourceActions
    }, {})
    return acc
  }, {})
}

function normalizePermissions(shape, source) {
  const combinedShape = {}
  mergePermissionShape(combinedShape, shape)
  mergePermissionShape(combinedShape, source)

  const normalized = buildBlankPermissions(combinedShape)

  Object.entries(source ?? {}).forEach(([resource, actions]) => {
    if (!actions || typeof actions !== 'object') return
    Object.entries(actions).forEach(([action, allowed]) => {
      if (normalized[resource] && action in normalized[resource]) {
        normalized[resource][action] = Boolean(allowed)
      }
    })
  })

  return normalized
}

function emptyRoleForm(permissionShape = {}) {
  return {
    name: '',
    slug: '',
    description: '',
    permissions: buildBlankPermissions(permissionShape),
  }
}

function emptyUserForm(templateKey = 'sales') {
  return {
    name: '',
    email: '',
    password: '',
    template_key: templateKey,
  }
}

function RolesPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [roles, setRoles] = useState([])
  const [templates, setTemplates] = useState({})
  const [selectedRole, setSelectedRole] = useState(null)
  const [assignableUsers, setAssignableUsers] = useState([])
  const [assignableUsersLoading, setAssignableUsersLoading] = useState(false)
  const [assignUser, setAssignUser] = useState(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(emptyRoleForm())
  const [saving, setSaving] = useState(false)
  const [userDialogOpen, setUserDialogOpen] = useState(false)
  const [creatingUser, setCreatingUser] = useState(false)
  const [userForm, setUserForm] = useState(emptyUserForm())
  const canCreate = can('roles.create')
  const canUpdate = can('roles.update')
  const canDelete = can('roles.delete')
  const canAssign = can('roles.assign')

  const templateEntries = useMemo(() => Object.entries(templates ?? {}), [templates])
  const defaultTemplateKey = useMemo(
    () => templateEntries.find(([key]) => key === 'sales')?.[0] ?? templateEntries[0]?.[0] ?? 'sales',
    [templateEntries],
  )
  const permissionShape = useMemo(() => {
    const shape = {}
    templateEntries.forEach(([, template]) => {
      mergePermissionShape(shape, template.permissions)
    })
    roles.forEach((role) => {
      mergePermissionShape(shape, role.permissions)
    })
    return shape
  }, [roles, templateEntries])
  const permissionResources = useMemo(() => Object.entries(form.permissions ?? {}), [form.permissions])

  const load = useCallback(async () => {
    try {
      const templatesResponse = await apiRequest('/api/admin/roles/templates', { token, tenantId })
      setTemplates(templatesResponse.templates ?? {})

      if (!tenantId) {
        setRoles([])
        setSelectedRole(null)
        return
      }

      const rolesResponse = await apiRequest('/api/admin/roles', { token, tenantId })
      setRoles(rolesResponse.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    load()
  }, [load, refreshKey])

  const loadAssignableUsers = useCallback(async () => {
    if (!tenantId) {
      setAssignableUsers([])
      return
    }

    setAssignableUsersLoading(true)
    try {
      const response = await apiRequest('/api/admin/roles/assignable-users?limit=200', { token, tenantId })
      setAssignableUsers(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setAssignableUsersLoading(false)
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadAssignableUsers()
  }, [loadAssignableUsers, refreshKey])

  const openDetails = async (roleId) => {
    try {
      const response = await apiRequest(`/api/admin/roles/${roleId}`, { token, tenantId })
      setSelectedRole(response.role ?? null)
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const openNew = (templateKey = null) => {
    if (!canCreate) {
      onNotify('You do not have permission to create roles.', 'warning')
      return
    }

    const templatePermissions = templateKey && templates[templateKey]
      ? templates[templateKey].permissions
      : buildBlankPermissions(permissionShape)

    setEditing(null)
    setForm({
      name: templateKey ? `${templates[templateKey].name} Custom` : '',
      slug: '',
      description: templateKey ? templates[templateKey].description ?? '' : '',
      permissions: normalizePermissions(permissionShape, templatePermissions),
    })
    setDialogOpen(true)
  }

  const openEdit = (role) => {
    if (!canUpdate) {
      onNotify('You do not have permission to update roles.', 'warning')
      return
    }

    setEditing(role)
    setForm({
      name: role.name ?? '',
      slug: role.slug ?? '',
      description: role.description ?? '',
      permissions: normalizePermissions(permissionShape, role.permissions ?? {}),
    })
    setDialogOpen(true)
  }

  const saveRole = async () => {
    if (editing && !canUpdate) {
      onNotify('You do not have permission to update roles.', 'warning')
      return
    }

    if (!editing && !canCreate) {
      onNotify('You do not have permission to create roles.', 'warning')
      return
    }

    setSaving(true)
    try {
      const payload = {
        name: form.name.trim(),
        slug: form.slug.trim() || undefined,
        description: form.description.trim() || undefined,
        permissions: form.permissions,
      }

      if (editing) {
        await apiRequest(`/api/admin/roles/${editing.id}`, {
          method: 'PATCH',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Role updated.', 'success')
      } else {
        await apiRequest('/api/admin/roles', {
          method: 'POST',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Role created.', 'success')
      }

      setDialogOpen(false)
      setEditing(null)
      setForm(emptyRoleForm(permissionShape))
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSaving(false)
    }
  }

  const openNewUser = () => {
    if (!canAssign) {
      onNotify('You do not have permission to create users from roles module.', 'warning')
      return
    }

    if (!tenantId) {
      onNotify('Select tenant first.', 'warning')
      return
    }

    setUserForm(emptyUserForm(defaultTemplateKey))
    setUserDialogOpen(true)
  }

  const createUser = async () => {
    if (!canAssign) {
      onNotify('You do not have permission to create users from roles module.', 'warning')
      return
    }

    if (!tenantId) {
      onNotify('Select tenant first.', 'warning')
      return
    }

    if (userForm.name.trim() === '' || userForm.email.trim() === '' || userForm.password.trim() === '') {
      onNotify('Name, email, and password are required.', 'warning')
      return
    }

    setCreatingUser(true)
    try {
      await apiRequest('/api/admin/users', {
        method: 'POST',
        token,
        tenantId,
        body: {
          name: userForm.name.trim(),
          email: userForm.email.trim(),
          password: userForm.password,
          template_key: userForm.template_key || undefined,
        },
      })

      onNotify('User created successfully.', 'success')
      setUserDialogOpen(false)
      setUserForm(emptyUserForm(defaultTemplateKey))
      await loadAssignableUsers()
      await load()

      if (selectedRole?.id) {
        await openDetails(selectedRole.id)
      }
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setCreatingUser(false)
    }
  }

  const removeRole = async (role) => {
    if (!canDelete) {
      onNotify('You do not have permission to delete roles.', 'warning')
      return
    }

    if (role.is_system) {
      onNotify('System role templates cannot be deleted.', 'warning')
      return
    }

    if (!window.confirm(`Delete role "${role.name}"?`)) {
      return
    }

    try {
      await apiRequest(`/api/admin/roles/${role.id}`, {
        method: 'DELETE',
        token,
        tenantId,
      })
      onNotify('Role deleted.', 'success')
      if (selectedRole?.id === role.id) {
        setSelectedRole(null)
      }
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const assignRole = async () => {
    if (!canAssign) {
      onNotify('You do not have permission to assign roles.', 'warning')
      return
    }

    if (!selectedRole || !assignUser) {
      onNotify('Select a user first.', 'warning')
      return
    }

    try {
      await apiRequest(`/api/admin/roles/${selectedRole.id}/assign`, {
        method: 'POST',
        token,
        tenantId,
        body: { user_id: Number(assignUser.id) },
      })
      onNotify('Role assigned.', 'success')
      setAssignUser(null)
      openDetails(selectedRole.id)
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const unassignRole = async (userId) => {
    if (!canAssign) {
      onNotify('You do not have permission to unassign roles.', 'warning')
      return
    }

    if (!selectedRole) return

    try {
      await apiRequest(`/api/admin/roles/${selectedRole.id}/unassign`, {
        method: 'POST',
        token,
        tenantId,
        body: { user_id: userId },
      })
      onNotify('Role unassigned.', 'success')
      openDetails(selectedRole.id)
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const togglePermission = (resource, action, checked) => {
    setForm((current) => ({
      ...current,
      permissions: {
        ...current.permissions,
        [resource]: {
          ...(current.permissions?.[resource] ?? {}),
          [action]: checked,
        },
      },
    }))
  }

  const setAllPermissions = (enabled) => {
    setForm((current) => {
      const nextPermissions = Object.entries(current.permissions ?? {}).reduce((acc, [resource, actions]) => {
        acc[resource] = Object.keys(actions).reduce((resourceActions, action) => {
          resourceActions[action] = enabled
          return resourceActions
        }, {})
        return acc
      }, {})

      return { ...current, permissions: nextPermissions }
    })
  }

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} justifyContent="space-between">
        <Typography variant="h5">Roles + Permissions</Typography>
        <Stack direction="row" spacing={1}>
          {canAssign && (
            <Button variant="outlined" startIcon={<AddIcon />} onClick={openNewUser} disabled={!tenantId}>
              New User
            </Button>
          )}
          {canCreate && (
            <Button variant="contained" startIcon={<AddIcon />} onClick={() => openNew()} disabled={!tenantId}>
              New Role
            </Button>
          )}
        </Stack>
      </Stack>

      <Card variant="outlined">
        <CardContent>
          <Typography variant="subtitle2" sx={{ mb: 1 }}>
            Templates
          </Typography>
          <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', rowGap: 1 }}>
            {templateEntries.map(([key, template]) => (
              <Chip
                key={key}
                label={`Use ${template.name}`}
                onClick={() => openNew(key)}
                clickable
                disabled={!canCreate}
              />
            ))}
            {templateEntries.length === 0 && (
              <Typography color="text.secondary">No templates available.</Typography>
            )}
          </Stack>
        </CardContent>
      </Card>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 7 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Name</TableCell>
                    <TableCell>Slug</TableCell>
                    <TableCell>Type</TableCell>
                    <TableCell>Users</TableCell>
                    <TableCell align="right">Actions</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {roles.map((role) => (
                    <TableRow key={role.id} hover selected={selectedRole?.id === role.id}>
                      <TableCell>{role.name}</TableCell>
                      <TableCell>{role.slug}</TableCell>
                      <TableCell>{role.is_system ? 'system' : 'custom'}</TableCell>
                      <TableCell>{role.users_count ?? 0}</TableCell>
                      <TableCell align="right">
                        <Stack direction="row" spacing={1} justifyContent="flex-end">
                          <Button size="small" onClick={() => openDetails(role.id)}>
                            View
                          </Button>
                          {canUpdate && (
                            <Button size="small" onClick={() => openEdit(role)} disabled={Boolean(role.is_system)}>
                              Edit
                            </Button>
                          )}
                          {canDelete && (
                            <Button size="small" color="error" onClick={() => removeRole(role)} disabled={Boolean(role.is_system)}>
                              Delete
                            </Button>
                          )}
                        </Stack>
                      </TableCell>
                    </TableRow>
                  ))}
                  {roles.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={5}>
                        <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                          No roles found.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 5 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Role Detail</Typography>
              <Divider sx={{ my: 1.2 }} />

              {!selectedRole && (
                <Typography color="text.secondary">Select a role to inspect assigned users.</Typography>
              )}

              {selectedRole && (
                <Stack spacing={1.2}>
                  <Typography variant="body2"><strong>Name:</strong> {selectedRole.name}</Typography>
                  <Typography variant="body2"><strong>Slug:</strong> {selectedRole.slug}</Typography>
                  <Typography variant="body2"><strong>Type:</strong> {selectedRole.is_system ? 'system' : 'custom'}</Typography>

                  <Paper variant="outlined" sx={{ p: 1 }}>
                    <Typography variant="subtitle2" sx={{ mb: 1 }}>
                      Assign User
                    </Typography>
                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                      <Autocomplete
                        size="small"
                        sx={{ minWidth: 240 }}
                        options={assignableUsers}
                        loading={assignableUsersLoading}
                        value={assignUser}
                        onChange={(_, option) => setAssignUser(option)}
                        getOptionLabel={(option) => `${option.name} (${option.email})`}
                        isOptionEqualToValue={(option, value) => option.id === value.id}
                        renderInput={(params) => (
                          <TextField
                            {...params}
                            label="User"
                            placeholder="Search user"
                            InputProps={{
                              ...params.InputProps,
                              endAdornment: (
                                <>
                                  {assignableUsersLoading ? <CircularProgress color="inherit" size={16} /> : null}
                                  {params.InputProps.endAdornment}
                                </>
                              ),
                            }}
                          />
                        )}
                      />
                      <Button variant="contained" onClick={assignRole} disabled={!assignUser || !canAssign}>
                        Assign
                      </Button>
                    </Stack>
                  </Paper>

                  <Typography variant="subtitle2">Assigned Users</Typography>
                  {(selectedRole.users ?? []).length === 0 && (
                    <Typography color="text.secondary">No assigned users.</Typography>
                  )}
                  {(selectedRole.users ?? []).map((user) => (
                    <Paper key={user.id} variant="outlined" sx={{ p: 1 }}>
                      <Stack direction="row" justifyContent="space-between" alignItems="center">
                        <BoxedUser user={user} />
                        {canAssign && (
                          <Button size="small" color="warning" onClick={() => unassignRole(user.id)}>
                            Unassign
                          </Button>
                        )}
                      </Stack>
                    </Paper>
                  ))}
                </Stack>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="md" fullWidth>
        <DialogTitle>{editing ? `Edit Role: ${editing.name}` : 'Create Role'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              label="Role Name"
              value={form.name}
              onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
            />
            <TextField
              label="Slug (optional)"
              value={form.slug}
              onChange={(event) => setForm((current) => ({ ...current, slug: event.target.value }))}
            />
            <TextField
              label="Description"
              value={form.description}
              onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
            />
            <Stack direction="row" spacing={1}>
              <Button size="small" variant="outlined" onClick={() => setAllPermissions(true)}>
                Select all permissions
              </Button>
              <Button size="small" variant="outlined" onClick={() => setAllPermissions(false)}>
                Clear all permissions
              </Button>
            </Stack>
            <Stack spacing={1}>
              {permissionResources.map(([resource, actions]) => (
                <Paper key={resource} variant="outlined" sx={{ p: 1.2 }}>
                  <Typography variant="subtitle2" sx={{ textTransform: 'capitalize' }}>
                    {resource.replaceAll('_', ' ')}
                  </Typography>
                  <Stack direction="row" sx={{ flexWrap: 'wrap', rowGap: 0.5, mt: 0.6 }}>
                    {Object.entries(actions).map(([action, allowed]) => (
                      <FormControlLabel
                        key={`${resource}.${action}`}
                        control={(
                          <Checkbox
                            size="small"
                            checked={Boolean(allowed)}
                            onChange={(event) => togglePermission(resource, action, event.target.checked)}
                            disabled={editing ? !canUpdate : !canCreate}
                          />
                        )}
                        label={action}
                      />
                    ))}
                  </Stack>
                </Paper>
              ))}
            </Stack>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialogOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={saveRole} disabled={saving || (editing ? !canUpdate : !canCreate)}>
            {saving ? 'Saving...' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={userDialogOpen} onClose={() => setUserDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Create User</DialogTitle>
        <DialogContent>
          <Stack spacing={1.5} sx={{ mt: 1 }}>
            <TextField
              label="Full Name"
              value={userForm.name}
              onChange={(event) => setUserForm((current) => ({ ...current, name: event.target.value }))}
              required
            />
            <TextField
              label="Email"
              value={userForm.email}
              onChange={(event) => setUserForm((current) => ({ ...current, email: event.target.value }))}
              required
            />
            <TextField
              label="Password"
              type="password"
              value={userForm.password}
              onChange={(event) => setUserForm((current) => ({ ...current, password: event.target.value }))}
              helperText="Minimum 8 characters."
              required
            />
            <TextField
              select
              label="Access Template"
              value={userForm.template_key}
              onChange={(event) => setUserForm((current) => ({ ...current, template_key: event.target.value }))}
            >
              {templateEntries.map(([key, template]) => (
                <MenuItem key={key} value={key}>
                  {template.name}
                </MenuItem>
              ))}
            </TextField>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setUserDialogOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={createUser} disabled={creatingUser || !tenantId || !canAssign}>
            {creatingUser ? 'Creating...' : 'Create User'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

function BoxedUser({ user }) {
  return (
    <Stack>
      <Typography variant="body2">
        #{user.id} {user.name}
      </Typography>
      <Typography variant="caption" color="text.secondary">
        {user.email}
      </Typography>
    </Stack>
  )
}

export default RolesPanel
