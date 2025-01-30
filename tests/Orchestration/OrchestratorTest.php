<?php

declare(strict_types=1);

namespace Tests\Ragnarok\Fenrir\Orchestration;

use Fakes\Ragnarok\Fenrir\LoopFake;
use Fakes\Ragnarok\Fenrir\PromiseFake;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Ragnarok\Fenrir\Orchestration\Orchestrator;
use Ragnarok\Fenrir\Parts\GatewayBot;
use Ragnarok\Fenrir\Parts\SessionStartLimit;
use Ragnarok\Fenrir\Rest\Gateway;

class OrchestratorTest extends TestCase
{
    public function testItOrchestrates()
    {
        $loop = new LoopFake();
        /** @var Gateway&MockInterface */
        $gateway = Mockery::mock(Gateway::class);

        $orchestrator = new Orchestrator($loop, $gateway);

        $gatewayBot = new GatewayBot();
        $gatewayBot->url = '::url::';
        $gatewayBot->shards = 15;
        $gatewayBot->session_start_limit = new SessionStartLimit();
        $gatewayBot->session_start_limit->total = 18;
        $gatewayBot->session_start_limit->remaining = 15;
        $gatewayBot->session_start_limit->reset_after = 12321323123123123;
        $gatewayBot->session_start_limit->max_concurrency = 5;

        $gateway->shouldReceive()
            ->getBot()
            ->andReturns(PromiseFake::get($gatewayBot))
            ->once();

        $spawned = [];
        $orchestrator->on(Orchestrator::ALLOW_SPAWN, function (int $shard, int $totalShards) use (&$spawned) {
            $spawned[] = [$shard, $totalShards];
        });

        $orchestrator->init();

        $this->assertEquals([
            [0, 15],
            [1, 15],
            [2, 15],
            [3, 15],
            [4, 15],
        ], $spawned);

        $spawned = [];
        $loop->runTimers(Orchestrator::CONCURRENCY_TIMESPAN_SECONDS);

        $this->assertEquals([
            [5, 15],
            [6, 15],
            [7, 15],
            [8, 15],
            [9, 15],
        ], $spawned);

        $spawned = [];
        $loop->runTimers(Orchestrator::CONCURRENCY_TIMESPAN_SECONDS);

        $this->assertEquals([
            [10, 15],
            [11, 15],
            [12, 15],
            [13, 15],
            [14, 15],
        ], $spawned);

        $spawned = [];
        $loop->runTimers(null);

        dd($spawned);
    }
}
