<?php

namespace App\Console\Commands;

use App\Models\FixedBill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('financial:check-due-bills')]
#[Description('Checks fixed bills approaching due date and prepares/sends reminders.')]
class CheckDueBillsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = now();
        $dueBillsCount = 0;

        $bills = FixedBill::with(['workspace.members'])
            ->where('is_active', true)
            ->where('is_reminder_active', true)
            ->get();

        foreach ($bills as $bill) {
            $daysAhead = $bill->reminder_days_before;
            $notificationDate = (clone $today)->addDays($daysAhead);

            $isDueToday = (int) $today->day === (int) $bill->due_day;
            $isApproaching = (int) $notificationDate->day === (int) $bill->due_day;

            if ($isDueToday || $isApproaching) {
                $dueBillsCount++;
                $message = sprintf(
                    'Reminder: Bill "%s" ($%0.2f) is due on day %d. Workspace: "%s".',
                    $bill->name,
                    $bill->estimated_amount,
                    $bill->due_day,
                    $bill->workspace->name
                );

                $this->info($message);
                Log::info($message);
            }
        }

        $this->info("Check completed: {$dueBillsCount} fixed bill(s) identified for reminder.");

        return Command::SUCCESS;
    }
}
