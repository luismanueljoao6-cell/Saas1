<?php

use Illuminate\Support\Facades\Route;
use Modules\Subscricoes\Http\Controllers\Webhooks\ProxyPayWebhookController;

/*
|--------------------------------------------------------------------------
| Webhook de pagamento
|--------------------------------------------------------------------------
| Sem autenticação de utilizador (o próprio gateway é quem chama isto) —
| a autenticidade é garantida pelo middleware 'webhook.assinatura', não
| por sessão/token de utilizador. Confirma no painel da ProxyPay qual é o
| URL exato a configurar: {URL_DA_APP}/api/webhooks/proxypay
*/
Route::post('/webhooks/proxypay', ProxyPayWebhookController::class)
    ->middleware('webhook.assinatura')
    ->name('subscricoes.webhooks.proxypay');
