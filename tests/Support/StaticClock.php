<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class StaticClock implements ClockInterface
{
    public function __construct(
        private readonly DateTimeImmutable $now,
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
