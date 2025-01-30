<?php

declare(strict_types=1);

namespace Ragnarok\Fenrir\Parts;

class SessionStartLimit
{
    public int $total;
    public int $remaining;
    public int $reset_after;
    public int $max_concurrency;
}
