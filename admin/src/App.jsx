import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Alert,
  AppBar,
  Avatar,
  Backdrop,
  Box,
  Button,
  Checkbox,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  Drawer,
  FormControl,
  FormControlLabel,
  IconButton,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  MenuItem,
  Select,
  Snackbar,
  Stack,
  TextField,
  Toolbar,
  Tooltip,
  Typography,
  useMediaQuery,
} from '@mui/material'
import {
  AccountTree as RulesIcon,
  AdminPanelSettings as RolesIcon,
  Add as AddIcon,
  Analytics as AnalyticsIcon,
  AutoFixHigh as PersonalizationIcon,
  Balance as BillingIcon,
  Business as AccountsIcon,
  Campaign as CampaignIcon,
  CleaningServices as DataQualityIcon,
  LocalPhone as TelephonyIcon,
  Psychology as CopilotIcon,
  GppGood as ComplianceIcon,
  Dashboard as DashboardIcon,
  Description as TemplateIcon,
  Group as LeadIcon,
  ManageSearch as SearchGlobalIcon,
  MenuBook as PlaybookIcon,
  RocketLaunch as ExperimentIcon,
  Insights as SegmentIcon,
  Storefront as MarketplaceIcon,
  MailOutline as InboxIcon,
  Logout as LogoutIcon,
  Menu as MenuIcon,
  Public as PortalRequestIcon,
  Refresh as RefreshIcon,
  Settings as SettingsIcon,
  SupportAgent as SupportIcon,
  TravelExplore as TrackingIcon,
  Webhook as WebhookIcon,
} from '@mui/icons-material'
import './App.css'
import { apiRequest } from './lib/api'
import LoginView from './components/LoginView'
import DashboardPanel from './components/DashboardPanel'
import SearchPanel from './components/SearchPanel'
import LeadsPanel from './components/LeadsPanel'
import SegmentsPanel from './components/SegmentsPanel'
import TemplatesPanel from './components/TemplatesPanel'
import CampaignsPanel from './components/CampaignsPanel'
import SettingsPanel from './components/SettingsPanel'
import WebhooksPanel from './components/WebhooksPanel'
import InboxPanel from './components/InboxPanel'
import RolesPanel from './components/RolesPanel'
import AssignmentRulesPanel from './components/AssignmentRulesPanel'
import BillingPanel from './components/BillingPanel'
import CompliancePanel from './components/CompliancePanel'
import PlaybooksPanel from './components/PlaybooksPanel'
import CustomerSuccessPanel from './components/CustomerSuccessPanel'
import WorkspaceAnalyticsPanel from './components/WorkspaceAnalyticsPanel'
import AccountsPanel from './components/AccountsPanel'
import TrackingPanel from './components/TrackingPanel'
import PersonalizationPanel from './components/PersonalizationPanel'
import DataQualityPanel from './components/DataQualityPanel'
import PortalRequestsPanel from './components/PortalRequestsPanel'
import ExperimentsPanel from './components/ExperimentsPanel'
import MarketplacePanel from './components/MarketplacePanel'
import TelephonyPanel from './components/TelephonyPanel'
import CopilotPanel from './components/CopilotPanel'

