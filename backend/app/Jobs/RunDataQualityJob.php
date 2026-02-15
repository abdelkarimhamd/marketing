<?php

namespace App\Jobs;

use App\Models\DataQualityRun;
use App\Services\DataQualityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunDataQualityJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
        $this->onQueue('data-quality');
    }

    public function handle(DataQualityService $service): void
    {
        $run = DataQualityRun::query()
            ->withoutTenancy()
            ->whereKey($this->runId)
            ->first();

        if (! $run instanceof DataQualityRun) {
            return;
        }

        $service->executeRun($run);
    }
}
