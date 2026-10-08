<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\WooCommerce\Queue;
use OneTrace\WooCommerce\Tests\TestCase;

final class QueueTest extends TestCase
{
    public function testKeepsMessagesForARetryWhileThePlatformIsUnavailable(): void
    {
        Queue::event(['type' => 'track', 'userId' => '1', 'event' => 'x']);
        $this->platform->push(503, ['message' => 'down']);

        self::assertSame(['sent' => 0, 'retried' => 1, 'dropped' => 0], $this->flush());
        self::assertSame(['waiting' => 1, 'retrying' => 1], Queue::stats());
        self::assertSame(['sent' => 0, 'retried' => 0, 'dropped' => 0], $this->flush(), 'Not before the pause ends.');
    }

    public function testDropsOnlyTheInvalidMessageOfABatch(): void
    {
        Queue::event(['type' => 'track', 'userId' => '1', 'event' => 'good']);
        Queue::event(['type' => 'track', 'userId' => '1', 'event' => 'bad']);
        $this->platform->responder = static function (Request $request): ?Response {
            return strpos((string) $request->getBody(), '"bad"') !== false ? new Response(422, ['content-type' => 'application/json'], '{"message":"invalid","errors":{}}') : null;
        };

        self::assertSame(['sent' => 1, 'retried' => 0, 'dropped' => 1], $this->flush());
        self::assertSame(['waiting' => 0, 'retrying' => 0], Queue::stats());
    }

    public function testPausesSendingWhenTheKeyIsRejected(): void
    {
        Queue::event(['type' => 'track', 'userId' => '1', 'event' => 'x']);
        $this->platform->push(401, ['message' => 'Invalid key']);

        $this->flush();

        self::assertIsString(get_option(Queue::PAUSED_OPTION));
        self::assertSame(1, Queue::stats()['waiting'], 'Nothing is lost while the settings are fixed.');
        self::assertSame(['sent' => 0, 'retried' => 0, 'dropped' => 0], $this->flush());
    }
}
