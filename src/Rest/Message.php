<?php

declare(strict_types=1);

namespace Exan\Dhp\Rest;

use Discord\Http\Endpoint;
use Discord\Http\Http;
use Exan\Dhp\Rest\Helpers\MessageBuilder;

class Message
{
    public function __construct(private Http $http)
    {
    }

    public function send(string $channelId, MessageBuilder $message)
    {
        if ($message->requiresMultipart()) {
            $multipart = $message->getMultipart();

            $body = $multipart->getBody();
            $headers = $multipart->getHeaders($body);

            return $this->http->post(
                Endpoint::bind(
                    Endpoint::CHANNEL_MESSAGES,
                    $channelId
                ),
                $body . "\n",
                $headers
            );
        }

        return $this->http->post(
            Endpoint::bind(
                Endpoint::CHANNEL_MESSAGES,
                $channelId
            ),
            $message->get()
        );
    }
}
