<?php

namespace ItZRealMoritz\ResourcePackHosting\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Restarts a server after a new resource pack was uploaded, either right away or at a scheduled time.
 */
class RestartServerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public Server $server) {}

    public function handle(DaemonServerRepository $repository): void
    {
        if ($this->server->isSuspended() || !$this->server->isInstalled()) {
            return;
        }

        $repository->setServer($this->server)->power('restart');
    }
}
