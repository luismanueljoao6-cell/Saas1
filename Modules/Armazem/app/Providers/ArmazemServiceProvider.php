<?php

namespace Modules\Armazem\Providers;

use Illuminate\Support\ServiceProvider;

class ArmazemServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
