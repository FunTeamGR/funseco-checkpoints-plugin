<?php

namespace Funseco\Checkpoints\Tests;

use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Widgets\WidgetsServiceProvider;
use Funseco\Menu\MenuServiceProvider;
use Funseco\Dedimania\DedimaniaServiceProvider;
use Funseco\Checkpoints\CheckpointsServiceProvider;

abstract class TestCase extends CoreTestCase
{
    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            WidgetsServiceProvider::class,
            MenuServiceProvider::class,
            DedimaniaServiceProvider::class,
            CheckpointsServiceProvider::class,
        ];
    }
}
