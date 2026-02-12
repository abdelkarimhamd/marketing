<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\Admin\ApiKeyController;
use App\Http\Controllers\Api\Admin\AssignmentRuleController;
use App\Http\Controllers\Api\Admin\CampaignController;
use App\Http\Controllers\Api\Admin\CampaignLogController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\LeadController;
use App\Http\Controllers\Api\Admin\LeadActivityController;
use App\Http\Controllers\Api\Admin\SegmentController;
use App\Http\Controllers\Api\Admin\TenantController;
use App\Http\Controllers\Api\Admin\TenantSettingsController;
use App\Http\Controllers\Api\Admin\TemplateController;
use App\Http\Controllers\Api\Admin\WebhookInboxController;
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

        Route::get('/leads', [LeadController::class, 'index']);
        Route::post('/leads', [LeadController::class, 'store']);
        Route::post('/leads/import', [LeadController::class, 'import']);
        Route::post('/leads/bulk', [LeadController::class, 'bulk']);
        Route::get('/leads/{lead}', [LeadController::class, 'show']);
        Route::get('/leads/{lead}/activities', [LeadActivityController::class, 'index']);
        Route::put('/leads/{lead}', [LeadController::class, 'update']);
        Route::patch('/leads/{lead}', [LeadController::class, 'update']);
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);

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

        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::post('/api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke']);
        Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);

        Route::get('/webhooks-inbox', [WebhookInboxController::class, 'index']);
        Route::get('/webhooks-inbox/{webhookInbox}', [WebhookInboxController::class, 'show']);
    });

Route::post('/public/leads', [PublicLeadController::class, 'store'])
    ->middleware(['resolve.public_tenant', 'throttle:public-leads']);

Route::post('/webhooks/email/{provider}', [WebhookController::class, 'email']);
Route::post('/webhooks/sms/{provider}', [WebhookController::class, 'sms']);
Route::match(['get', 'post'], '/webhooks/whatsapp/{provider}', [WebhookController::class, 'whatsapp']);
