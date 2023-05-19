<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Storage;

use Yiisoft\Session\SessionInterface;

final class SessionLocaleStorage implements LocaleStorageInterface
{
    public function __construct(
        private SessionInterface $session,
        private string $itemName,
    )
    {
    }

    public function getName(): string
    {
        return 'session';
    }

    public function set(string $value): void
    {
        $this->session->set($this->itemName, $value);
    }

    public function get(): ?string
    {
        return null;
    }
}
