<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\SubscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('financial:process-subscriptions')]
#[Description('Processes active subscriptions that reached their billing day to discount credit card limits.')]
class ProcessDueSubscriptionsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SubscriptionService $subscriptionService): int
    {
        $totalProcessed = 0;

        Workspace::chunk(100, function ($workspaces) use ($subscriptionService, &$totalProcessed): void {
            foreach ($workspaces as $workspace) {
                $totalProcessed += $subscriptionService->processDueSubscriptions($workspace);
            }
        });

        $this->info("Successfully processed {$totalProcessed} subscription(s) for the current cycle.");

        return Command::SUCCESS;
    }
}
