<?php

namespace Modules\EstudioMusica\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Services\CampanhaMarketingService;

/**
 * "Campanhas para Datas Comemorativas" (secção F) — disparo manual pelo
 * Administrador, de propósito não agendado sozinho (ver
 * CampanhaMarketingService::enviarCampanhaGenerica() para o porquê).
 *
 *   php artisan estudiomusica:campanha-generica 1 dia-da-musica-2026 \
 *     "Feliz Dia da Música!" "Este mês, 20% de desconto em horas de estúdio."
 */
class EnviarCampanhaGenericaCommand extends Command
{
    protected $signature = 'estudiomusica:campanha-generica
        {empresa : ID da empresa}
        {identificador : Identifica esta edição da campanha (ex.: dia-da-musica-2026) — evita reenvio duplicado}
        {assunto : Assunto do e-mail}
        {mensagem : Corpo da mensagem, enviado por WhatsApp/SMS/e-mail}';

    protected $description = 'Envia manualmente uma campanha de datas comemorativas a todos os clientes de uma empresa.';

    public function handle(TenantManager $tenantManager, CampanhaMarketingService $campanhas): int
    {
        $empresa = Empresa::findOrFail((int) $this->argument('empresa'));

        $tenantManager->set($empresa->id);

        $enviados = $campanhas->enviarCampanhaGenerica(
            $this->argument('identificador'),
            $this->argument('assunto'),
            $this->argument('mensagem'),
        );

        $this->info("Campanha enviada a {$enviados} cliente(s) de \"{$empresa->nome_comercial}\".");

        return self::SUCCESS;
    }
}
