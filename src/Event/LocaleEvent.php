<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Event;

final class LocaleEvent
{
    public function __construct(private string $locale)
    {
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}
