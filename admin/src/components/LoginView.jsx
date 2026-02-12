import { Box, Button, Paper, Stack, TextField, Typography } from '@mui/material'
import { useState } from 'react'

function LoginView({ onLogin, loading }) {
  const [form, setForm] = useState({
    email: 'tenant.admin@demo.test',
    password: 'password',
    device_name: 'react-admin',
  })

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'grid',
        placeItems: 'center',
        px: 2,
      }}
    >
      <Paper
        elevation={0}
        sx={{
          width: '100%',
          maxWidth: 460,
          p: 4,
          border: '1px solid #d7e7f4',
          borderRadius: 4,
          background: 'linear-gradient(160deg, #ffffff 0%, #f5fbff 100%)',
        }}
      >
        <Stack spacing={3}>
          <Box>
            <Typography variant="h4">Marketion Admin</Typography>
            <Typography color="text.secondary">Login + tenant-scoped CRM marketing control panel.</Typography>
          </Box>

          <TextField
            label="Email"
            value={form.email}
            onChange={(event) => setForm((prev) => ({ ...prev, email: event.target.value }))}
            fullWidth
          />

          <TextField
            label="Password"
            type="password"
            value={form.password}
            onChange={(event) => setForm((prev) => ({ ...prev, password: event.target.value }))}
            fullWidth
          />

          <TextField
            label="Device Name"
            value={form.device_name}
            onChange={(event) => setForm((prev) => ({ ...prev, device_name: event.target.value }))}
            fullWidth
          />

          <Button variant="contained" size="large" disabled={loading} onClick={() => onLogin(form)}>
            {loading ? 'Signing in...' : 'Sign in'}
          </Button>
        </Stack>
      </Paper>
    </Box>
  )
}

export default LoginView
