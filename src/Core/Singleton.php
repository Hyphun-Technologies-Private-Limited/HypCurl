<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Core;

/**
 * Reusable singleton behaviour. One instance per concrete class:
 * JsonPlaceholder::instance() and HttpBin::instance() are separate singletons.
 */
trait Singleton
{
    /** @var array<class-string, static> */
    private static array $instances = [];

    public static function instance(): static
    {
        return self::$instances[static::class] ??= new static();
    }

    /** Drop the cached instance (useful in tests or after changing config). */
    public static function reset(): void
    {
        unset(self::$instances[static::class]);
    }

    /**
     * Drop every instance stored by the class that uses this trait, including subclasses
     * (ApiClient::resetAll() forgets all API clients).
     */
    public static function resetAll(): void
    {
        self::$instances = [];
    }

    private function __clone()
    {
    }

    public function __wakeup(): void
    {
        throw new \LogicException('Cannot unserialize a singleton.');
    }
}
