<?php

declare(strict_types=1);

namespace Ragnarok\Fenrir;

use Closure;
use Evenement\EventEmitter;
use Fakes\Ragnarok\Fenrir\DataMapperFake;
use Fakes\Ragnarok\Fenrir\PromiseFake;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery\CompositeExpectation;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ragnarok\Fenrir\Bitwise\Bitwise;
use Ragnarok\Fenrir\Constants\Events;
use Ragnarok\Fenrir\Gateway\Connection;
use Ragnarok\Fenrir\Gateway\Objects\Payload;
use Ragnarok\Fenrir\Gateway\Puppet;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\ExtendedPromiseInterface;

use function React\Async\await;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ConnectionTest extends MockeryTestCase
{
    private Puppet&MockInterface $puppet;
    private DataMapper $dataMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->puppet = Mockery::mock('overload:Ragnarok\Fenrir\Gateway\Puppet');
        $this->dataMapper = DataMapperFake::get();
    }
    private function getConnection(
        ?LoopInterface $loopInterface = null,
        string $token = '::token::',
        Bitwise $intents = new Bitwise(),
        DataMapper $dataMapper = new DataMapper(new NullLogger()),
        LoggerInterface $logger = new NullLogger(),
        int $timeout = 10,
    ): Connection {
        $loopInterface ??= Mockery::mock(LoopInterface::class);

        return new Connection(
            $loopInterface,
            $token,
            $intents,
            $dataMapper,
            $logger,
            $timeout
        );
    }

    private function expectConnect($url = Connection::DEFAULT_WEBSOCKET_URL, ?ExtendedPromiseInterface $return = null): CompositeExpectation
    {
        $return ??= PromiseFake::get();

        return $this->puppet->expects()
            ->connect()
            ->with($url)
            ->andReturn($return);
    }

    private function expectIdentify($token, Bitwise $intents): CompositeExpectation
    {
        return $this->puppet->expects()
            ->identify()
            ->with($token, $intents);
    }

    private function expectHeartbeat(?string $sequence = null): CompositeExpectation
    {
        return $this->puppet->expects()
            ->sendHeartBeat()
            ->with($sequence);
    }

    private function expectResume(string $token, string $sessionId, ?int $sequence = null): CompositeExpectation
    {
        return $this->puppet->expects()
            ->resume()
            ->with($token, $sessionId, $sequence);
    }

    private function expectReconnectTimer(LoopInterface&MockInterface $loop, TimerInterface $return): CompositeExpectation
    {
        return $loop->expects()
            ->addTimer()
            ->with(0.5, Mockery::on(fn () => true))
            ->andReturns($return);
    }

    private function expectHeartbeatTimer(LoopInterface&MockInterface $loop, float $time, bool $executeImmediately = false, ?TimerInterface $return = null): CompositeExpectation
    {
        $return ??= Mockery::mock(TimerInterface::class);

        return $loop->expects()
            ->addPeriodicTimer()
            ->with($time, Mockery::on(function ($fn) use ($executeImmediately) {
                if ($executeImmediately) {
                    $fn();
                }

                return true;
            }))
            ->andReturns($return);
    }

    private function sendPayload(EventEmitter $emitter, array $payload)
    {
        $emitter->emit(
            $payload['op'],
            [$this->dataMapper->map((object) $payload, Payload::class)]
        );
    }

    private function expectTerminate(int $code, string $reason = '')
    {
        return $this->puppet->expects()
            ->terminate()
            ->with($code, $reason);
    }


    public function testItOpensAConnection()
    {
        $this->expectConnect()->once();

        $intents = new Bitwise(123);
        $this->expectIdentify('::token::', $intents)->once();

        /** @var LoopInterface&MockInterface */
        $loop = Mockery::mock(LoopInterface::class);
        $this->expectHeartbeatTimer($loop, 1.234)->once();

        $connection = $this->getConnection($loop, intents: $intents);
        $connection->open();

        $this->sendPayload($connection->raw, [
            'op' => 10,
            'd' => (object) [
                'heartbeat_interval' => 1234,
            ]
        ]);
    }

    public function testItDoesNotReconnectIfHeartbeatAcknowledged()
    {
        $this->expectConnect()->once();

        $intents = new Bitwise(123);
        $this->expectIdentify('::token::', $intents)->once();

        /** @var LoopInterface&MockInterface */
        $loop = Mockery::mock(LoopInterface::class);
        $this->expectHeartbeatTimer($loop, 1.234, true);
        $this->expectHeartbeat();

        $timerInterface = Mockery::mock(TimerInterface::class);
        $this->expectReconnectTimer($loop, $timerInterface);

        $connection = $this->getConnection($loop, intents: $intents);
        $connection->open();

        $this->sendPayload($connection->raw, [
            'op' => 10,
            'd' => (object) [
                'heartbeat_interval' => 1234,
            ]
        ]);

        $loop->expects()
            ->cancelTimer($timerInterface)
            ->once();

        $this->sendPayload($connection->raw, [
            'op' => 11,
        ]);
    }

    public function testItResumes()
    {
        $this->expectConnect()->once();
        $this->expectTerminate(1004, 'reconnecting');
        $promise = PromiseFake::get();
        $this->expectConnect('::reconnect url::', $promise)->once();
        $this->expectResume('::token::', '::session id::', 1);

        $intents = new Bitwise(123);
        $this->expectIdentify('::token::', $intents)->once();

        /** @var LoopInterface&MockInterface */
        $loop = Mockery::mock(LoopInterface::class);
        $timer = Mockery::mock(TimerInterface::class);
        $this->expectHeartbeatTimer($loop, 1.234, return: $timer)->once();
        $this->expectHeartbeatTimer($loop, 5.678)->once();

        $connection = $this->getConnection($loop, intents: $intents);
        $connection->open();

        $this->sendPayload($connection->raw, [
            'op' => 10,
            'd' => (object) [
                'heartbeat_interval' => 1234,
            ],
        ]);

        $this->sendPayload($connection->raw, [
            'op' => 0,
            's' => 1,
            't' => Events::READY,
            'd' => (object) [
                'resume_gateway_url' => '::reconnect url::',
                'session_id' => '::session id::',
            ],
        ]);

        $loop->expects()
            ->cancelTimer()
            ->with($timer)
            ->once();

        $this->sendPayload($connection->raw, [
            'op' => 7
        ]);

        await($promise);

        $this->sendPayload($connection->raw, [
            'op' => 10,
            'd' => (object) [
                'heartbeat_interval' => 5678,
            ],
        ]);
    }

    // public function testItInitializesANewConnectionOnResumeFailure()
    // {
    // }

    // public function testItResumesOnInvalidSession()
    // {
    // }

    // public function testItReconnectsOnInvalidSession()
    // {
    // }

    // public function testItResumesIfNoHeartbeatsAreAcknowledged()
    // {
    // }

    // public function testItReconnectsIfTheConnectionIsKilled()
    // {
    // }

    public function testItSendsAForcedHeartbeat()
    {
        $this->expectConnect()->once();

        $intents = new Bitwise(123);
        $this->expectIdentify('::token::', $intents)->once();

        /** @var LoopInterface&MockInterface */
        $loop = Mockery::mock(LoopInterface::class);
        $this->expectHeartbeatTimer($loop, 1.234)->once();

        $this->expectHeartbeat(null)->once();
        $this->expectHeartbeat('1')->once();

        $connection = $this->getConnection($loop, intents: $intents);
        $connection->open();

        $this->sendPayload($connection->raw, [
            'op' => 10,
            'd' => (object) [
                'heartbeat_interval' => 1234,
            ]
        ]);

        $this->sendPayload($connection->raw, [
            'op' => 1,
        ]);

        $this->sendPayload($connection->raw, [
            'op' => 0,
            's' => 1,
            't' => Events::CHANNEL_CREATE,
            'd' => (object) [],
        ]);

        $this->sendPayload($connection->raw, [
            'op' => 1,
        ]);
    }
}
