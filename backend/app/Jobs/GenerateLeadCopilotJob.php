<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\CopilotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateLeadCopilotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $leadId,
        public ?int $requestedBy = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(CopilotService $service): void
    {
        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->leadId)
            ->first();

        if (! $lead instanceof Lead) {
            return;
        }

        $service->generateNow($lead, $this->requestedBy);
    }
}
