<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PublicBrandingController;
use App\Http\Controllers\Api\PublicChatController;
use App\Http\Controllers\Api\PublicSignupController;
use App\Http\Controllers\Api\PublicPortalController;
use App\Http\Controllers\Api\PublicTrackingController;
use App\Http\Controllers\Api\ScimController;
use App\Http\Controllers\Api\TelephonyWebhookController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\Admin\AccountController;
use App\Http\Controllers\Api\Admin\AiController;
use App\Http\Controllers\Api\Admin\ApiKeyController;
use App\Http\Controllers\Api\Admin\ApprovalController;
use App\Http\Controllers\Api\Admin\AppointmentController;
use App\Http\Controllers\Api\Admin\AttachmentController;
use App\Http\Controllers\Api\Admin\AssignmentRuleController;
use App\Http\Controllers\Api\Admin\BrandController;
use App\Http\Controllers\Api\Admin\BillingController;
use App\Http\Controllers\Api\Admin\CampaignController;
use App\Http\Controllers\Api\Admin\CampaignLogController;
use App\Http\Controllers\Api\Admin\CallLogController;
use App\Http\Controllers\Api\Admin\ComplianceController;
use App\Http\Controllers\Api\Admin\ConsentController;
use App\Http\Controllers\Api\Admin\CopilotController;
use App\Http\Controllers\Api\Admin\CustomFieldController;
use App\Http\Controllers\Api\Admin\DataQualityController;
use App\Http\Controllers\Api\Admin\DataLifecycleController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\DeviceTokenController;
use App\Http\Controllers\Api\Admin\ExperimentController;
use App\Http\Controllers\Api\Admin\ExportController;
use App\Http\Controllers\Api\Admin\GlobalSearchController;
use App\Http\Controllers\Api\Admin\HealthController;
use App\Http\Controllers\Api\Admin\InboxController;
use App\Http\Controllers\Api\Admin\IntegrationController;
use App\Http\Controllers\Api\Admin\LeadController;
use App\Http\Controllers\Api\Admin\LeadActivityController;
use App\Http\Controllers\Api\Admin\LeadFormController;
use App\Http\Controllers\Api\Admin\LeadImportController;
use App\Http\Controllers\Api\Admin\MediaLibraryController;
use App\Http\Controllers\Api\Admin\MarketplaceController;
use App\Http\Controllers\Api\Admin\PersonalizationController;
use App\Http\Controllers\Api\Admin\PlaybookController;
use App\Http\Controllers\Api\Admin\PortalRequestController;
use App\Http\Controllers\Api\Admin\ProposalController;
use App\Http\Controllers\Api\Admin\ProposalTemplateController;
use App\Http\Controllers\Api\Admin\RealtimeController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SandboxController;
use App\Http\Controllers\Api\Admin\SavedViewController;
use App\Http\Controllers\Api\Admin\SegmentController;
use App\Http\Controllers\Api\Admin\SsoController;
use App\Http\Controllers\Api\Admin\TenantController;
use App\Http\Controllers\Api\Admin\TenantDomainController;
use App\Http\Controllers\Api\Admin\TenantSettingsController;
use App\Http\Controllers\Api\Admin\TemplateController;
use App\Http\Controllers\Api\Admin\TelephonyController;
use App\Http\Controllers\Api\Admin\TeamController;
use App\Http\Controllers\Api\Admin\TrackingController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\WebhookInboxController;
use App\Http\Controllers\Api\Admin\WorkflowController;
use App\Http\Controllers\Api\PublicLeadController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => ['status' => 'ok']);

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::prefix('admin')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/tenants', [TenantController::class, 'index']);
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::get('/roles/templates', [RoleController::class, 'templates']);
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/assignable-users', [RoleController::class, 'assignableUsers']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::get('/roles/{tenantRole}', [RoleController::class, 'show']);
        Route::put('/roles/{tenantRole}', [RoleController::class, 'update']);
        Route::patch('/roles/{tenantRole}', [RoleController::class, 'update']);
        Route::delete('/roles/{tenantRole}', [RoleController::class, 'destroy']);
        Route::post('/roles/{tenantRole}/assign', [RoleController::class, 'assign']);
        Route::post('/roles/{tenantRole}/unassign', [RoleController::class, 'unassign']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}/availability', [UserController::class, 'updateAvailability']);
        Route::patch('/users/{user}/booking-link', [UserController::class, 'updateBookingLink']);
        Route::get('/teams', [TeamController::class, 'index']);
        Route::patch('/teams/{team}/booking-link', [TeamController::class, 'updateBookingLink']);

        Route::get('/leads', [LeadController::class, 'index']);
        Route::get('/leads/assignment-options', [LeadController::class, 'assignmentOptions']);
        Route::post('/leads', [LeadController::class, 'store']);
        Route::post('/leads/import', [LeadController::class, 'import']);
        Route::get('/lead-import/presets', [LeadImportController::class, 'presetsIndex']);
        Route::post('/lead-import/presets', [LeadImportController::class, 'presetsStore']);
        Route::get('/lead-import/presets/{leadImportPreset}', [LeadImportController::class, 'presetsShow']);
        Route::put('/lead-import/presets/{leadImportPreset}', [LeadImportController::class, 'presetsUpdate']);
        Route::patch('/lead-import/presets/{leadImportPreset}', [LeadImportController::class, 'presetsUpdate']);
        Route::delete('/lead-import/presets/{leadImportPreset}', [LeadImportController::class, 'presetsDestroy']);
        Route::get('/lead-import/schedules', [LeadImportController::class, 'schedulesIndex']);
        Route::post('/lead-import/schedules', [LeadImportController::class, 'schedulesStore']);
        Route::get('/lead-import/schedules/{leadImportSchedule}', [LeadImportController::class, 'schedulesShow']);
        Route::put('/lead-import/schedules/{leadImportSchedule}', [LeadImportController::class, 'schedulesUpdate']);
        Route::patch('/lead-import/schedules/{leadImportSchedule}', [LeadImportController::class, 'schedulesUpdate']);
        Route::delete('/lead-import/schedules/{leadImportSchedule}', [LeadImportController::class, 'schedulesDestroy']);
        Route::post('/lead-import/schedules/{leadImportSchedule}/run', [LeadImportController::class, 'schedulesRunNow']);
        Route::post('/leads/bulk', [LeadController::class, 'bulk']);
        Route::post('/leads/merge', [LeadController::class, 'merge']);
        Route::post('/leads/{lead}/accounts/attach', [AccountController::class, 'attachLead']);
        Route::get('/leads/{lead}', [LeadController::class, 'show']);
        Route::get('/leads/{lead}/activities', [LeadActivityController::class, 'index']);
        Route::get('/leads/{lead}/web-activity', [TrackingController::class, 'leadEvents']);
        Route::get('/copilot/leads/{lead}', [CopilotController::class, 'show']);
        Route::post('/copilot/leads/{lead}/generate', [CopilotController::class, 'generate']);
        Route::put('/leads/{lead}', [LeadController::class, 'update']);
        Route::patch('/leads/{lead}', [LeadController::class, 'update']);
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);

        Route::get('/accounts', [AccountController::class, 'index']);
        Route::post('/accounts', [AccountController::class, 'store']);
        Route::get('/accounts/{account}', [AccountController::class, 'show']);
        Route::put('/accounts/{account}', [AccountController::class, 'update']);
        Route::patch('/accounts/{account}', [AccountController::class, 'update']);
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy']);
        Route::post('/accounts/{account}/contacts/attach', [AccountController::class, 'attachContact']);
        Route::post('/accounts/{account}/contacts/detach', [AccountController::class, 'detachContact']);
        Route::get('/accounts/{account}/timeline', [AccountController::class, 'timeline']);

        Route::get('/tracking/analytics', [TrackingController::class, 'analytics']);
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::post('/appointments', [AppointmentController::class, 'store']);
        Route::get('/attachments', [AttachmentController::class, 'index']);
        Route::post('/attachments', [AttachmentController::class, 'store']);
        Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download']);
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);
        Route::get('/media-library', [MediaLibraryController::class, 'index']);
        Route::post('/media-library', [MediaLibraryController::class, 'store']);
        Route::get('/media-library/{attachment}/download', [MediaLibraryController::class, 'download']);
        Route::delete('/media-library/{attachment}', [MediaLibraryController::class, 'destroy']);

        Route::get('/assignment-rules', [AssignmentRuleController::class, 'index']);
        Route::post('/assignment-rules', [AssignmentRuleController::class, 'store']);
        Route::get('/assignment-rules/{assignmentRule}', [AssignmentRuleController::class, 'show']);
        Route::put('/assignment-rules/{assignmentRule}', [AssignmentRuleController::class, 'update']);
        Route::patch('/assignment-rules/{assignmentRule}', [AssignmentRuleController::class, 'update']);
        Route::delete('/assignment-rules/{assignmentRule}', [AssignmentRuleController::class, 'destroy']);

        Route::get('/segments', [SegmentController::class, 'index']);
        Route::post('/segments', [SegmentController::class, 'store']);
        Route::get('/segments/{segment}', [SegmentController::class, 'show']);
        Route::put('/segments/{segment}', [SegmentController::class, 'update']);
        Route::patch('/segments/{segment}', [SegmentController::class, 'update']);
        Route::delete('/segments/{segment}', [SegmentController::class, 'destroy']);
        Route::get('/segments/{segment}/preview', [SegmentController::class, 'preview']);

        Route::get('/templates', [TemplateController::class, 'index']);
        Route::post('/templates', [TemplateController::class, 'store']);
        Route::get('/templates/{template}', [TemplateController::class, 'show']);
        Route::put('/templates/{template}', [TemplateController::class, 'update']);
        Route::patch('/templates/{template}', [TemplateController::class, 'update']);
        Route::delete('/templates/{template}', [TemplateController::class, 'destroy']);
        Route::post('/templates/{template}/render', [TemplateController::class, 'render']);
        Route::get('/proposal-templates', [ProposalTemplateController::class, 'index']);
        Route::post('/proposal-templates', [ProposalTemplateController::class, 'store']);
        Route::get('/proposal-templates/{proposalTemplate}', [ProposalTemplateController::class, 'show']);
        Route::put('/proposal-templates/{proposalTemplate}', [ProposalTemplateController::class, 'update']);
        Route::patch('/proposal-templates/{proposalTemplate}', [ProposalTemplateController::class, 'update']);
        Route::delete('/proposal-templates/{proposalTemplate}', [ProposalTemplateController::class, 'destroy']);
        Route::get('/proposals', [ProposalController::class, 'index']);
        Route::post('/proposals/generate', [ProposalController::class, 'generate']);
        Route::get('/proposals/{proposal}', [ProposalController::class, 'show']);
        Route::post('/proposals/{proposal}/send', [ProposalController::class, 'send']);
        Route::get('/playbooks', [PlaybookController::class, 'index']);
        Route::post('/playbooks', [PlaybookController::class, 'store']);
        Route::post('/playbooks/bootstrap', [PlaybookController::class, 'bootstrap']);
        Route::get('/playbooks/suggestions', [PlaybookController::class, 'suggestions']);
        Route::get('/playbooks/{playbook}', [PlaybookController::class, 'show']);
        Route::put('/playbooks/{playbook}', [PlaybookController::class, 'update']);
        Route::patch('/playbooks/{playbook}', [PlaybookController::class, 'update']);
        Route::delete('/playbooks/{playbook}', [PlaybookController::class, 'destroy']);

        Route::get('/campaigns', [CampaignController::class, 'index']);
        Route::post('/campaigns', [CampaignController::class, 'store']);
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update']);
        Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update']);
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy']);
        Route::post('/campaigns/{campaign}/wizard', [CampaignController::class, 'wizard']);
        Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch']);
        Route::get('/campaigns/{campaign}/logs', [CampaignLogController::class, 'index']);

        Route::get('/settings', [TenantSettingsController::class, 'show']);
        Route::put('/settings', [TenantSettingsController::class, 'update']);
        Route::patch('/settings', [TenantSettingsController::class, 'update']);
        Route::get('/brands', [BrandController::class, 'index']);
        Route::post('/brands', [BrandController::class, 'store']);
        Route::get('/brands/{brand}', [BrandController::class, 'show']);
        Route::put('/brands/{brand}', [BrandController::class, 'update']);
        Route::patch('/brands/{brand}', [BrandController::class, 'update']);
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);
        Route::post('/settings/encryption/rotate', [TenantSettingsController::class, 'rotateEncryptionKey']);
        Route::get('/domains', [TenantDomainController::class, 'index']);
        Route::post('/domains', [TenantDomainController::class, 'store']);
        Route::post('/domains/{tenantDomain}/verify', [TenantDomainController::class, 'verify']);
        Route::post('/domains/{tenantDomain}/ssl/provision', [TenantDomainController::class, 'provisionSsl']);
        Route::post('/domains/{tenantDomain}/primary', [TenantDomainController::class, 'setPrimary']);
        Route::delete('/domains/{tenantDomain}', [TenantDomainController::class, 'destroy']);

        Route::get('/personalization/rules', [PersonalizationController::class, 'index']);
        Route::post('/personalization/rules', [PersonalizationController::class, 'store']);
        Route::get('/personalization/rules/{personalizationRule}', [PersonalizationController::class, 'show']);
        Route::put('/personalization/rules/{personalizationRule}', [PersonalizationController::class, 'update']);
        Route::patch('/personalization/rules/{personalizationRule}', [PersonalizationController::class, 'update']);
        Route::delete('/personalization/rules/{personalizationRule}', [PersonalizationController::class, 'destroy']);
        Route::post('/personalization/rules/{personalizationRule}/preview', [PersonalizationController::class, 'preview']);

        Route::get('/data-quality/runs', [DataQualityController::class, 'runs']);
        Route::post('/data-quality/runs', [DataQualityController::class, 'start']);
        Route::get('/data-quality/merge-suggestions', [DataQualityController::class, 'suggestions']);
        Route::post('/data-quality/merge-suggestions/{mergeSuggestion}/review', [DataQualityController::class, 'review']);

        Route::get('/portal/requests', [PortalRequestController::class, 'index']);
        Route::patch('/portal/requests/{portalRequest}', [PortalRequestController::class, 'update']);
        Route::post('/portal/requests/{portalRequest}/convert', [PortalRequestController::class, 'convert']);

        Route::get('/experiments', [ExperimentController::class, 'index']);
        Route::post('/experiments', [ExperimentController::class, 'store']);
        Route::get('/experiments/{experiment}', [ExperimentController::class, 'show']);
        Route::put('/experiments/{experiment}', [ExperimentController::class, 'update']);
        Route::patch('/experiments/{experiment}', [ExperimentController::class, 'update']);
        Route::delete('/experiments/{experiment}', [ExperimentController::class, 'destroy']);
        Route::get('/experiments/{experiment}/results', [ExperimentController::class, 'results']);

        Route::get('/marketplace/apps', [MarketplaceController::class, 'apps']);
        Route::get('/marketplace/installs', [MarketplaceController::class, 'installs']);
        Route::post('/marketplace/apps/{marketplaceApp}/install', [MarketplaceController::class, 'install']);
        Route::post('/marketplace/installs/{appInstall}/uninstall', [MarketplaceController::class, 'uninstall']);
        Route::post('/marketplace/installs/{appInstall}/rotate-secret', [MarketplaceController::class, 'rotateSecret']);
        Route::post('/marketplace/installs/{appInstall}/webhooks', [MarketplaceController::class, 'saveWebhook']);
        Route::delete('/marketplace/webhooks/{appWebhook}', [MarketplaceController::class, 'destroyWebhook']);
        Route::get('/marketplace/deliveries', [MarketplaceController::class, 'deliveries']);
        Route::post('/marketplace/deliveries/{appWebhookDelivery}/retry', [MarketplaceController::class, 'retryDelivery']);

        Route::get('/telephony/calls', [TelephonyController::class, 'index']);
        Route::post('/telephony/calls/start', [TelephonyController::class, 'start']);
        Route::post('/telephony/calls/{call}/disposition', [TelephonyController::class, 'disposition']);
        Route::get('/telephony/access-token', [TelephonyController::class, 'accessToken']);

        Route::post('/mobile/device-tokens', [DeviceTokenController::class, 'register']);
        Route::delete('/mobile/device-tokens', [DeviceTokenController::class, 'unregister']);

        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::post('/api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke']);
        Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);

        Route::get('/webhooks-inbox', [WebhookInboxController::class, 'index']);
        Route::get('/webhooks-inbox/{webhookInbox}', [WebhookInboxController::class, 'show']);

        Route::get('/inbox', [InboxController::class, 'index']);
        Route::get('/inbox/thread/{threadKey}', [InboxController::class, 'thread']);
        Route::get('/search', [GlobalSearchController::class, 'index']);
        Route::get('/saved-views', [SavedViewController::class, 'index']);
        Route::post('/saved-views', [SavedViewController::class, 'store']);
        Route::patch('/saved-views/{savedView}', [SavedViewController::class, 'update']);
        Route::delete('/saved-views/{savedView}', [SavedViewController::class, 'destroy']);

        Route::get('/consent/events', [ConsentController::class, 'events']);
        Route::get('/consent/preferences', [ConsentController::class, 'preferences']);

        Route::get('/compliance', [ComplianceController::class, 'show']);
        Route::put('/compliance', [ComplianceController::class, 'update']);
        Route::patch('/compliance', [ComplianceController::class, 'update']);
        Route::post('/compliance/country-rules', [ComplianceController::class, 'upsertCountryRule']);
        Route::put('/compliance/country-rules/{countryComplianceRule}', [ComplianceController::class, 'upsertCountryRule']);
        Route::delete('/compliance/country-rules/{countryComplianceRule}', [ComplianceController::class, 'destroyCountryRule']);

        Route::get('/billing/plans', [BillingController::class, 'plans']);
        Route::post('/billing/plans', [BillingController::class, 'savePlan']);
        Route::put('/billing/plans/{billingPlan}', [BillingController::class, 'savePlan']);
        Route::get('/billing/subscription', [BillingController::class, 'subscription']);
        Route::post('/billing/subscription', [BillingController::class, 'saveSubscription']);
        Route::put('/billing/subscription', [BillingController::class, 'saveSubscription']);
        Route::get('/billing/usage', [BillingController::class, 'usage']);
        Route::get('/billing/invoices', [BillingController::class, 'invoices']);
        Route::get('/billing/profitability', [BillingController::class, 'profitability']);
        Route::get('/billing/margin-alerts', [BillingController::class, 'marginAlerts']);
        Route::get('/billing/workspace-analytics', [BillingController::class, 'workspaceAnalytics']);
        Route::get('/billing/workspace-analytics/export', [BillingController::class, 'workspaceAnalyticsExport']);
        Route::post('/billing/invoices/generate', [BillingController::class, 'generateInvoice']);

        Route::get('/integrations', [IntegrationController::class, 'index']);
        Route::post('/integrations/connections', [IntegrationController::class, 'saveConnection']);
        Route::put('/integrations/connections/{integrationConnection}', [IntegrationController::class, 'saveConnection']);
        Route::delete('/integrations/connections/{integrationConnection}', [IntegrationController::class, 'destroyConnection']);
        Route::post('/integrations/events', [IntegrationController::class, 'saveEventSubscription']);
        Route::put('/integrations/events/{integrationEventSubscription}', [IntegrationController::class, 'saveEventSubscription']);
        Route::delete('/integrations/events/{integrationEventSubscription}', [IntegrationController::class, 'destroyEventSubscription']);

        Route::get('/sso', [SsoController::class, 'index']);
        Route::post('/sso/configs', [SsoController::class, 'saveConfig']);
        Route::put('/sso/configs/{tenantSsoConfig}', [SsoController::class, 'saveConfig']);
        Route::post('/sso/scim-tokens', [SsoController::class, 'createScimToken']);
        Route::post('/sso/scim-tokens/{scimAccessToken}/revoke', [SsoController::class, 'revokeScimToken']);

        Route::get('/sandboxes', [SandboxController::class, 'index']);
        Route::post('/sandboxes', [SandboxController::class, 'store']);
        Route::post('/sandboxes/{tenantSandbox}/promote', [SandboxController::class, 'promote']);

        Route::get('/custom-fields', [CustomFieldController::class, 'index']);
        Route::post('/custom-fields', [CustomFieldController::class, 'store']);
        Route::put('/custom-fields/{customField}', [CustomFieldController::class, 'update']);
        Route::delete('/custom-fields/{customField}', [CustomFieldController::class, 'destroy']);

        Route::get('/lead-forms', [LeadFormController::class, 'index']);
        Route::post('/lead-forms', [LeadFormController::class, 'store']);
        Route::put('/lead-forms/{leadForm}', [LeadFormController::class, 'update']);
        Route::delete('/lead-forms/{leadForm}', [LeadFormController::class, 'destroy']);

        Route::get('/workflows/versions', [WorkflowController::class, 'index']);
        Route::post('/workflows/versions/snapshot', [WorkflowController::class, 'snapshot']);
        Route::post('/workflows/versions/{workflowVersion}/request-approval', [WorkflowController::class, 'requestApproval']);
        Route::post('/workflows/approvals/{approvalRequest}/review', [WorkflowController::class, 'review']);
        Route::post('/workflows/versions/{workflowVersion}/publish', [WorkflowController::class, 'publish']);
        Route::post('/workflows/versions/{workflowVersion}/rollback', [WorkflowController::class, 'rollback']);

        Route::get('/approvals', [ApprovalController::class, 'index']);
        Route::get('/approvals/{highRiskApproval}', [ApprovalController::class, 'show']);
        Route::post('/approvals/{highRiskApproval}/review', [ApprovalController::class, 'review']);

        Route::get('/realtime/events', [RealtimeController::class, 'events']);
        Route::get('/campaigns/{campaign}/live-monitor', [RealtimeController::class, 'campaignMonitor']);

        Route::get('/calls', [CallLogController::class, 'index']);
        Route::post('/calls', [CallLogController::class, 'store']);
        Route::post('/calls/{callLog}/complete', [CallLogController::class, 'complete']);

        Route::get('/health', [HealthController::class, 'tenant']);
        Route::get('/tenant-console', [HealthController::class, 'tenantConsole']);

        Route::get('/exports', [ExportController::class, 'index']);
        Route::post('/exports/run', [ExportController::class, 'run']);
        Route::get('/exports/{exportJob}', [ExportController::class, 'download'])->name('admin.exports.download');
        Route::get('/exports/{exportJob}/stream', [ExportController::class, 'stream'])->name('admin.exports.stream');

        Route::post('/lifecycle/archive', [DataLifecycleController::class, 'archive']);
        Route::get('/lifecycle/leads/{lead}/export', [DataLifecycleController::class, 'exportLead']);
        Route::delete('/lifecycle/leads/{lead}', [DataLifecycleController::class, 'deleteLead']);

        Route::post('/ai/campaign-copy', [AiController::class, 'campaignCopy']);
        Route::post('/ai/leads/{lead}/classify', [AiController::class, 'classifyLead']);
        Route::post('/ai/messages/{message}/reply-suggestions', [AiController::class, 'replySuggestions']);
    });