const DRAWER_WIDTH = 282
const NAV_SECTIONS = [
  {
    title: 'Operations',
    items: [
      { key: 'dashboard', label: 'Dashboard', icon: DashboardIcon, permission: 'dashboard.view' },
      { key: 'search', label: 'Search', icon: SearchGlobalIcon, permission: 'leads.view' },
      { key: 'leads', label: 'Leads', icon: LeadIcon, permission: 'leads.view' },
      { key: 'accounts', label: 'Accounts', icon: AccountsIcon, permission: 'accounts.view' },
      { key: 'tracking', label: 'Web Tracking', icon: TrackingIcon, permission: 'tracking.view' },
      { key: 'portalRequests', label: 'Portal Requests', icon: PortalRequestIcon, permission: 'portal_requests.view' },
      { key: 'inbox', label: 'Inbox', icon: InboxIcon, permissions: ['inbox.view', 'leads.view'] },
      { key: 'campaigns', label: 'Campaigns', icon: CampaignIcon, permission: 'campaigns.view' },
    ],
  },
  {
    title: 'Growth',
    items: [
      { key: 'assignmentRules', label: 'Assignment Rules', icon: RulesIcon, permission: 'assignment_rules.view' },
      { key: 'segments', label: 'Segments', icon: SegmentIcon, permission: 'segments.view' },
      { key: 'templates', label: 'Templates', icon: TemplateIcon, permission: 'templates.view' },
      { key: 'playbooks', label: 'Playbooks', icon: PlaybookIcon, permission: 'playbooks.view' },
      { key: 'personalization', label: 'Personalization', icon: PersonalizationIcon, permission: 'personalization.view' },
      { key: 'experiments', label: 'Experiments', icon: ExperimentIcon, permission: 'experiments.view' },
    ],
  },
  {
    title: 'Admin',
    items: [
      { key: 'roles', label: 'Roles', icon: RolesIcon, permission: 'roles.view' },
      { key: 'billing', label: 'Billing', icon: BillingIcon, permissions: ['billing.view', 'settings.view'] },
      { key: 'compliance', label: 'Compliance', icon: ComplianceIcon, permissions: ['compliance.view', 'settings.view'] },
      { key: 'telephony', label: 'Telephony', icon: TelephonyIcon, permission: 'telephony.view' },
      { key: 'dataQuality', label: 'Data Quality', icon: DataQualityIcon, permission: 'data_quality.view' },
      { key: 'marketplace', label: 'Marketplace', icon: MarketplaceIcon, permission: 'marketplace.view' },
      { key: 'copilot', label: 'Copilot', icon: CopilotIcon, permission: 'copilot.view' },
      {
        key: 'workspaceAnalytics',
        label: 'Workspace Analytics',
        icon: AnalyticsIcon,
        permissions: ['billing.view', 'settings.view'],
        superAdminOnly: true,
      },
      { key: 'customerSuccess', label: 'Success Console', icon: SupportIcon, permission: 'dashboard.view', superAdminOnly: true },
      { key: 'settings', label: 'Settings', icon: SettingsIcon, permission: 'settings.view' },
      { key: 'webhooks', label: 'Webhooks Inbox', icon: WebhookIcon, permission: 'webhooks.view' },
    ],
  },
]

