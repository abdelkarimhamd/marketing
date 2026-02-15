import React, { useEffect, useState } from 'react'
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native'
import { request } from '../api'

export default function InboxScreen({ token, tenantId }) {
  const [rows, setRows] = useState([])
  const [refreshing, setRefreshing] = useState(false)

  const load = async () => {
    setRefreshing(true)
    try {
      const response = await request('/api/admin/inbox?per_page=30', { token, tenantId })
      setRows(response.data || response.threads || [])
    } catch {
      setRows([])
    } finally {
      setRefreshing(false)
    }
  }

  useEffect(() => {
    load()
  }, [token, tenantId])

  return (
    <FlatList
      data={rows}
      keyExtractor={(item, index) => String(item.id || item.thread_key || index)}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={load} />}
      contentContainerStyle={styles.list}
      renderItem={({ item }) => (
        <View style={styles.card}>
          <Text style={styles.title}>{item.thread_key || item.channel || `Thread #${item.id}`}</Text>
          <Text style={styles.meta}>{item.preview || item.subject || '-'}</Text>
          <Text style={styles.meta}>Updated: {item.updated_at || item.created_at || '-'}</Text>
        </View>
      )}
      ListEmptyComponent={<Text style={styles.empty}>No inbox threads.</Text>}
    />
  )
}

const styles = StyleSheet.create({
  list: { padding: 12 },
  card: {
    borderWidth: 1,
    borderColor: '#d6e6ef',
    borderRadius: 10,
    padding: 12,
    backgroundColor: '#fff',
    marginBottom: 8,
  },
  title: { fontWeight: '700', color: '#0c4f6c' },
  meta: { color: '#5f7887', marginTop: 2 },
  empty: { textAlign: 'center', marginTop: 30, color: '#6f8794' },
})
