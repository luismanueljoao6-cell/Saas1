<?php

namespace Modules\Core\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    public function boot(): void
    {
        $this->map();
    }

    protected function mapWebRoutes(): void
    {
        Route::middleware('web')
            ->group(__DIR__.'/../../routes/web.php');
    }

    protected function mapApiRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../../routes/api.php');
    }
}
