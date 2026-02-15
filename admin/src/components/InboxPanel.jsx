import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Card,
  CardContent,
  Divider,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
  Paper,
  Button,
} from '@mui/material'
import { apiRequest, formatDate } from '../lib/api'

function InboxPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [messages, setMessages] = useState([])
  const [threadRows, setThreadRows] = useState([])
  const [suggestions, setSuggestions] = useState([])
  const [suggestionsLoading, setSuggestionsLoading] = useState(false)
  const [search, setSearch] = useState('')
  const [channel, setChannel] = useState('')
  const [threadKey, setThreadKey] = useState('')
  const canSuggestPlaybooks = can('playbooks.suggest')

  const threadContext = useMemo(() => {
    if (threadRows.length === 0) return null

    const latest = threadRows[threadRows.length - 1]
    const lead = latest?.lead ?? null
    const qSource = [latest?.subject, latest?.body].filter(Boolean).join(' ')

    return {
      threadKey: threadKey || latest?.thread_key || '',
      leadId: lead?.id ?? latest?.lead_id ?? null,
      stage: lead?.status ?? null,
      channel: latest?.channel ?? null,
      query: qSource.trim().slice(0, 160),
    }
  }, [threadKey, threadRows])

  const loadMessages = useCallback(async () => {
    try {
      const query = new URLSearchParams()
      query.set('per_page', '50')
      if (search.trim()) query.set('search', search.trim())
      if (channel) query.set('channel', channel)

      const response = await apiRequest(`/api/admin/inbox?${query.toString()}`, { token, tenantId })
      setMessages(response.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [channel, onNotify, search, tenantId, token])

  const loadThread = useCallback(async () => {
    if (!threadKey) {
      setThreadRows([])
      setSuggestions([])
      return
    }

    try {
      const response = await apiRequest(`/api/admin/inbox/thread/${encodeURIComponent(threadKey)}`, { token, tenantId })
      setThreadRows(response.messages ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
      setThreadRows([])
      setSuggestions([])
    }
  }, [onNotify, tenantId, threadKey, token])

  const loadSuggestions = useCallback(async () => {
    if (!canSuggestPlaybooks) {
      setSuggestions([])
      return
    }

    if (!threadContext) {
      setSuggestions([])
      return
    }

    setSuggestionsLoading(true)
    try {
      const params = new URLSearchParams({ limit: '4' })
      if (threadContext.threadKey) params.set('thread_key', threadContext.threadKey)
      if (threadContext.leadId) params.set('lead_id', String(threadContext.leadId))
      if (threadContext.stage) params.set('stage', String(threadContext.stage))
      if (threadContext.channel) params.set('channel', String(threadContext.channel))
      if (threadContext.query) params.set('q', threadContext.query)

      const response = await apiRequest(`/api/admin/playbooks/suggestions?${params.toString()}`, { token, tenantId })
      setSuggestions(response.suggestions ?? [])
    } catch {
      // Keep this silent for users/tenants without playbook feature permissions.
      setSuggestions([])
    } finally {
      setSuggestionsLoading(false)
    }
  }, [canSuggestPlaybooks, tenantId, threadContext, token])

  useEffect(() => {
    loadMessages()
  }, [loadMessages, refreshKey])

  useEffect(() => {
    loadThread()
  }, [loadThread])

  useEffect(() => {
    loadSuggestions()
  }, [loadSuggestions])

  return (
    <Stack spacing={2}>
      <Typography variant="h5">Inbox</Typography>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 6 }}>
          <Card>
            <CardContent>
              <Stack direction="row" spacing={1} sx={{ mb: 1.2 }}>
                <TextField
                  size="small"
                  label="Search"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                />
                <FormControl size="small" sx={{ minWidth: 140 }}>
                  <InputLabel>Channel</InputLabel>
                  <Select label="Channel" value={channel} onChange={(event) => setChannel(event.target.value)}>
                    <MenuItem value="">all</MenuItem>
                    <MenuItem value="email">email</MenuItem>
                    <MenuItem value="sms">sms</MenuItem>
                    <MenuItem value="whatsapp">whatsapp</MenuItem>
                  </Select>
                </FormControl>
                <Button variant="outlined" onClick={loadMessages}>Refresh</Button>
              </Stack>
              <Divider sx={{ mb: 1.2 }} />
              <Stack spacing={1}>
                {messages.length === 0 && <Typography color="text.secondary">No messages.</Typography>}
                {messages.map((message) => (
                  <Paper
                    key={message.id}
                    variant={threadKey === message.thread_key ? 'elevation' : 'outlined'}
                    sx={{ p: 1, cursor: message.thread_key ? 'pointer' : 'default' }}
                    onClick={() => message.thread_key && setThreadKey(message.thread_key)}
                  >
                    <Typography variant="body2">
                      <strong>{message.channel}</strong> - {message.direction} - {message.status}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      #{message.id} - {message.subject || message.body?.slice(0, 80) || '-'}
                    </Typography>
                    <Typography variant="caption" color="text.secondary" display="block">
                      {formatDate(message.created_at)}
                    </Typography>
                  </Paper>
                ))}
              </Stack>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 6 }}>
          <Card>
            <CardContent>
              <Typography variant="subtitle1">Thread Timeline</Typography>
              <Typography variant="caption" color="text.secondary">
                {threadKey || 'Select a thread'}
              </Typography>
              <Divider sx={{ my: 1.2 }} />
              <Stack spacing={1}>
                {threadRows.length === 0 && <Typography color="text.secondary">No thread messages.</Typography>}
                {threadRows.map((message) => (
                  <Paper key={message.id} variant="outlined" sx={{ p: 1 }}>
                    <Typography variant="body2">
                      <strong>{message.direction}</strong> - {message.status}
                    </Typography>
                    <Typography variant="body2">{message.subject || '-'}</Typography>
                    <Typography variant="caption" color="text.secondary">
                      {message.body?.slice(0, 140) || '-'}
                    </Typography>
                    <Typography variant="caption" color="text.secondary" display="block">
                      {formatDate(message.created_at)}
                    </Typography>
                  </Paper>
                ))}
              </Stack>

              {canSuggestPlaybooks && (
                <>
                  <Divider sx={{ my: 1.2 }} />
                  <Typography variant="subtitle2">Playbook Suggestions</Typography>
                  <Stack spacing={1} sx={{ mt: 1 }}>
                    {suggestionsLoading && (
                      <Typography color="text.secondary">Loading suggestions...</Typography>
                    )}
                    {!suggestionsLoading && suggestions.length === 0 && (
                      <Typography color="text.secondary">No suggestions for this thread context.</Typography>
                    )}
                    {suggestions.map((suggestion) => (
                      <Paper key={`playbook-suggestion-${suggestion.playbook_id}`} variant="outlined" sx={{ p: 1 }}>
                        <Typography variant="body2">
                          <strong>{suggestion.name}</strong> ({suggestion.industry})
                        </Typography>
                        <Typography variant="caption" color="text.secondary" display="block">
                          stage: {suggestion.stage || 'any'} | channel: {suggestion.channel || 'any'}
                        </Typography>
                        {(suggestion.scripts ?? []).length > 0 && (
                          <Typography variant="caption" display="block">
                            Script: {suggestion.scripts[0]}
                          </Typography>
                        )}
                        {(suggestion.objections ?? []).length > 0 && (
                          <Typography variant="caption" display="block">
                            Response: {suggestion.objections[0].response || '-'}
                          </Typography>
                        )}
                      </Paper>
                    ))}
                  </Stack>
                </>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </Stack>
  )
}

export default InboxPanel
