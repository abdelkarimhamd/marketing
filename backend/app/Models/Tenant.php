<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'public_key',
        'domain',
        'settings',
        'branding',
        'timezone',
        'locale',
        'currency',
        'data_residency_region',
        'data_residency_locked',
        'sso_required',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'branding' => 'array',
            'data_residency_locked' => 'boolean',
            'sso_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the users for the tenant.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Teams that belong to this tenant.
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Leads that belong to this tenant.
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Tags that belong to this tenant.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Activities for this tenant.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Segments for this tenant.
     */
    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    /**
     * Templates for this tenant.
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * Brand profiles managed by this tenant.
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * Campaigns for this tenant.
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Campaign steps for this tenant.
     */
    public function campaignSteps(): HasMany
    {
        return $this->hasMany(CampaignStep::class);
    }

    /**
     * Messages for this tenant.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Webhook inbox rows for this tenant.
     */
    public function webhooksInbox(): HasMany
    {
        return $this->hasMany(WebhookInbox::class);
    }

    /**
     * Unsubscribe records for this tenant.
     */
    public function unsubscribes(): HasMany
    {
        return $this->hasMany(Unsubscribe::class);
    }

    /**
     * Assignment rules for this tenant.
     */
    public function assignmentRules(): HasMany
    {
        return $this->hasMany(AssignmentRule::class);
    }

    /**
     * API keys for this tenant.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * Custom tenant roles.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(TenantRole::class);
    }

    /**
     * Verified/admin/landing domains mapped to this tenant.
     */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    /**
     * Consent events collected for this tenant.
     */
    public function consentEvents(): HasMany
    {
        return $this->hasMany(ConsentEvent::class);
    }

    /**
     * Tenant subscriptions.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    /**
     * Billing usage records for this tenant.
     */
    public function billingUsageRecords(): HasMany
    {
        return $this->hasMany(BillingUsageRecord::class);
    }

    /**
     * Billing invoices for this tenant.
     */
    public function billingInvoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    /**
     * External integrations configured by the tenant.
     */
    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    /**
     * Outbound event subscriptions.
     */
    public function integrationEventSubscriptions(): HasMany
    {
        return $this->hasMany(IntegrationEventSubscription::class);
    }

    /**
     * Tenant SSO configuration rows.
     */
    public function ssoConfigs(): HasMany
    {
        return $this->hasMany(TenantSsoConfig::class);
    }

    /**
     * SCIM access tokens.
     */
    public function scimAccessTokens(): HasMany
    {
        return $this->hasMany(ScimAccessToken::class);
    }

    /**
     * Realtime events for UI streams.
     */
    public function realtimeEvents(): HasMany
    {
        return $this->hasMany(RealtimeEvent::class);
    }

    /**
     * Sales call logs.
     */
    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    /**
     * Sandbox entries linked to this tenant.
     */
    public function sandboxes(): HasMany
    {
        return $this->hasMany(TenantSandbox::class);
    }

    /**
     * Custom fields defined by this tenant.
     */
    public function customFields(): HasMany
    {
        return $this->hasMany(CustomField::class);
    }

    /**
     * Lead forms defined by this tenant.
     */
    public function leadForms(): HasMany
    {
        return $this->hasMany(LeadForm::class);
    }

    /**
     * Saved lead import mapping presets.
     */
    public function leadImportPresets(): HasMany
    {
        return $this->hasMany(LeadImportPreset::class);
    }

    /**
     * Scheduled lead import jobs.
     */
    public function leadImportSchedules(): HasMany
    {
        return $this->hasMany(LeadImportSchedule::class);
    }

    /**
     * Workflow versions for templates/campaigns/segments.
     */
    public function workflowVersions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    /**
     * Approval requests submitted in this tenant.
     */
    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    /**
     * Lead preferences by channel/topics.
     */
    public function leadPreferences(): HasMany
    {
        return $this->hasMany(LeadPreference::class);
    }

    /**
     * AI interaction logs.
     */
    public function aiInteractions(): HasMany
    {
        return $this->hasMany(AiInteraction::class);
    }

    /**
     * Export job rows.
     */
    public function exportJobs(): HasMany
    {
        return $this->hasMany(ExportJob::class);
    }

    /**
     * Country compliance rules.
     */
    public function countryComplianceRules(): HasMany
    {
        return $this->hasMany(CountryComplianceRule::class);
    }

    /**
     * Historical tenant health snapshots.
     */
    public function healthSnapshots(): HasMany
    {
        return $this->hasMany(TenantHealthSnapshot::class);
    }

    /**
     * Appointment bookings in this tenant.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Proposal templates configured by this tenant.
     */
    public function proposalTemplates(): HasMany
    {
        return $this->hasMany(ProposalTemplate::class);
    }

    /**
     * Generated proposals in this tenant.
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * Tenant-managed encryption keys used for sensitive settings.
     */
    public function encryptionKeys(): HasMany
    {
        return $this->hasMany(TenantEncryptionKey::class);
    }

    /**
     * Accounts under this tenant.
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Tracking visitors in this tenant.
     */
    public function trackingVisitors(): HasMany
    {
        return $this->hasMany(TrackingVisitor::class);
    }

    /**
     * Tracking events in this tenant.
     */
    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class);
    }

    /**
     * Personalization rules in this tenant.
     */
    public function personalizationRules(): HasMany
    {
        return $this->hasMany(PersonalizationRule::class);
    }

    /**
     * Mobile device token registrations.
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Telephony call rows.
     */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /**
     * Portal request rows.
     */
    public function portalRequests(): HasMany
    {
        return $this->hasMany(PortalRequest::class);
    }

    /**
     * Data quality runs.
     */
    public function dataQualityRuns(): HasMany
    {
        return $this->hasMany(DataQualityRun::class);
    }

    /**
     * Merge suggestion rows.
     */
    public function mergeSuggestions(): HasMany
    {
        return $this->hasMany(MergeSuggestion::class);
    }

    /**
     * Experiment definitions.
     */
    public function experiments(): HasMany
    {
        return $this->hasMany(Experiment::class);
    }

    /**
     * Experiment assignment rows.
     */
    public function experimentAssignments(): HasMany
    {
        return $this->hasMany(ExperimentAssignment::class);
    }

    /**
     * Marketplace installs for this tenant.
     */
    public function appInstalls(): HasMany
    {
        return $this->hasMany(AppInstall::class);
    }

    /**
     * App webhook rows for this tenant.
     */
    public function appWebhooks(): HasMany
    {
        return $this->hasMany(AppWebhook::class);
    }

    /**
     * Domain event bus rows for this tenant.
     */
    public function domainEvents(): HasMany
    {
        return $this->hasMany(DomainEvent::class);
    }

    /**
     * AI summaries for this tenant.
     */
    public function aiSummaries(): HasMany
    {
        return $this->hasMany(AiSummary::class);
    }

    /**
     * AI recommendations for this tenant.
     */
    public function aiRecommendations(): HasMany
    {
        return $this->hasMany(AiRecommendation::class);
    }
}
