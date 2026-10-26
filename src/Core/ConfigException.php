<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Core;

/**
 * A HypCurl setup mistake (unknown option, misplaced key, missing CA file, ...).
 * Extends InvalidArgumentException, so existing catches keep working; JSON-ready like HttpException.
 */
final class ConfigException extends \InvalidArgumentException implements \JsonSerializable
{
    public function __construct(string $message, public readonly ?string $hint = null)
    {
        parent::__construct($message);
    }

    /**
     * Throw if $given has keys outside $allowed, with "did you mean" suggestions.
     *
     * @param list<string>          $allowed
     * @param array<string, string> $misplaced key => where it belongs instead
     */
    public static function assertKnownKeys(array $given, array $allowed, string $what, array $misplaced = []): void
    {
        $unknown = array_values(array_diff(array_keys($given), $allowed));
        if ($unknown === []) {
            return;
        }
        $notes = [];
        foreach ($unknown as $key) {
            if (isset($misplaced[$key])) {
                $notes[] = "'$key' $misplaced[$key]";
            } elseif (($guess = self::closest((string) $key, $allowed)) !== null) {
                $notes[] = "'$key': did you mean '$guess'?";
            }
        }
        $list = implode(', ', array_map(fn ($k) => "'$k'", $unknown));
        throw new self(
            "Unknown $what option(s): $list." . ($notes !== [] ? ' ' . implode(' ', $notes) : ''),
            'Allowed: ' . implode(', ', $allowed) . '.',
        );
    }

    public function toArray(bool $debug = true): array
    {
        // The message describes a setup mistake in code (no secrets), so it is shown even without debug.
        $error = ['type' => 'config_error', 'message' => $this->getMessage()];
        if ($debug && $this->hint !== null) {
            $error['hint'] = $this->hint;
        }

        return ['success' => false, 'error' => $error];
    }

    public function toJson(bool $debug = true, int $flags = JSON_PRETTY_PRINT): string
    {
        return (string) json_encode($this->toArray($debug), $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param list<string> $options */
    private static function closest(string $key, array $options): ?string
    {
        $best = null;
        $bestDistance = 3; // only suggest close matches
        foreach ($options as $option) {
            $distance = levenshtein(strtolower($key), strtolower($option));
            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$option, $distance];
            }
        }

        return $best;
    }
}
