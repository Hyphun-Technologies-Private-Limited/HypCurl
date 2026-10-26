<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Core;

/**
 * Turns "Call to undefined method X::foo()" into a helpful BadMethodCallException:
 * suggests close method names, and points out properties called like methods.
 */
trait HelpfulCalls
{
    public function __call(string $name, array $arguments): never
    {
        $class = (new \ReflectionClass($this))->getShortName();
        $methods = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            array_filter(
                (new \ReflectionClass($this))->getMethods(\ReflectionMethod::IS_PUBLIC),
                fn (\ReflectionMethod $m) => !$m->isStatic() && !str_starts_with($m->getName(), '__'),
            ),
        );

        $message = "$class has no method $name().";
        if (property_exists($this, $name) && (new \ReflectionProperty($this, $name))->isPublic()) {
            $message .= " '$name' is a property: use ->$name without parentheses.";
        } else {
            // getBody() -> body(), getStatusCode() -> status(), isOk() -> ok()
            $bare = strtolower((string) preg_replace('/^(get|is|has)(?=[A-Z])/', '', $name));
            $bare = (string) preg_replace('/code$/', '', $bare);
            $suggestions = array_values(array_filter(
                $methods,
                fn (string $m) => levenshtein(strtolower($name), strtolower($m)) <= 2
                    || levenshtein($bare, strtolower($m)) <= 1
                    || (strlen($name) > 3 && str_contains(strtolower($m), strtolower($name))),
            ));
            if ($suggestions !== []) {
                $message .= ' Did you mean ' . implode(' or ', array_map(fn ($m) => "$m()", array_slice($suggestions, 0, 3))) . '?';
            }
        }
        sort($methods);
        throw new \BadMethodCallException($message . " Available: " . implode(', ', $methods) . '.');
    }
}