function App() {
  const isDesktop = useMediaQuery((theme) => theme.breakpoints.up('lg'))
  const [token, setToken] = useState(localStorage.getItem('marketion_token') || '')
  const [user, setUser] = useState(() => {
    const raw = localStorage.getItem('marketion_user')
    return raw ? JSON.parse(raw) : null
  })
  const [permissionMatrix, setPermissionMatrix] = useState(() => {
    const raw = localStorage.getItem('marketion_permission_matrix')

    if (!raw) return {}

    try {
      const parsed = JSON.parse(raw)
      return parsed && typeof parsed === 'object' ? parsed : {}
    } catch {
      return {}
    }
  })
  const [tenantId, setTenantId] = useState(localStorage.getItem('marketion_tenant_id') || '')
  const [tenants, setTenants] = useState([])
  const [moduleKey, setModuleKey] = useState(localStorage.getItem('marketion_module') || 'dashboard')
  const [mobileOpen, setMobileOpen] = useState(false)
  const [loading, setLoading] = useState(Boolean(token))
  const [refreshKey, setRefreshKey] = useState(0)
  const [toast, setToast] = useState({ open: false, message: '', severity: 'info' })
  const [loggingIn, setLoggingIn] = useState(false)
  const [navQuery, setNavQuery] = useState('')
  const [createTenantOpen, setCreateTenantOpen] = useState(false)
  const [creatingTenant, setCreatingTenant] = useState(false)
  const [newTenant, setNewTenant] = useState({
    name: '',
    slug: '',
    domain: '',
    timezone: 'UTC',
    locale: 'en',
    currency: 'USD',
    is_active: true,
  })

  const notify = useCallback((message, severity = 'info') => {
    setToast({ open: true, message, severity })
  }, [])

  const loadTenants = useCallback(async () => {
    if (!token) return
    try {
      const response = await apiRequest('/api/admin/tenants', { token, tenantId: tenantId || undefined })
      const rows = response.data ?? []
      setTenants(rows)

      if (!tenantId && rows.length === 1 && user?.role !== 'super_admin') {
        const onlyTenantId = String(rows[0].id)
        setTenantId(onlyTenantId)
        localStorage.setItem('marketion_tenant_id', onlyTenantId)
      }
    } catch (error) {
      notify(error.message, 'error')
    }
  }, [notify, tenantId, token, user?.role])

  useEffect(() => {
    let active = true

    const boot = async () => {
      if (!token) {
        setLoading(false)
        return
      }

      try {
        const response = await apiRequest('/api/auth/me', {
          token,
          tenantId: tenantId || undefined,
        })

        if (!active) return
        setUser(response.user)
        setPermissionMatrix(response.permission_matrix ?? {})
        localStorage.setItem('marketion_user', JSON.stringify(response.user))
        localStorage.setItem('marketion_permission_matrix', JSON.stringify(response.permission_matrix ?? {}))
        await loadTenants()
      } catch (error) {
        if (!active) return
        notify(`Session expired: ${error.message}`, 'warning')
        setToken('')
        setUser(null)
        setPermissionMatrix({})
        setTenantId('')
        localStorage.removeItem('marketion_token')
        localStorage.removeItem('marketion_user')
        localStorage.removeItem('marketion_permission_matrix')
        localStorage.removeItem('marketion_tenant_id')
      } finally {
        if (active) setLoading(false)
      }
    }

    boot()
    return () => {
      active = false
    }
  }, [loadTenants, notify, tenantId, token])

  useEffect(() => {
    localStorage.setItem('marketion_module', moduleKey)
  }, [moduleKey])

  const onLogin = async (credentials) => {
    setLoggingIn(true)
    try {
      const response = await apiRequest('/api/auth/login', {
        method: 'POST',
        body: credentials,
      })
      setToken(response.token)
      setUser(response.user)
      setPermissionMatrix(response.permission_matrix ?? {})
      localStorage.setItem('marketion_token', response.token)
      localStorage.setItem('marketion_user', JSON.stringify(response.user))
      localStorage.setItem('marketion_permission_matrix', JSON.stringify(response.permission_matrix ?? {}))

      const userTenantId = response.user?.tenant_id ? String(response.user.tenant_id) : ''
      setTenantId(userTenantId)
      if (userTenantId) {
        localStorage.setItem('marketion_tenant_id', userTenantId)
      } else {
        localStorage.removeItem('marketion_tenant_id')
      }

      notify('Login successful.', 'success')
    } catch (error) {
      notify(error.message, 'error')
    } finally {
      setLoggingIn(false)
    }
  }

  const logout = async () => {
    try {
      if (token) {
        await apiRequest('/api/auth/logout', {
          method: 'POST',
          token,
          tenantId: tenantId || undefined,
        })
      }
    } catch {
      // ignore logout errors
    } finally {
      setToken('')
      setUser(null)
      setPermissionMatrix({})
      setTenants([])
      setTenantId('')
      localStorage.removeItem('marketion_token')
      localStorage.removeItem('marketion_user')
      localStorage.removeItem('marketion_permission_matrix')
      localStorage.removeItem('marketion_tenant_id')
      notify('Logged out.', 'success')
    }
  }

  const changeTenant = (value) => {
    setTenantId(value)
    if (value) {
      localStorage.setItem('marketion_tenant_id', value)
    } else {
      localStorage.removeItem('marketion_tenant_id')
    }
    setRefreshKey((prev) => prev + 1)
    notify(value ? `Tenant switched to #${value}` : 'Tenant bypass mode enabled.', 'info')
  }

  const resetNewTenantForm = () => {
    setNewTenant({
      name: '',
      slug: '',
      domain: '',
      timezone: 'UTC',
      locale: 'en',
      currency: 'USD',
      is_active: true,
    })
  }

  const createTenant = async () => {
    if (!token || user?.role !== 'super_admin') {
      return
    }

    if (newTenant.name.trim() === '') {
      notify('Tenant name is required.', 'warning')
      return
    }

    setCreatingTenant(true)
    try {
      const response = await apiRequest('/api/admin/tenants', {
        method: 'POST',
        token,
        body: {
          name: newTenant.name.trim(),
          slug: newTenant.slug.trim() || undefined,
          domain: newTenant.domain.trim() || null,
          timezone: newTenant.timezone.trim() || 'UTC',
          locale: newTenant.locale.trim() || 'en',
          currency: newTenant.currency.trim() || 'USD',
          is_active: Boolean(newTenant.is_active),
        },
      })

      const createdId = response.tenant?.id ? String(response.tenant.id) : ''
      notify('Tenant created successfully.', 'success')
      setCreateTenantOpen(false)
      resetNewTenantForm()
      await loadTenants()
      if (createdId) {
        changeTenant(createdId)
      }
    } catch (error) {
      notify(error.message, 'error')
    } finally {
      setCreatingTenant(false)
    }
  }

  const hasPermission = useCallback(
    (permission) => {
      if (!permission) return true

      if (user?.role === 'super_admin' || user?.role === 'tenant_admin') {
        return true
      }

      const [resource, action] = String(permission).split('.')

      if (!resource || !action) return false

      return Boolean(permissionMatrix?.[resource]?.[action])
    },
    [permissionMatrix, user?.role],
  )
  const hasAnyPermission = useCallback(
    (permissions) => {
      if (!Array.isArray(permissions) || permissions.length === 0) return true
      return permissions.some((permission) => hasPermission(permission))
    },
    [hasPermission],
  )

  const permittedNavSections = useMemo(
    () => NAV_SECTIONS
      .map((section) => ({
        ...section,
        items: section.items.filter((item) => {
          if (item.superAdminOnly && user?.role !== 'super_admin') {
            return false
          }

          if (Array.isArray(item.permissions) && item.permissions.length > 0) {
            return hasAnyPermission(item.permissions)
          }

          return hasPermission(item.permission)
        }),
      }))
      .filter((section) => section.items.length > 0),
    [hasAnyPermission, hasPermission, user?.role],
  )

  const filteredNavSections = useMemo(() => {
    const query = navQuery.trim().toLowerCase()

    return permittedNavSections
      .map((section) => ({
        ...section,
        items: section.items.filter((item) => item.label.toLowerCase().includes(query)),
      }))
      .filter((section) => section.items.length > 0)
  }, [navQuery, permittedNavSections])

  const allowedModuleKeys = useMemo(
    () => permittedNavSections.flatMap((section) => section.items.map((item) => item.key)),
    [permittedNavSections],
  )

  useEffect(() => {
    if (allowedModuleKeys.length === 0) return
    if (!allowedModuleKeys.includes(moduleKey)) {
      setModuleKey(allowedModuleKeys[0])
    }
  }, [allowedModuleKeys, moduleKey])

  const activeTenant = useMemo(
    () => tenants.find((tenant) => String(tenant.id) === String(tenantId)) ?? null,
    [tenantId, tenants],
  )
  const isBypassMode = user?.role === 'super_admin' && tenantId === ''
  const activeModuleKey = allowedModuleKeys.includes(moduleKey)
    ? moduleKey
    : (allowedModuleKeys[0] ?? null)

  const activePanel = useMemo(() => {
    if (!activeModuleKey) {
      return (
        <Alert severity="warning">
          No modules are available for your role in this tenant.
        </Alert>
      )
    }

    const shared = {
      token,
      tenantId: tenantId || undefined,
      refreshKey,
      onNotify: notify,
      can: hasPermission,
    }

    if (activeModuleKey === 'dashboard') return <DashboardPanel {...shared} />
    if (activeModuleKey === 'search') return <SearchPanel {...shared} />
    if (activeModuleKey === 'leads') return <LeadsPanel {...shared} />
    if (activeModuleKey === 'accounts') return <AccountsPanel {...shared} />
    if (activeModuleKey === 'tracking') return <TrackingPanel {...shared} />
    if (activeModuleKey === 'portalRequests') return <PortalRequestsPanel {...shared} />
    if (activeModuleKey === 'assignmentRules') return <AssignmentRulesPanel {...shared} />
    if (activeModuleKey === 'segments') return <SegmentsPanel {...shared} />
    if (activeModuleKey === 'templates') return <TemplatesPanel {...shared} />
    if (activeModuleKey === 'playbooks') return <PlaybooksPanel {...shared} />
    if (activeModuleKey === 'personalization') return <PersonalizationPanel {...shared} />
    if (activeModuleKey === 'experiments') return <ExperimentsPanel {...shared} />
    if (activeModuleKey === 'campaigns') return <CampaignsPanel {...shared} />
    if (activeModuleKey === 'inbox') return <InboxPanel {...shared} />
    if (activeModuleKey === 'roles') return <RolesPanel {...shared} />
    if (activeModuleKey === 'billing') return <BillingPanel {...shared} />
    if (activeModuleKey === 'compliance') return <CompliancePanel {...shared} />
    if (activeModuleKey === 'telephony') return <TelephonyPanel {...shared} />
    if (activeModuleKey === 'dataQuality') return <DataQualityPanel {...shared} />
    if (activeModuleKey === 'marketplace') return <MarketplacePanel {...shared} />
    if (activeModuleKey === 'copilot') return <CopilotPanel {...shared} />
    if (activeModuleKey === 'workspaceAnalytics') return <WorkspaceAnalyticsPanel {...shared} />
    if (activeModuleKey === 'customerSuccess') return <CustomerSuccessPanel {...shared} />
    if (activeModuleKey === 'settings') return <SettingsPanel {...shared} />
    return <WebhooksPanel {...shared} />
  }, [activeModuleKey, hasPermission, notify, refreshKey, tenantId, token])

  if (!token) {
    return (
      <>
        <LoginView onLogin={onLogin} loading={loggingIn} />
        <Snackbar
          open={toast.open}
          autoHideDuration={4500}
          onClose={() => setToast((prev) => ({ ...prev, open: false }))}
        >
          <Alert severity={toast.severity} variant="filled">
            {toast.message}
          </Alert>
        </Snackbar>
      </>
    )
  }

  const drawerContent = (
    <Box sx={{ p: 1.6 }}>
      <Stack spacing={1.2}>
        <Stack direction="row" spacing={1.2} alignItems="center" sx={{ px: 1, py: 1 }}>
          <Avatar sx={{ bgcolor: 'secondary.main' }}>{user?.name?.[0] ?? 'A'}</Avatar>
          <Box>
            <Typography variant="subtitle1">{user?.name ?? 'Admin'}</Typography>
            <Typography variant="caption" color="text.secondary">
              {user?.role ?? '-'}
            </Typography>
          </Box>
        </Stack>
        <Divider />
        <TextField
          size="small"
          placeholder="Find module..."
          value={navQuery}
          onChange={(event) => setNavQuery(event.target.value)}
          sx={{ px: 1 }}
        />
        {filteredNavSections.map((section) => (
          <Box key={section.title}>
            <Typography variant="caption" color="text.secondary" sx={{ px: 1.5 }}>
              {section.title}
            </Typography>
            <List dense sx={{ pt: 0.4 }}>
              {section.items.map((item) => (
                <ListItemButton
                  key={item.key}
                  selected={activeModuleKey === item.key}
                  onClick={() => {
                    setModuleKey(item.key)
                    setMobileOpen(false)
                  }}
                >
                  <ListItemIcon>
                    <item.icon />
                  </ListItemIcon>
                  <ListItemText primary={item.label} />
                </ListItemButton>
              ))}
            </List>
          </Box>
        ))}
        {filteredNavSections.length === 0 && (
          <Typography variant="body2" color="text.secondary" sx={{ px: 1.2 }}>
            No modules match your search.
          </Typography>
        )}
      </Stack>
    </Box>
  )

  return (
    <Box className="app-shell" sx={{ display: 'flex' }}>
      <AppBar
        position="fixed"
        sx={{
          zIndex: (theme) => theme.zIndex.drawer + 1,
          background: 'linear-gradient(120deg, #146c94 0%, #0c4f6c 52%, #11636f 100%)',
        }}
      >
        <Toolbar sx={{ display: 'flex', gap: 1.2 }}>
          {!isDesktop && (
            <IconButton color="inherit" onClick={() => setMobileOpen(true)}>
              <MenuIcon />
            </IconButton>
          )}
          <Typography sx={{ flex: 1, fontWeight: 700 }}>Marketion Admin</Typography>
          <Chip
            size="small"
            label={tenantId ? `Tenant: ${activeTenant?.name ?? `#${tenantId}`}` : 'Tenant: Bypass (All)'}
            color={isBypassMode ? 'warning' : 'default'}
            variant={isBypassMode ? 'filled' : 'outlined'}
            sx={{
              display: { xs: 'none', md: 'inline-flex' },
              color: isBypassMode ? undefined : '#fff',
              borderColor: isBypassMode ? undefined : 'rgba(255,255,255,0.6)',
              backgroundColor: isBypassMode ? undefined : 'rgba(255,255,255,0.1)',
            }}
          />

          <Tooltip title="Refresh panel">
            <IconButton color="inherit" onClick={() => setRefreshKey((prev) => prev + 1)}>
              <RefreshIcon />
            </IconButton>
          </Tooltip>

          <FormControl
            size="small"
            sx={{
              minWidth: 220,
              backgroundColor: 'rgba(255,255,255,0.12)',
              borderRadius: 1,
            }}
          >
            <Select
              value={tenantId}
              displayEmpty
              onOpen={() => loadTenants()}
              onChange={(event) => changeTenant(event.target.value)}
              sx={{ color: '#fff', '& fieldset': { borderColor: 'rgba(255,255,255,0.35)' } }}
            >
              {user?.role === 'super_admin' && <MenuItem value="">All Tenants (Bypass)</MenuItem>}
              {tenants.map((tenant) => (
                <MenuItem key={tenant.id} value={String(tenant.id)}>
                  {tenant.name} ({tenant.slug})
                </MenuItem>
              ))}
            </Select>
          </FormControl>

          {user?.role === 'super_admin' && (
            <>
              <Tooltip title="Create tenant">
                <IconButton
                  color="inherit"
                  onClick={() => setCreateTenantOpen(true)}
                  sx={{ display: { xs: 'inline-flex', md: 'none' } }}
                >
                  <AddIcon />
                </IconButton>
              </Tooltip>
              <Button
                color="inherit"
                variant="outlined"
                startIcon={<AddIcon />}
                onClick={() => setCreateTenantOpen(true)}
                sx={{
                  borderColor: 'rgba(255,255,255,0.5)',
                  display: { xs: 'none', md: 'inline-flex' },
                }}
              >
                New Tenant
              </Button>
            </>
          )}

          <Button color="inherit" startIcon={<LogoutIcon />} onClick={logout}>
            Logout
          </Button>
        </Toolbar>
      </AppBar>

      {isDesktop ? (
        <Drawer
          variant="permanent"
          sx={{
            width: DRAWER_WIDTH,
            flexShrink: 0,
            '& .MuiDrawer-paper': { width: DRAWER_WIDTH, boxSizing: 'border-box', borderRight: '1px solid #d7e7f4' },
          }}
        >
          <Toolbar />
          {drawerContent}
        </Drawer>
      ) : (
        <Drawer
          variant="temporary"
          open={mobileOpen}
          onClose={() => setMobileOpen(false)}
          sx={{ '& .MuiDrawer-paper': { width: DRAWER_WIDTH } }}
        >
          {drawerContent}
        </Drawer>
      )}

      <Box component="main" sx={{ flexGrow: 1, p: { xs: 1.5, md: 2.4 }, width: '100%' }}>
        <Toolbar />
        {activePanel}
      </Box>

      <Dialog open={createTenantOpen} onClose={() => setCreateTenantOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Create Tenant</DialogTitle>
        <DialogContent>
          <Stack spacing={1.5} sx={{ mt: 1 }}>
            <TextField
              label="Tenant Name"
              value={newTenant.name}
              onChange={(event) => setNewTenant((prev) => ({ ...prev, name: event.target.value }))}
              required
            />
            <TextField
              label="Slug (optional)"
              helperText="If empty, it is generated from name."
              value={newTenant.slug}
              onChange={(event) => setNewTenant((prev) => ({ ...prev, slug: event.target.value }))}
            />
            <TextField
              label="Primary Domain (optional)"
              value={newTenant.domain}
              onChange={(event) => setNewTenant((prev) => ({ ...prev, domain: event.target.value }))}
              placeholder="brand.example.com"
            />
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
              <TextField
                size="small"
                label="Timezone"
                value={newTenant.timezone}
                onChange={(event) => setNewTenant((prev) => ({ ...prev, timezone: event.target.value }))}
                fullWidth
              />
              <TextField
                size="small"
                label="Locale"
                value={newTenant.locale}
                onChange={(event) => setNewTenant((prev) => ({ ...prev, locale: event.target.value }))}
                fullWidth
              />
              <TextField
                size="small"
                label="Currency"
                value={newTenant.currency}
                onChange={(event) => setNewTenant((prev) => ({ ...prev, currency: event.target.value }))}
                fullWidth
              />
            </Stack>
            <FormControlLabel
              control={(
                <Checkbox
                  checked={Boolean(newTenant.is_active)}
                  onChange={(event) => setNewTenant((prev) => ({ ...prev, is_active: event.target.checked }))}
                />
              )}
              label="Tenant is active"
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCreateTenantOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={createTenant} disabled={creatingTenant}>
            {creatingTenant ? 'Creating...' : 'Create Tenant'}
          </Button>
        </DialogActions>
      </Dialog>

      <Backdrop open={loading} sx={{ color: '#fff', zIndex: (theme) => theme.zIndex.modal + 10 }}>
        <CircularProgress color="inherit" />
      </Backdrop>

      <Snackbar
        open={toast.open}
        autoHideDuration={4500}
        onClose={() => setToast((prev) => ({ ...prev, open: false }))}
      >
        <Alert severity={toast.severity} variant="filled">
          {toast.message}
        </Alert>
      </Snackbar>
    </Box>
  )
}

export default App
