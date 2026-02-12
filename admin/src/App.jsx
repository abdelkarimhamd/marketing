import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Alert,
  AppBar,
  Avatar,
  Backdrop,
  Box,
  Button,
  CircularProgress,
  Divider,
  Drawer,
  FormControl,
  IconButton,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  MenuItem,
  Select,
  Snackbar,
  Stack,
  Toolbar,
  Tooltip,
  Typography,
  useMediaQuery,
} from '@mui/material'
import {
  Campaign as CampaignIcon,
  Dashboard as DashboardIcon,
  Description as TemplateIcon,
  Group as LeadIcon,
  Insights as SegmentIcon,
  Logout as LogoutIcon,
  Menu as MenuIcon,
  Refresh as RefreshIcon,
  Settings as SettingsIcon,
  Webhook as WebhookIcon,
} from '@mui/icons-material'
import './App.css'
import { apiRequest } from './lib/api'
import LoginView from './components/LoginView'
import DashboardPanel from './components/DashboardPanel'
import LeadsPanel from './components/LeadsPanel'
import SegmentsPanel from './components/SegmentsPanel'
import TemplatesPanel from './components/TemplatesPanel'
import CampaignsPanel from './components/CampaignsPanel'
import SettingsPanel from './components/SettingsPanel'
import WebhooksPanel from './components/WebhooksPanel'

const DRAWER_WIDTH = 282

function App() {
  const isDesktop = useMediaQuery((theme) => theme.breakpoints.up('lg'))
  const [token, setToken] = useState(localStorage.getItem('marketion_token') || '')
  const [user, setUser] = useState(() => {
    const raw = localStorage.getItem('marketion_user')
    return raw ? JSON.parse(raw) : null
  })
  const [tenantId, setTenantId] = useState(localStorage.getItem('marketion_tenant_id') || '')
  const [tenants, setTenants] = useState([])
  const [moduleKey, setModuleKey] = useState(localStorage.getItem('marketion_module') || 'dashboard')
  const [mobileOpen, setMobileOpen] = useState(false)
  const [loading, setLoading] = useState(Boolean(token))
  const [refreshKey, setRefreshKey] = useState(0)
  const [toast, setToast] = useState({ open: false, message: '', severity: 'info' })
  const [loggingIn, setLoggingIn] = useState(false)

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
        localStorage.setItem('marketion_user', JSON.stringify(response.user))
        await loadTenants()
      } catch (error) {
        if (!active) return
        notify(`Session expired: ${error.message}`, 'warning')
        setToken('')
        setUser(null)
        setTenantId('')
        localStorage.removeItem('marketion_token')
        localStorage.removeItem('marketion_user')
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
      localStorage.setItem('marketion_token', response.token)
      localStorage.setItem('marketion_user', JSON.stringify(response.user))

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
      setTenants([])
      setTenantId('')
      localStorage.removeItem('marketion_token')
      localStorage.removeItem('marketion_user')
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

  const navItems = [
    { key: 'dashboard', label: 'Dashboard', icon: <DashboardIcon /> },
    { key: 'leads', label: 'Leads', icon: <LeadIcon /> },
    { key: 'segments', label: 'Segments', icon: <SegmentIcon /> },
    { key: 'templates', label: 'Templates', icon: <TemplateIcon /> },
    { key: 'campaigns', label: 'Campaigns', icon: <CampaignIcon /> },
    { key: 'settings', label: 'Settings', icon: <SettingsIcon /> },
    { key: 'webhooks', label: 'Webhooks Inbox', icon: <WebhookIcon /> },
  ]

  const activePanel = useMemo(() => {
    const shared = { token, tenantId: tenantId || undefined, refreshKey, onNotify: notify }

    if (moduleKey === 'dashboard') return <DashboardPanel {...shared} />
    if (moduleKey === 'leads') return <LeadsPanel {...shared} />
    if (moduleKey === 'segments') return <SegmentsPanel {...shared} />
    if (moduleKey === 'templates') return <TemplatesPanel {...shared} />
    if (moduleKey === 'campaigns') return <CampaignsPanel {...shared} />
    if (moduleKey === 'settings') return <SettingsPanel {...shared} />
    return <WebhooksPanel {...shared} />
  }, [moduleKey, notify, refreshKey, tenantId, token])

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
        <List dense>
          {navItems.map((item) => (
            <ListItemButton
              key={item.key}
              selected={moduleKey === item.key}
              onClick={() => {
                setModuleKey(item.key)
                setMobileOpen(false)
              }}
            >
              <ListItemIcon>{item.icon}</ListItemIcon>
              <ListItemText primary={item.label} />
            </ListItemButton>
          ))}
        </List>
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
