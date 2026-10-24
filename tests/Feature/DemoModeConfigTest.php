<?php

namespace Tests\Feature;

use App\Support\DemoMode;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The demo mode is parsed from the environment in config/lernplattform.php, so it is checked against the file itself
 * instead of against the configuration of the booted application.
 */
class DemoModeConfigTest extends TestCase
{
    /**
     * The only environments on which demo logins may be on at all.
     *
     * @return array<string, array{0: string}>
     */
    public static function allowedEnvironments(): array
    {
        return [
            'local' => ['local'],
            'testing' => ['testing'],
            'staging' => ['staging'],
            'demo' => ['demo'],
        ];
    }

    /**
     * Every other value stays off, including variants that only differ in case, a leading space or nothing at all.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function forbiddenEnvironments(): array
    {
        return [
            'production' => ['production'],
            'prod' => ['prod'],
            'capitalised production' => ['Production'],
            'live' => ['live'],
            'production with a leading space' => [' production'],
            'empty' => [''],
            'not set' => [null],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function truthyDemoModeValues(): array
    {
        return [
            'true' => ['true'],
            'one' => ['1'],
            'on' => ['on'],
            'yes' => ['yes'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function falsyDemoModeValues(): array
    {
        return [
            'no' => ['no'],
            'off' => ['off'],
            'zero' => ['0'],
            'false' => ['false'],
            'empty' => [''],
            'disabled' => ['disabled'],
        ];
    }

    #[DataProvider('allowedEnvironments')]
    public function test_demo_mode_is_enabled_on_the_allowed_environments(string $appEnv): void
    {
        $config = $this->configUnder(['APP_ENV' => $appEnv, 'DEMO_MODE' => 'true', 'DEMO_PASSWORD' => 'demo-passwort-1']);

        $this->assertTrue($config['demo']['enabled']);
        $this->assertSame('demo-passwort-1', $config['demo']['password']);
    }

    #[DataProvider('forbiddenEnvironments')]
    public function test_demo_mode_is_off_on_every_other_environment(?string $appEnv): void
    {
        $config = $this->configUnder(['APP_ENV' => $appEnv, 'DEMO_MODE' => 'true', 'DEMO_PASSWORD' => 'demo-passwort-1']);

        $this->assertFalse($config['demo']['enabled']);
    }

    #[DataProvider('truthyDemoModeValues')]
    public function test_demo_mode_accepts_the_truthy_switch_values(string $demoMode): void
    {
        $config = $this->configUnder(['APP_ENV' => 'demo', 'DEMO_MODE' => $demoMode, 'DEMO_PASSWORD' => 'demo-passwort-1']);

        $this->assertTrue($config['demo']['enabled']);
    }

    #[DataProvider('falsyDemoModeValues')]
    public function test_demo_mode_rejects_every_other_switch_value(string $demoMode): void
    {
        $config = $this->configUnder(['APP_ENV' => 'demo', 'DEMO_MODE' => $demoMode, 'DEMO_PASSWORD' => 'demo-passwort-1']);

        $this->assertFalse($config['demo']['enabled']);
    }

    public function test_demo_mode_is_off_when_the_switch_is_not_set(): void
    {
        $config = $this->configUnder(['APP_ENV' => 'demo', 'DEMO_MODE' => null, 'DEMO_PASSWORD' => 'demo-passwort-1']);

        $this->assertFalse($config['demo']['enabled']);
    }

    public function test_demo_mode_needs_the_password(): void
    {
        config(['lernplattform.demo.enabled' => true, 'lernplattform.demo.password' => '']);

        $this->assertFalse(DemoMode::isActive());
    }

    public function test_the_demo_password_is_readable_while_demo_mode_is_on(): void
    {
        config(['lernplattform.demo.enabled' => true, 'lernplattform.demo.password' => 'geheim-123456']);

        $this->assertTrue(DemoMode::isActive());
        $this->assertSame('geheim-123456', DemoMode::password());
    }

    public function test_the_password_cannot_be_read_while_demo_mode_is_off(): void
    {
        config(['lernplattform.demo.enabled' => false, 'lernplattform.demo.password' => 'geheim-123456']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Demo mode is not active.');

        DemoMode::password();
    }

    /**
     * Reads config/lernplattform.php once with the given environment and restores the environment afterwards.
     *
     * refreshApplication() must not be used here: the env repository of the booted application keeps the values of
     * .env, so the test would only ever see those. The variables are therefore set in $_ENV, $_SERVER and the process
     * environment and the configuration file is required again.
     *
     * @param  array<string, string|null>  $variables  null removes the variable
     * @return array<string, mixed>
     */
    private function configUnder(array $variables): array
    {
        $original = [];

        foreach ($variables as $key => $value) {
            $original[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        try {
            /** @var array<string, mixed> $config */
            $config = require config_path('lernplattform.php');

            return $config;
        } finally {
            foreach ($original as $key => $values) {
                $this->restoreEnvironmentVariable($key, $values);
            }
        }
    }

    /**
     * @param  array{0: string|null, 1: string|null, 2: string|false}  $values  $_ENV, $_SERVER and getenv() as they were
     */
    private function restoreEnvironmentVariable(string $key, array $values): void
    {
        [$env, $server, $process] = $values;

        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }

        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }

        if ($process === false) {
            putenv($key);
        } else {
            putenv($key.'='.$process);
        }
    }
}
