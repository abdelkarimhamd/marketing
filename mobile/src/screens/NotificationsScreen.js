import React, { useState } from 'react'
import { Button, StyleSheet, Text, View } from 'react-native'
import * as Device from 'expo-device'
import * as Notifications from 'expo-notifications'
import Constants from 'expo-constants'
import { request } from '../api'

async function registerForPushNotificationsAsync() {
  if (!Device.isDevice) {
    throw new Error('Push notifications require a physical device.')
  }

  const { status: existingStatus } = await Notifications.getPermissionsAsync()
  let finalStatus = existingStatus

  if (existingStatus !== 'granted') {
    const { status } = await Notifications.requestPermissionsAsync()
    finalStatus = status
  }

  if (finalStatus !== 'granted') {
    throw new Error('Notification permission not granted.')
  }

  const projectId = Constants?.expoConfig?.extra?.eas?.projectId || Constants?.easConfig?.projectId
  const token = await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined)
  return token.data
}

export default function NotificationsScreen({ token, tenantId }) {
  const [message, setMessage] = useState('')
  const [deviceToken, setDeviceToken] = useState('')

  const register = async () => {
    try {
      const expoToken = await registerForPushNotificationsAsync()
      setDeviceToken(expoToken)
      await request('/api/admin/mobile/device-tokens', {
        method: 'POST',
        token,
        tenantId,
        body: {
          platform: Device.osName?.toLowerCase().includes('ios') ? 'ios' : 'android',
          token: expoToken,
          meta: { source: 'expo' },
        },
      })
      setMessage('Device token registered successfully.')
    } catch (error) {
      setMessage(error.message || 'Failed to register token.')
    }
  }

  const unregister = async () => {
    if (!deviceToken) return

    try {
      await request('/api/admin/mobile/device-tokens', {
        method: 'DELETE',
        token,
        tenantId,
        body: { token: deviceToken },
      })
      setMessage('Device token unregistered.')
      setDeviceToken('')
    } catch (error) {
      setMessage(error.message || 'Failed to unregister token.')
    }
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Push Notifications</Text>
      <Button title="Register Device Token" onPress={register} />
      <Button title="Unregister Device Token" onPress={unregister} disabled={!deviceToken} color="#9c3a2b" />
      <Text style={styles.meta}>Token: {deviceToken || '-'}</Text>
      <Text style={styles.meta}>{message}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    padding: 16,
    gap: 12,
    backgroundColor: '#f6fafc',
  },
  title: {
    fontSize: 20,
    fontWeight: '700',
    color: '#0c4f6c',
  },
  meta: {
    color: '#587082',
    fontSize: 12,
  },
})
