<?php

declare(strict_types=1);

namespace Ragnarok\Fenrir\Orchestration;

use DateTime;
use Evenement\EventEmitter;
use Ragnarok\Fenrir\Parts\GatewayBot;
use Ragnarok\Fenrir\Parts\SessionStartLimit;
use Ragnarok\Fenrir\Rest\Gateway;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

class Orchestrator extends EventEmitter
{
    public const ALLOW_SPAWN = 'allow_spawn';

    public const CONCURRENCY_TIMESPAN_SECONDS = 5;

    /**
     * @var int[]
     */
    private array $shards;

    private int $totalShards;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Gateway $gateway,
        private readonly ?int $totalShardsOverride = null
    ) {
    }

    /**
     * @return PromiseInterface<null>
     */
    public function init(): PromiseInterface
    {
        return $this->gateway->getBot()->then(function (GatewayBot $bot) {
            $this->totalShards = $this->totalShardsOverride ?? $bot->shards;

            $this->shards = range(
                0,
                $this->totalShards - 1
            );

            return $this->orchestrate($bot->session_start_limit);
        });
    }

    /**
     * @return PromiseInterface<null>
     */
    private function orchestrate(SessionStartLimit $sessionStartLimits): PromiseInterface
    {
        return $this->startSpawning(
            $sessionStartLimits->remaining,
            $sessionStartLimits->max_concurrency,
            (new DateTime())->setTimestamp($sessionStartLimits->reset_after),
        )->then(function () use ($sessionStartLimits) {
            $wait = max(
                $sessionStartLimits->reset_after - (new DateTime())->getTimestamp(),
                1
            );

            return new Promise(function (callable $resolve) use ($wait) {
                $this->loop->addTimer(
                    $wait,
                    fn () => $this->continueOrchestration()->then(fn () => $resolve(null))
                );
            });
        });
    }

    /**
     * @return PromiseInterface<null>
     */
    private function continueOrchestration(): PromiseInterface
    {
        return new Promise(function (callable $resolve) {
            if (!empty($this->shards)) {
                return $this->gateway->getBot()->then(
                    fn (GatewayBot $bot) => $this->orchestrate($bot->session_start_limit)
                        ->then(fn () => $resolve(null))
                );
            }
        });
    }

    /**
     * @return PromiseInterface<null>
     */
    private function startSpawning(int $remaining, int $concurrency, DateTime $resetAfter): PromiseInterface
    {
        return new Promise(function (callable $resolve) use ($remaining, $concurrency, $resetAfter) {
            $currentDate = new DateTime();

            if ($currentDate > $resetAfter) {
                $resolve(null);

                return;
            }

            if ($remaining === 0) {
                $resolve(null);
                return;
            }

            $shardsToSpawn = $remaining > $concurrency
                ? $concurrency
                : $remaining;

            $remaining = max($remaining - $concurrency, 0);

            $shards = array_splice($this->shards, 0, $shardsToSpawn);

            $this->allowSpawning($shards);

            $this->loop->addTimer(self::CONCURRENCY_TIMESPAN_SECONDS, function () use ($remaining, $concurrency, $resetAfter, $resolve) {
                $this->startSpawning($remaining, $concurrency, $resetAfter)->then(fn () => $resolve());
            });
        });
    }

    /**
     * @param int[] $shards
     */
    private function allowSpawning(array $shards): void
    {
        foreach ($shards as $shard) {
            $this->emit(self::ALLOW_SPAWN, [$shard, $this->totalShards]);
        }
    }
}