Route::post('/public/leads', [PublicLeadController::class, 'store'])
    ->middleware(['resolve.public_tenant', 'throttle:public-leads']);
Route::get('/public/branding', PublicBrandingController::class)
    ->middleware('resolve.public_tenant');
Route::get('/public/chat/widget', [PublicChatController::class, 'widget'])
    ->middleware(['resolve.public_tenant', 'throttle:public-chat-widget']);
Route::post('/public/chat/message', [PublicChatController::class, 'message'])
    ->middleware(['resolve.public_tenant', 'throttle:public-chat']);
Route::get('/public/portal', [PublicPortalController::class, 'show'])
    ->middleware(['resolve.public_tenant', 'throttle:public-portal']);
Route::post('/public/portal/request-quote', [PublicPortalController::class, 'requestQuote'])
    ->middleware(['resolve.public_tenant', 'throttle:public-portal']);
Route::post('/public/portal/book-demo', [PublicPortalController::class, 'bookDemo'])
    ->middleware(['resolve.public_tenant', 'throttle:public-portal']);
Route::post('/public/portal/upload-documents', [PublicPortalController::class, 'uploadDocuments'])
    ->middleware(['resolve.public_tenant', 'throttle:public-portal-upload']);
Route::get('/public/portal/status/{token}', [PublicPortalController::class, 'status'])
    ->name('public.portal.status')
    ->middleware(['throttle:public-portal-status']);
