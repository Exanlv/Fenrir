<?php

namespace Exan\Dhp\Websocket\Events;

/**
 * @see https://discord.com/developers/docs/topics/gateway-events#guild-scheduled-event-user-remove
 */
class GuildScheduledEventUserRemove
{
    public string $guild_scheduled_event_id;
    public string $user_id;
    public string $guild_id;
}
