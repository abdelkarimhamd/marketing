import { useCallback, useEffect, useState } from 'react'
import {
  Button,
  Card,
  CardContent,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material'
import { Add as AddIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

function parseCommaList(text) {
  return String(text ?? '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

function addonsToText(addons) {
  if (Array.isArray(addons)) {
    return addons.map((item) => String(item)).join(', ')
  }

  if (addons && typeof addons === 'object' && Array.isArray(addons.features)) {
    return addons.features.map((item) => String(item)).join(', ')
  }

  return ''
}

function emptyPlanForm() {
  return {
    name: '',
    slug: '',
    seat_limit: 1,
    message_bundle: 0,
    monthly_price: 0,
    overage_price_per_message: 0,
    hard_limit: false,
    is_active: true,
    addonsText: '',
  }
}

function BillingPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [plans, setPlans] = useState([])
  const [subscription, setSubscription] = useState(null)
  const [tenant, setTenant] = useState(null)
  const [usageRows, setUsageRows] = useState([])
  const [invoices, setInvoices] = useState([])
  const [planDialogOpen, setPlanDialogOpen] = useState(false)
  const [editingPlan, setEditingPlan] = useState(null)
  const [planForm, setPlanForm] = useState(emptyPlanForm())
  const [subscriptionForm, setSubscriptionForm] = useState({
    billing_plan_id: '',
    status: 'active',
    seat_limit_override: '',
    message_bundle_override: '',
    overage_price_override: '',
    provider: 'manual',
    provider_subscription_id: '',
  })
  const [savingPlan, setSavingPlan] = useState(false)
  const [savingSubscription, setSavingSubscription] = useState(false)
  const [generatingInvoice, setGeneratingInvoice] = useState(false)
  const canManage = can('settings.update') || can('billing.create') || can('billing.update') || can('billing.delete')

  const loadBilling = useCallback(async () => {
    try {
      const [planResponse, subscriptionResponse, usageResponse, invoiceResponse] = await Promise.all([
        apiRequest('/api/admin/billing/plans', { token, tenantId }),
        apiRequest('/api/admin/billing/subscription', { token, tenantId }),
        apiRequest('/api/admin/billing/usage?per_page=25', { token, tenantId }),
        apiRequest('/api/admin/billing/invoices?per_page=25', { token, tenantId }),
      ])

      const sub = subscriptionResponse.subscription ?? null

      setPlans(planResponse.plans ?? [])
      setTenant(subscriptionResponse.tenant ?? null)
      setSubscription(sub)
      setUsageRows(usageResponse.data ?? [])
      setInvoices(invoiceResponse.data ?? [])
      setSubscriptionForm({
        billing_plan_id: sub?.billing_plan_id ? String(sub.billing_plan_id) : '',
        status: sub?.status ?? 'active',
        seat_limit_override: sub?.seat_limit_override != null ? String(sub.seat_limit_override) : '',
        message_bundle_override: sub?.message_bundle_override != null ? String(sub.message_bundle_override) : '',
        overage_price_override: sub?.overage_price_override != null ? String(sub.overage_price_override) : '',
        provider: sub?.provider ?? 'manual',
        provider_subscription_id: sub?.provider_subscription_id ?? '',
      })
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadBilling()
  }, [loadBilling, refreshKey])

  const openNewPlan = () => {
    if (!canManage) {
      onNotify('You do not have permission to manage billing.', 'warning')
      return
    }

    setEditingPlan(null)
    setPlanForm(emptyPlanForm())
    setPlanDialogOpen(true)
  }

  const openEditPlan = (plan) => {
    if (!canManage) {
      onNotify('You do not have permission to manage billing.', 'warning')
      return
    }

    setEditingPlan(plan)
    setPlanForm({
      name: plan.name ?? '',
      slug: plan.slug ?? '',
      seat_limit: Number(plan.seat_limit ?? 1),
      message_bundle: Number(plan.message_bundle ?? 0),
      monthly_price: Number(plan.monthly_price ?? 0),
      overage_price_per_message: Number(plan.overage_price_per_message ?? 0),
      hard_limit: Boolean(plan.hard_limit),
      is_active: Boolean(plan.is_active),
      addonsText: addonsToText(plan.addons),
    })
    setPlanDialogOpen(true)
  }

  const savePlan = async () => {
    if (!canManage) {
      onNotify('You do not have permission to manage billing.', 'warning')
      return
    }

    const addons = { features: parseCommaList(planForm.addonsText) }

    const payload = {
      name: planForm.name.trim(),
      slug: planForm.slug.trim(),
      seat_limit: Number(planForm.seat_limit),
      message_bundle: Number(planForm.message_bundle),
      monthly_price: Number(planForm.monthly_price),
      overage_price_per_message: Number(planForm.overage_price_per_message),
      hard_limit: Boolean(planForm.hard_limit),
      is_active: Boolean(planForm.is_active),
      addons,
    }

    if (!payload.name || !payload.slug) {
      onNotify('Plan name and slug are required.', 'warning')
      return
    }

    setSavingPlan(true)
    try {
      if (editingPlan) {
        await apiRequest(`/api/admin/billing/plans/${editingPlan.id}`, {
          method: 'PUT',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Billing plan updated.', 'success')
      } else {
        await apiRequest('/api/admin/billing/plans', {
          method: 'POST',
          token,
          tenantId,
          body: payload,
        })
        onNotify('Billing plan created.', 'success')
      }

      setPlanDialogOpen(false)
      setEditingPlan(null)
      loadBilling()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavingPlan(false)
    }
  }

  const saveSubscription = async () => {
    if (!canManage) {
      onNotify('You do not have permission to manage billing.', 'warning')
      return
    }

    const payload = {
      billing_plan_id: subscriptionForm.billing_plan_id ? Number(subscriptionForm.billing_plan_id) : null,
      status: subscriptionForm.status,
      seat_limit_override: subscriptionForm.seat_limit_override ? Number(subscriptionForm.seat_limit_override) : null,
      message_bundle_override: subscriptionForm.message_bundle_override ? Number(subscriptionForm.message_bundle_override) : null,
      overage_price_override: subscriptionForm.overage_price_override ? Number(subscriptionForm.overage_price_override) : null,
      provider: subscriptionForm.provider.trim() || 'manual',
      provider_subscription_id: subscriptionForm.provider_subscription_id.trim() || null,
    }

    setSavingSubscription(true)
    try {
      await apiRequest('/api/admin/billing/subscription', {
        method: 'PUT',
        token,
        tenantId,
        body: payload,
      })
      onNotify('Subscription saved.', 'success')
      loadBilling()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavingSubscription(false)
    }
  }

  const generateInvoice = async () => {
    if (!canManage) {
      onNotify('You do not have permission to manage billing.', 'warning')
      return
    }

    setGeneratingInvoice(true)
    try {
      await apiRequest('/api/admin/billing/invoices/generate', {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify('Invoice generated.', 'success')
      loadBilling()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setGeneratingInvoice(false)
    }
  }

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between">
        <Typography variant="h5">Billing</Typography>
        <Stack direction="row" spacing={1}>
          {canManage && (
            <>
              <Button variant="outlined" onClick={generateInvoice} disabled={generatingInvoice}>
                {generatingInvoice ? 'Generating...' : 'Generate Invoice'}
              </Button>
              <Button variant="contained" startIcon={<AddIcon />} onClick={openNewPlan}>
                New Plan
              </Button>
            </>
          )}
        </Stack>
      </Stack>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 7 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Name</TableCell>
                    <TableCell>Slug</TableCell>
                    <TableCell>Seats</TableCell>
                    <TableCell>Messages</TableCell>
                    <TableCell>Monthly</TableCell>
                    <TableCell>Active</TableCell>
                    <TableCell align="right">Actions</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {plans.map((plan) => (
                    <TableRow key={plan.id}>
                      <TableCell>{plan.name}</TableCell>
                      <TableCell>{plan.slug}</TableCell>
                      <TableCell>{plan.seat_limit}</TableCell>
                      <TableCell>{plan.message_bundle}</TableCell>
                      <TableCell>{plan.monthly_price}</TableCell>
                      <TableCell>{plan.is_active ? 'yes' : 'no'}</TableCell>
                      <TableCell align="right">
                        {canManage && (
                          <Button size="small" onClick={() => openEditPlan(plan)}>
                            Edit
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                  {plans.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={7}>
                        <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                          No billing plans.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 5 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Tenant Subscription</Typography>
              <Typography variant="caption" color="text.secondary">
                {tenant ? `${tenant.name} (${tenant.slug})` : 'No tenant context'}
              </Typography>
              <Divider sx={{ my: 1.2 }} />
              <Stack spacing={1.2}>
                <FormControl size="small" fullWidth>
                  <InputLabel>Plan</InputLabel>
                  <Select
                    label="Plan"
                  value={subscriptionForm.billing_plan_id}
                  disabled={!canManage}
                  onChange={(event) =>
                    setSubscriptionForm((current) => ({ ...current, billing_plan_id: event.target.value }))
                  }
                  >
                    <MenuItem value="">None</MenuItem>
                    {plans.map((plan) => (
                      <MenuItem key={plan.id} value={String(plan.id)}>
                        {plan.name} ({plan.slug})
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>

                <FormControl size="small" fullWidth>
                  <InputLabel>Status</InputLabel>
                  <Select
                  label="Status"
                  value={subscriptionForm.status}
                  disabled={!canManage}
                  onChange={(event) => setSubscriptionForm((current) => ({ ...current, status: event.target.value }))}
                >
                    <MenuItem value="trialing">trialing</MenuItem>
                    <MenuItem value="active">active</MenuItem>
                    <MenuItem value="past_due">past_due</MenuItem>
                    <MenuItem value="cancelled">cancelled</MenuItem>
                  </Select>
                </FormControl>

                <TextField
                  size="small"
                  label="Seat Limit Override"
                  value={subscriptionForm.seat_limit_override}
                  disabled={!canManage}
                  onChange={(event) =>
                    setSubscriptionForm((current) => ({ ...current, seat_limit_override: event.target.value }))
                  }
                />
                <TextField
                  size="small"
                  label="Message Bundle Override"
                  value={subscriptionForm.message_bundle_override}
                  disabled={!canManage}
                  onChange={(event) =>
                    setSubscriptionForm((current) => ({ ...current, message_bundle_override: event.target.value }))
                  }
                />
                <TextField
                  size="small"
                  label="Overage Price Override"
                  value={subscriptionForm.overage_price_override}
                  disabled={!canManage}
                  onChange={(event) =>
                    setSubscriptionForm((current) => ({ ...current, overage_price_override: event.target.value }))
                  }
                />
                <TextField
                  size="small"
                  label="Provider"
                  value={subscriptionForm.provider}
                  disabled={!canManage}
                  onChange={(event) => setSubscriptionForm((current) => ({ ...current, provider: event.target.value }))}
                />
                <TextField
                  size="small"
                  label="Provider Subscription ID"
                  value={subscriptionForm.provider_subscription_id}
                  disabled={!canManage}
                  onChange={(event) =>
                    setSubscriptionForm((current) => ({ ...current, provider_subscription_id: event.target.value }))
                  }
                />

                <Button variant="contained" onClick={saveSubscription} disabled={savingSubscription || !canManage}>
                  {savingSubscription ? 'Saving...' : 'Save Subscription'}
                </Button>

                {subscription && (
                  <Typography variant="caption" color="text.secondary">
                    Current period: {formatDate(subscription.current_period_start)} {'->'} {formatDate(subscription.current_period_end)}
                  </Typography>
                )}
              </Stack>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 6 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Usage Records</Typography>
              <Divider sx={{ my: 1.2 }} />
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Date</TableCell>
                    <TableCell>Channel</TableCell>
                    <TableCell>Messages</TableCell>
                    <TableCell>Cost</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {usageRows.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell>{row.period_date}</TableCell>
                      <TableCell>{row.channel}</TableCell>
                      <TableCell>{row.messages_count}</TableCell>
                      <TableCell>{row.cost_total}</TableCell>
                    </TableRow>
                  ))}
                  {usageRows.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={4}>
                        <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                          No usage records.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 6 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Invoices</Typography>
              <Divider sx={{ my: 1.2 }} />
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>#</TableCell>
                    <TableCell>Status</TableCell>
                    <TableCell>Grand Total</TableCell>
                    <TableCell>Currency</TableCell>
                    <TableCell>Issued</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {invoices.map((invoice) => (
                    <TableRow key={invoice.id}>
                      <TableCell>{invoice.invoice_number}</TableCell>
                      <TableCell>{invoice.status}</TableCell>
                      <TableCell>{invoice.grand_total}</TableCell>
                      <TableCell>{invoice.currency}</TableCell>
                      <TableCell>{formatDate(invoice.issued_at)}</TableCell>
                    </TableRow>
                  ))}
                  {invoices.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={5}>
                        <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                          No invoices.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Dialog open={planDialogOpen} onClose={() => setPlanDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>{editingPlan ? `Edit Plan: ${editingPlan.name}` : 'Create Billing Plan'}</DialogTitle>
        <DialogContent>
          <Stack spacing={1.2} sx={{ mt: 1 }}>
            <TextField
              label="Name"
              value={planForm.name}
              onChange={(event) => setPlanForm((current) => ({ ...current, name: event.target.value }))}
            />
            <TextField
              label="Slug"
              value={planForm.slug}
              onChange={(event) => setPlanForm((current) => ({ ...current, slug: event.target.value }))}
            />
            <Stack direction="row" spacing={1}>
              <TextField
                label="Seat Limit"
                type="number"
                value={planForm.seat_limit}
                onChange={(event) => setPlanForm((current) => ({ ...current, seat_limit: event.target.value }))}
              />
              <TextField
                label="Message Bundle"
                type="number"
                value={planForm.message_bundle}
                onChange={(event) => setPlanForm((current) => ({ ...current, message_bundle: event.target.value }))}
              />
            </Stack>
            <Stack direction="row" spacing={1}>
              <TextField
                label="Monthly Price"
                type="number"
                value={planForm.monthly_price}
                onChange={(event) => setPlanForm((current) => ({ ...current, monthly_price: event.target.value }))}
              />
              <TextField
                label="Overage Price"
                type="number"
                value={planForm.overage_price_per_message}
                onChange={(event) =>
                  setPlanForm((current) => ({ ...current, overage_price_per_message: event.target.value }))
                }
              />
            </Stack>
            <Stack direction="row" spacing={2}>
              <FormControl size="small" sx={{ minWidth: 140 }}>
                <InputLabel>Hard Limit</InputLabel>
                <Select
                  label="Hard Limit"
                  value={planForm.hard_limit ? 'yes' : 'no'}
                  onChange={(event) =>
                    setPlanForm((current) => ({ ...current, hard_limit: event.target.value === 'yes' }))
                  }
                >
                  <MenuItem value="yes">yes</MenuItem>
                  <MenuItem value="no">no</MenuItem>
                </Select>
              </FormControl>
              <FormControl size="small" sx={{ minWidth: 140 }}>
                <InputLabel>Active</InputLabel>
                <Select
                  label="Active"
                  value={planForm.is_active ? 'yes' : 'no'}
                  onChange={(event) =>
                    setPlanForm((current) => ({ ...current, is_active: event.target.value === 'yes' }))
                  }
                >
                  <MenuItem value="yes">yes</MenuItem>
                  <MenuItem value="no">no</MenuItem>
                </Select>
              </FormControl>
            </Stack>
            <TextField
              label="Add-on features (comma separated)"
              value={planForm.addonsText}
              onChange={(event) => setPlanForm((current) => ({ ...current, addonsText: event.target.value }))}
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setPlanDialogOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={savePlan} disabled={savingPlan || !canManage}>
            {savingPlan ? 'Saving...' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default BillingPanel
