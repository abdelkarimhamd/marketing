import React, { useEffect, useMemo, useState } from 'react'
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native'
import { request } from '../api'

export default function DealsScreen({ token, tenantId }) {
  const [rows, setRows] = useState([])
  const [refreshing, setRefreshing] = useState(false)

  const load = async () => {
    setRefreshing(true)
    try {
      const response = await request('/api/admin/leads?per_page=50', { token, tenantId })
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

  const grouped = useMemo(() => {
    const bucket = { new: [], contacted: [], won: [], lost: [] }
    rows.forEach((row) => {
      const key = bucket[row.status] ? row.status : 'new'
      bucket[key].push(row)
    })
    return bucket
  }, [rows])

  return (
    <ScrollView
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={load} />}
      contentContainerStyle={styles.wrap}
    >
      {Object.entries(grouped).map(([stage, items]) => (
        <View key={stage} style={styles.column}>
          <Text style={styles.stage}>{stage.toUpperCase()} ({items.length})</Text>
          {items.slice(0, 8).map((item) => (
            <View key={item.id} style={styles.card}>
              <Text style={styles.name}>{item.first_name || ''} {item.last_name || ''}</Text>
              <Text style={styles.meta}>{item.company || item.email || '-'}</Text>
            </View>
          ))}
          {items.length === 0 && <Text style={styles.empty}>No deals.</Text>}
        </View>
      ))}
    </ScrollView>
  )
}

const styles = StyleSheet.create({
  wrap: {
    padding: 12,
    gap: 12,
  },
  column: {
    borderWidth: 1,
    borderColor: '#d8e8f0',
    borderRadius: 10,
    padding: 10,
    backgroundColor: '#fff',
  },
  stage: {
    fontWeight: '700',
    color: '#0c4f6c',
    marginBottom: 8,
  },
  card: {
    padding: 8,
    borderWidth: 1,
    borderColor: '#e2eef5',
    borderRadius: 8,
    marginBottom: 6,
    backgroundColor: '#fbfdff',
  },
  name: { fontWeight: '600' },
  meta: { color: '#6a8292' },
  empty: { color: '#8ca0ae' },
})
