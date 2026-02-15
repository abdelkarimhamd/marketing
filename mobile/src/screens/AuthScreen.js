import React, { useState } from 'react'
import { Button, StyleSheet, Text, TextInput, View } from 'react-native'

export default function AuthScreen({ onLogin, loading }) {
  const [email, setEmail] = useState('tenant.admin@demo.test')
  const [password, setPassword] = useState('password')

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Marketion Mobile</Text>
      <TextInput style={styles.input} value={email} onChangeText={setEmail} placeholder="Email" autoCapitalize="none" />
      <TextInput style={styles.input} value={password} onChangeText={setPassword} placeholder="Password" secureTextEntry />
      <Button title={loading ? 'Signing in...' : 'Sign In'} onPress={() => onLogin({ email, password })} disabled={loading} />
    </View>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    padding: 20,
    gap: 12,
    backgroundColor: '#f4f8fb',
  },
  title: {
    fontSize: 24,
    fontWeight: '700',
    color: '#0c4f6c',
  },
  input: {
    borderWidth: 1,
    borderColor: '#bfd5e3',
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    backgroundColor: '#fff',
  },
})
