<?php

namespace Modules\Subscricoes\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')
            ->group(__DIR__.'/../../routes/web.php');

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../../routes/api.php');
    }
}
