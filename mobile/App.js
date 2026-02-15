import React, { useMemo, useState } from 'react'
import { SafeAreaView, StyleSheet, Text, TouchableOpacity, View } from 'react-native'
import { request } from './src/api'
import AuthScreen from './src/screens/AuthScreen'
import LeadsScreen from './src/screens/LeadsScreen'
import DealsScreen from './src/screens/DealsScreen'
import InboxScreen from './src/screens/InboxScreen'
import NotificationsScreen from './src/screens/NotificationsScreen'

const TABS = [
  { key: 'leads', label: 'Leads' },
  { key: 'deals', label: 'Deals' },
  { key: 'inbox', label: 'Inbox' },
  { key: 'push', label: 'Push' },
]

export default function App() {
  const [auth, setAuth] = useState({ token: '', user: null, tenantId: '' })
  const [tab, setTab] = useState('leads')
  const [loading, setLoading] = useState(false)

  const onLogin = async ({ email, password }) => {
    setLoading(true)
    try {
      const response = await request('/api/auth/login', {
        method: 'POST',
        body: { email, password },
      })
      setAuth({
        token: response.token,
        user: response.user,
        tenantId: response.user?.tenant_id ? String(response.user.tenant_id) : '',
      })
    } catch (error) {
      alert(error.message || 'Login failed')
    } finally {
      setLoading(false)
    }
  }

  const onLogout = async () => {
    if (!auth.token) return
    try {
      await request('/api/auth/logout', {
        method: 'POST',
        token: auth.token,
        tenantId: auth.tenantId,
      })
    } catch {
      // Ignore logout failures on local app reset.
    }
    setAuth({ token: '', user: null, tenantId: '' })
    setTab('leads')
  }

  const screen = useMemo(() => {
    const shared = { token: auth.token, tenantId: auth.tenantId }

    if (tab === 'deals') return <DealsScreen {...shared} />
    if (tab === 'inbox') return <InboxScreen {...shared} />
    if (tab === 'push') return <NotificationsScreen {...shared} />
    return <LeadsScreen {...shared} />
  }, [auth.tenantId, auth.token, tab])

  if (!auth.token) {
    return <AuthScreen onLogin={onLogin} loading={loading} />
  }

  return (
    <SafeAreaView style={styles.container}>
      <View style={styles.header}>
        <View>
          <Text style={styles.title}>Marketion Mobile</Text>
          <Text style={styles.subtitle}>{auth.user?.name || '-'} • Tenant {auth.tenantId || '-'}</Text>
        </View>
        <TouchableOpacity onPress={onLogout}><Text style={styles.logout}>Logout</Text></TouchableOpacity>
      </View>

      <View style={styles.body}>{screen}</View>

      <View style={styles.tabs}>
        {TABS.map((item) => (
          <TouchableOpacity key={item.key} style={[styles.tab, tab === item.key ? styles.tabActive : null]} onPress={() => setTab(item.key)}>
            <Text style={[styles.tabLabel, tab === item.key ? styles.tabLabelActive : null]}>{item.label}</Text>
          </TouchableOpacity>
        ))}
      </View>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#eef6fa',
  },
  header: {
    paddingHorizontal: 14,
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: '#c9dde8',
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#ffffff',
  },
  title: {
    fontSize: 18,
    fontWeight: '700',
    color: '#0c4f6c',
  },
  subtitle: {
    color: '#5d7381',
    marginTop: 2,
  },
  logout: {
    color: '#b12f2f',
    fontWeight: '600',
  },
  body: {
    flex: 1,
  },
  tabs: {
    flexDirection: 'row',
    borderTopWidth: 1,
    borderTopColor: '#c9dde8',
    backgroundColor: '#fff',
  },
  tab: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 12,
  },
  tabActive: {
    backgroundColor: '#e5f2f8',
  },
  tabLabel: {
    color: '#607887',
    fontWeight: '600',
  },
  tabLabelActive: {
    color: '#0c4f6c',
  },
})