Route::post('/public/track', [PublicTrackingController::class, 'track'])
    ->middleware('throttle:public-track');
Route::post('/public/identify', [PublicTrackingController::class, 'identify'])
    ->middleware('throttle:public-identify');
Route::get('/public/personalize', [PublicTrackingController::class, 'personalize'])
    ->middleware('throttle:public-track');
Route::post('/public/signup', [PublicSignupController::class, 'signup'])
    ->middleware('throttle:public-signup');

Route::post('/webhooks/email/{provider}', [WebhookController::class, 'email']);
Route::post('/webhooks/sms/{provider}', [WebhookController::class, 'sms']);
Route::match(['get', 'post'], '/webhooks/whatsapp/{provider}', [WebhookController::class, 'whatsapp']);
Route::match(['get', 'post'], '/webhooks/telephony/twilio', [TelephonyWebhookController::class, 'twilio'])
    ->name('webhooks.telephony.twilio');
Route::post('/webhooks/billing/{provider}', [PublicSignupController::class, 'billingWebhook']);

Route::prefix('scim/v2')->middleware('throttle:scim')->group(function () {
    Route::get('/Users', [ScimController::class, 'index']);
    Route::post('/Users', [ScimController::class, 'store']);
    Route::put('/Users/{id}', [ScimController::class, 'update']);
    Route::patch('/Users/{id}', [ScimController::class, 'update']);
    Route::delete('/Users/{id}', [ScimController::class, 'destroy']);
});
