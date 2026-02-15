import React, { useEffect, useState } from 'react'
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native'
import { request } from '../api'

export default function LeadsScreen({ token, tenantId }) {
  const [rows, setRows] = useState([])
  const [refreshing, setRefreshing] = useState(false)

  const load = async () => {
    setRefreshing(true)
    try {
      const response = await request('/api/admin/leads?per_page=30', { token, tenantId })
      setRows(response.data || [])
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
      keyExtractor={(item) => String(item.id)}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={load} />}
      contentContainerStyle={styles.list}
      renderItem={({ item }) => (
        <View style={styles.card}>
          <Text style={styles.name}>{item.first_name || ''} {item.last_name || ''}</Text>
          <Text style={styles.meta}>{item.email || item.phone || '-'}</Text>
          <Text style={styles.meta}>Status: {item.status || '-'} | Score: {item.score || 0}</Text>
        </View>
      )}
      ListEmptyComponent={<Text style={styles.empty}>No leads found.</Text>}
    />
  )
}

const styles = StyleSheet.create({
  list: {
    padding: 12,
    gap: 8,
  },
  card: {
    borderWidth: 1,
    borderColor: '#d6e6ef',
    borderRadius: 10,
    padding: 12,
    backgroundColor: '#fff',
    marginBottom: 8,
  },
  name: {
    fontSize: 16,
    fontWeight: '700',
    color: '#0c4f6c',
  },
  meta: {
    color: '#4d6778',
    marginTop: 2,
  },
  empty: {
    textAlign: 'center',
    marginTop: 30,
    color: '#6f8794',
  },
})
