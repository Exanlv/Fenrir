<?php

declare(strict_types=1);

namespace Ragnarok\Fenrir\Enums;

enum DefaultMessageNotifications: int
{
    case ALL_MESSAGES = 0;
    case ONLY_MENTIONS = 1;
}
