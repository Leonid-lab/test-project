<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $connection = $application->make('db')->connection();

        // Защищаем основную базу от RefreshDatabase при неверном окружении.
        if ($connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== 'product_report_test') {
            throw new LogicException('Tests must use the product_report_test database.');
        }

        return $application;
    }
}
