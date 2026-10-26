<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

/**
 * The JSON error handler is global, so it is tested in separate PHP processes.
 */
final class JsonErrorHandlerProcessTest extends TestCase
{
    /** @return array{0: int, 1: string} exit code, combined output */
    private function runScript(string $body): array
    {
        $file = tempnam(sys_get_temp_dir(), 'hcjs') . '.php';
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents($file, "<?php\nrequire $autoload;\nuse Hyphun\\HypCurl\\HypCurl;\n$body\n");
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d log_errors=0 ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        unlink($file);

        return [$code, implode("\n", $out)];
    }

    public function testNonHypCurlErrorIsReportedNormallyWithoutRecursion(): void
    {
        // Regression: this used to loop until "Maximum call stack size ... reached".
        [$code, $out] = $this->runScript("HypCurl::boot(['json_errors' => true]);\nundefined_function_xyz();");

        $this->assertSame(255, $code);
        $this->assertStringContainsString('Uncaught Error: Call to undefined function undefined_function_xyz()', $out);
        $this->assertStringNotContainsString('Maximum call stack', $out);
        $this->assertStringNotContainsString('"success"', $out);
    }

    public function testPreviousHandlerStillReceivesOtherExceptions(): void
    {
        [$code, $out] = $this->runScript(
            "set_exception_handler(function (Throwable \$e) { echo 'APP HANDLER: ', \$e->getMessage(); });\n"
            . "HypCurl::boot(['json_errors' => true]);\n"
            . "throw new RuntimeException('boom');",
        );

        $this->assertSame(0, $code, 'same as plain PHP when an app handler handles the exception');
        $this->assertStringContainsString('APP HANDLER: boom', $out);
        $this->assertStringNotContainsString('Fatal error', $out);
    }

    public function testHypCurlErrorsBecomeJson(): void
    {
        [$code, $out] = $this->runScript(
            "HypCurl::boot(['json_errors' => true, 'values' => ['HYPCURL_LOG_LEVEL' => 'OFF']]);\n"
            . "HypCurl::get('http://127.0.0.1:1/')->send();",
        );

        $this->assertSame(1, $code);
        $json = json_decode($out, true);
        $this->assertSame(false, $json['success']);
        $this->assertSame('connection_error', $json['error']['type']);
    }

    public function testMisplacedJsonErrorsKeyIsReportedAsJson(): void
    {
        [$code, $out] = $this->runScript("HypCurl::boot(['defaults' => ['json_errors' => true]]);");

        $this->assertSame(1, $code);
        $this->assertSame('config_error', json_decode($out, true)['error']['type']);
    }

    public function testAllExceptionsMode(): void
    {
        [$code, $out] = $this->runScript(
            "HypCurl::boot(['json_errors' => ['all' => true]]);\nthrow new LogicException('secret detail');",
        );

        $this->assertSame(1, $code);
        $this->assertSame(['type' => 'internal_error', 'message' => 'Internal error'], json_decode($out, true)['error']);
    }
}
