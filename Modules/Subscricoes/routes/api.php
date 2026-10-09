<?php

use Illuminate\Support\Facades\Route;
use Modules\Subscricoes\Http\Controllers\Webhooks\ProxyPayWebhookController;

/*
| Webhook de pagamento: sem autenticação de utilizador; a autenticidade é
| garantida por 'webhook.assinatura'. 'throttle' corre primeiro, para limitar
| tentativas de adivinhar o token. URL: {URL_DA_APP}/api/webhooks/proxypay
*/
Route::post('/webhooks/proxypay', ProxyPayWebhookController::class)
    ->middleware(['throttle:120,1', 'webhook.assinatura'])
    ->name('subscricoes.webhooks.proxypay');
