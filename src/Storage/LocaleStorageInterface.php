<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Storage;

interface LocaleStorageInterface
{
    public function getName(): string;

    public function set(string $value): void;

    public function get(): ?string;
}
