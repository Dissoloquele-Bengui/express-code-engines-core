<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core;

use Illuminate\Support\ServiceProvider;
use ExpressCodeEngines\Shared\Contracts\BusinessRuleEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ComputationEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ConstraintEngineInterface;
use ExpressCodeEngines\Shared\Contracts\WorkflowEngineInterface;
use ExpressCodeEngines\Core\ConstraintEngine\ConstraintEngine;
use ExpressCodeEngines\Core\ConstraintEngine\Checkers\UniquenessChecker;
use ExpressCodeEngines\Core\ConstraintEngine\Checkers\OverlapChecker;
use ExpressCodeEngines\Core\ConstraintEngine\Checkers\LimitChecker;
use ExpressCodeEngines\Core\BusinessRuleEngine\BusinessRuleEngine;
use ExpressCodeEngines\Core\ComputationEngine\ComputationEngine;
use ExpressCodeEngines\Core\WorkflowEngine\WorkflowEngine;

class EngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/engines.php', 'engines');

        // Register built-in constraint checkers
        $this->app->tag([
            UniquenessChecker::class,
            OverlapChecker::class,
            LimitChecker::class,
        ], 'engine.constraint.checkers');

        $this->app->bind(ConstraintEngineInterface::class, function ($app) {
            return new ConstraintEngine(
                checkers: $app->tagged('engine.constraint.checkers'),
            );
        });

        $this->app->bind(BusinessRuleEngineInterface::class, BusinessRuleEngine::class);
        $this->app->bind(ComputationEngineInterface::class,  ComputationEngine::class);
        $this->app->bind(WorkflowEngineInterface::class,     WorkflowEngine::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/engines.php' => config_path('engines.php'),
            ], 'engines-config');

            $this->commands([
                \ExpressCodeEngines\Core\Console\EnginesGraphCommand::class,
            ]);
        }

        if (config('app.debug')) {
            $this->validateEngineConfig();
        }
    }

    private function validateEngineConfig(): void
    {
        $config = config('engines', []);

        if (! is_array($config)) {
            throw new \RuntimeException('[ExpressCodeEngines] engines config must be an array.');
        }

        (new EngineConfigValidator())->validate($config);
    }
}
