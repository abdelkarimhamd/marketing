<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\ExportJob;
use App\Models\LeadImportSchedule;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\BiExportService;
use App\Services\HealthMetricsService;
use App\Services\LeadImportService;
use App\Services\RetentionService;
use App\Services\TenantDomainManager;
use Database\Seeders\RegressionStagingSeeder;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('tenants:domains:sync {--tenant_id=} {--host=}', function (TenantDomainManager $manager) {
    $query = TenantDomain::query()->withoutTenancy();

    if (is_numeric($this->option('tenant_id')) && (int) $this->option('tenant_id') > 0) {
        $query->where('tenant_id', (int) $this->option('tenant_id'));
    }

    if (is_string($this->option('host')) && trim((string) $this->option('host')) !== '') {
        $query->where('host', strtolower(trim((string) $this->option('host'))));
    }

    $domains = $query->orderBy('tenant_id')->orderBy('kind')->orderBy('host')->get();

    if ($domains->isEmpty()) {
        $this->info('No tenant domains matched.');

        return 0;
    }

    foreach ($domains as $domain) {
        $updated = $manager->verify($domain);

        $this->line(sprintf(
            '[tenant:%d] %s (%s) => verification=%s ssl=%s',
            $updated->tenant_id,
            $updated->host,
            $updated->kind,
            $updated->verification_status,
            $updated->ssl_status,
        ));
    }

    $this->info('Tenant domain sync completed.');

    return 0;
})->purpose('Validate tenant domain CNAME records and trigger SSL automation.');

Artisan::command('tenants:retention:archive {--tenant_id=} {--months=}', function (RetentionService $retentionService) {
    $query = Tenant::query();

    if (is_numeric($this->option('tenant_id')) && (int) $this->option('tenant_id') > 0) {
        $query->whereKey((int) $this->option('tenant_id'));
    }

    $tenants = $query->where('is_active', true)->get();

    if ($tenants->isEmpty()) {
        $this->info('No tenants matched.');
        return 0;
    }

    $months = is_numeric($this->option('months')) ? (int) $this->option('months') : null;

    foreach ($tenants as $tenant) {
        $result = $retentionService->archiveForTenant($tenant, $months);
        $this->line(sprintf(
            '[tenant:%d] archived messages=%d webhooks=%d deleted_attachments=%d',
            $tenant->id,
            $result['archived_messages'],
            $result['archived_webhooks'],
            $result['deleted_attachments'] ?? 0,
        ));
    }

    $this->info('Retention archive completed.');
    return 0;
})->purpose('Archive old tenant logs and remove expired attachments by retention policy.');

Artisan::command('tenants:health:snapshot {--tenant_id=}', function (HealthMetricsService $healthService) {
    $query = Tenant::query();

    if (is_numeric($this->option('tenant_id')) && (int) $this->option('tenant_id') > 0) {
        $query->whereKey((int) $this->option('tenant_id'));
    }

    $tenants = $query->where('is_active', true)->get();

    if ($tenants->isEmpty()) {
        $this->info('No tenants matched.');
        return 0;
    }

    foreach ($tenants as $tenant) {
        $snapshot = $healthService->storeDailySnapshot($tenant);
        $this->line(sprintf(
            '[tenant:%d] health score=%s date=%s',
            $tenant->id,
            $snapshot->health_score,
            $snapshot->snapshot_date,
        ));
    }

    $this->info('Health snapshots completed.');
    return 0;
})->purpose('Compute and persist tenant health metrics daily.');

Artisan::command('exports:run-scheduled', function (BiExportService $exportService) {
    $jobs = ExportJob::query()
        ->withoutTenancy()
        ->whereNotNull('schedule_cron')
        ->where(function ($query) {
            $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
        })
        ->where('status', '!=', 'running')
        ->limit(100)
        ->get();

    if ($jobs->isEmpty()) {
        $this->info('No scheduled exports due.');
        return 0;
    }

    foreach ($jobs as $job) {
        if (! is_numeric($job->tenant_id) || (int) $job->tenant_id <= 0) {
            continue;
        }

        $completed = $exportService->exportToCsv((int) $job->tenant_id, (string) $job->type);

        $job->forceFill([
            'status' => $completed->status,
            'file_path' => $completed->file_path,
            'last_run_at' => now(),
            'next_run_at' => now()->addDay(),
            'completed_at' => now(),
        ])->save();

        $this->line(sprintf(
            '[export:%d] tenant=%d type=%s file=%s',
            $job->id,
            $job->tenant_id,
            $job->type,
            $job->file_path,
        ));
    }

    $this->info('Scheduled exports completed.');
    return 0;
})->purpose('Run scheduled BI/report export jobs.');

Artisan::command('imports:run-scheduled', function (LeadImportService $leadImportService) {
    $schedules = LeadImportSchedule::query()
        ->withoutTenancy()
        ->with('preset')
        ->where('is_active', true)
        ->where(function ($query) {
            $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
        })
        ->whereNotNull('schedule_cron')
        ->limit(100)
        ->get();

    if ($schedules->isEmpty()) {
        $this->info('No scheduled lead imports due.');
        return 0;
    }

    foreach ($schedules as $schedule) {
        $result = $leadImportService->runSchedule($schedule);

        $this->line(sprintf(
            '[import:%d] tenant=%d status=%s created=%d updated=%d merged=%d skipped=%d',
            $schedule->id,
            $schedule->tenant_id,
            $result['status'] ?? 'unknown',
            (int) ($result['created_count'] ?? 0),
            (int) ($result['updated_count'] ?? 0),
            (int) ($result['merged_count'] ?? 0),
            (int) ($result['skipped_count'] ?? 0),
        ));
    }

    $this->info('Scheduled lead imports completed.');
    return 0;
})->purpose('Run scheduled lead import jobs from URL/SFTP sources.');

Artisan::command('qa:seed-regression-staging {--fresh}', function () {
    if ((bool) $this->option('fresh')) {
        $this->warn('Running migrate:fresh --seed for regression staging...');
        $this->call('migrate:fresh', ['--force' => true]);
    }

    $this->call('db:seed', [
        '--class' => RegressionStagingSeeder::class,
        '--force' => true,
    ]);

    $this->info('Regression staging dataset is ready.');
    $this->line('Tenants: 3 | Users per tenant: 10 | Leads per tenant: 5000 | Brands total: 5');

    return 0;
})->purpose('Seed deterministic multi-tenant QA dataset for Task 51 stability gate.');
