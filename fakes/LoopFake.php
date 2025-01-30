<?php

declare(strict_types=1);

namespace Fakes\Ragnarok\Fenrir;

use Ragnarok\Fenrir\DataMapper;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

class LoopFake implements LoopInterface
{
    private array $timers = [];

    public function addReadStream($stream, $listener)
    {
    }

    public function addWriteStream($stream, $listener)
    {
    }

    public function removeReadStream($stream)
    {
    }

    public function removeWriteStream($stream)
    {
    }

    public function addTimer($interval, $callback)
    {
        $this->timers[] = ['seconds' => $interval, 'callback' => $callback];
    }

    public function runTimers(?int $seconds = null)
    {
        $timers = $this->timers;
        $this->timers = [];

        if ($seconds === null) {
            foreach ($timers as $i => $timer) {
                $timer['callback']();
            }

            return;
        }

        foreach ($timers as $i => &$timer) {
            $timer['seconds'] -= $seconds;

            if ($timer['seconds'] <= 0) {
                $timer['callback']();
                unset($timers[$i]);
            }
        }

        $this->timers = [...$timers, ...$this->timers];
    }

    public function addPeriodicTimer($interval, $callback)
    {
    }

    public function cancelTimer(TimerInterface $timer)
    {
    }

    public function futureTick($listener)
    {
    }

    public function addSignal($signal, $listener)
    {
    }

    public function removeSignal($signal, $listener)
    {
    }

    public function run()
    {
    }

    public function stop()
    {
    }
}
