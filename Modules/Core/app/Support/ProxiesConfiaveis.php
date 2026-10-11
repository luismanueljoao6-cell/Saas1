<?php

namespace Modules\Core\Support;

/**
 * Decide em que proxies confiar. Isolado de bootstrap/app.php para poder ser
 * testado: o erro original era tratar TRUSTED_PROXIES vazio ('') como "não
 * definido" (null), o que impedia a confiança automática no GitHub Codespaces.
 */
final class ProxiesConfiaveis
{
    /**
     * @return array<int, string>|string|null null = não confiar em nenhum proxy
     */
    public static function resolver(mixed $valor, bool $emCodespaces): array|string|null
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return $emCodespaces ? '*' : null;
        }

        if ($valor === '*') {
            return '*';
        }

        $lista = array_values(array_filter(array_map('trim', explode(',', $valor)), 'strlen'));

        return $lista === [] ? null : $lista;
    }
}
