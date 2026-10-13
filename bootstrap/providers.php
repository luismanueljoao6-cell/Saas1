<?php

use App\Providers\AppServiceProvider;
use Nwidart\Modules\LaravelModulesServiceProvider;

return [
    // Registo explícito para garantir que os comandos e o carregamento
    // dos módulos funcionam mesmo quando o manifesto de packages está
    // desatualizado no ambiente (por exemplo, depois de integrar um ZIP).
    LaravelModulesServiceProvider::class,
    AppServiceProvider::class,
];
