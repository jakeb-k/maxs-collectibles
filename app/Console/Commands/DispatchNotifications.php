<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationDelivery;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;

class DispatchNotifications extends Command
{
    protected $signature = 'notifications:dispatch {--delivery= : Restrict to a durable delivery ID} {--retry : Reset an exhausted failed delivery for operator recovery}';
    protected $description = 'Queue committed pending/retryable deliveries; recover expired queue/send leases';

    public function handle(): int
    {
        if ($this->option('retry') && !$this->option('delivery')) { $this->error('--retry requires --delivery.'); return self::FAILURE; }
        $query = NotificationDelivery::query()->where('state', '!=', 'sent');
        if ($id = $this->option('delivery')) $query->whereKey($id);
        $count = 0;
        $query->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $row) if (SendNotificationDelivery::enqueue($row->id, (bool) $this->option('retry'))) $count++;
        });
        $this->info('Queued '.$count.' notification delivery jobs.');
        return self::SUCCESS;
    }
}
