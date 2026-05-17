<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use ExpressCodeEngines\Core\EngineServiceProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Minimal Laravel bootstrap for integration tests.
 *
 * Sets up:
 *   - IoC container with Facades
 *   - SQLite in-memory database via Eloquent Capsule
 *   - EngineServiceProvider (core bindings)
 *   - Base schema for engines that need DB (ConstraintEngine checkers)
 *
 * Does NOT require a full Laravel application.
 * Does NOT require artisan, HTTP kernel, or session handling.
 *
 * Each test class gets a fresh container and fresh DB schema.
 */
abstract class EngineIntegrationTestCase extends BaseTestCase
{
    protected Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = $this->buildContainer();
        $this->bootDatabase();
        $this->bootServiceProvider();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────
    // Bootstrap
    // ──────────────────────────────────────────────────────────

    private function buildContainer(): Container
    {
        $app = new Container();
        $app->instance('app', $app);
        $app->instance(Container::class, $app);

        // Config
        $config = new ConfigRepository([
            'app'     => ['debug' => true, 'env' => 'testing'],
            'engines' => $this->enginesConfig(),
            'cache'   => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        ]);

        $app->instance('config', $config);
        $app->bind(\Illuminate\Contracts\Config\Repository::class, fn () => $config);

        // Events
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $events);

        // Running in console flag
        $app->bind('runningInConsole', fn () => false);

        Facade::setFacadeApplication($app);

        return $app;
    }

    private function bootDatabase(): void
    {
        $capsule = new Capsule();

        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $capsule->setEventDispatcher(new Dispatcher($this->app));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Register DB facade
        $this->app->instance('db', $capsule->getDatabaseManager());

        $this->buildSchema($capsule);
    }

    private function bootServiceProvider(): void
    {
        $provider = new EngineServiceProvider($this->app);
        $provider->register();
        // Note: boot() requires config() facade — skip for now unless testing commands
    }

    /**
     * Build the SQLite in-memory schema needed by constraint checkers.
     */
    protected function buildSchema(Capsule $capsule): void
    {
        $schema = $capsule->schema();

        // Orders table — used by UniquenessChecker and LimitChecker tests
        $schema->create('orders', function ($table) {
            $table->id();
            $table->string('reference')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();
        });

        // Bookings table — used by OverlapChecker tests
        $schema->create('bookings', function ($table) {
            $table->id();
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
        });
    }

    /**
     * Override in subclasses to inject custom engine config.
     */
    protected function enginesConfig(): array
    {
        return [];
    }

    /**
     * Helper: resolve a binding from the container.
     */
    protected function make(string $abstract): mixed
    {
        return $this->app->make($abstract);
    }
}
