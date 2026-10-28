<?php

declare(strict_types=1);

namespace Tests\Feature;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\TestCase as ApplicationTestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DatabaseSafetyTest extends TestCase
{
    public function test_a_database_url_cannot_bypass_the_test_database_guard(): void
    {
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = 'postgresql://user@127.0.0.1/another_database';
        putenv('DB_URL='.$_ENV['DB_URL']);

        $this->expectException(LogicException::class);
        $this->applicationTestCase()->createApplication();
    }

    public function test_a_different_database_driver_is_rejected(): void
    {
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        putenv('DB_CONNECTION=sqlite');

        $this->expectException(LogicException::class);
        $this->applicationTestCase()->createApplication();
    }

    private function applicationTestCase(): ApplicationTestCase
    {
        return new class('test_placeholder') extends ApplicationTestCase
        {
            public function test_placeholder(): void {}
        };
    }
}
