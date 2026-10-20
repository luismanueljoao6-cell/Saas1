<?php
// Uso: php patcher.php <ficheiro>   (stdin: BLOCO_ANTIGO \n=====\n BLOCO_NOVO)
$alvo = $argv[1] ?? null;
if ($alvo === null || ! is_file($alvo)) {
    fwrite(STDERR, "ERRO: ficheiro não encontrado: {$alvo}\n");
    exit(1);
}

$entrada = str_replace("\r\n", "\n", stream_get_contents(STDIN));
$partes = explode("\n=====\n", $entrada, 2);
if (count($partes) !== 2) {
    fwrite(STDERR, "ERRO: formato de patch inválido para {$alvo}\n");
    exit(2);
}

$antigo = $partes[0];
$novo = rtrim($partes[1], "\n");

$conteudo = file_get_contents($alvo);
$crlf = str_contains($conteudo, "\r\n");
$norm = str_replace("\r\n", "\n", $conteudo);

if ($novo !== '' && str_contains($norm, $novo)) {
    echo "  = já aplicado: {$alvo}\n";
    exit(0);
}

$n = substr_count($norm, $antigo);
if ($n !== 1) {
    fwrite(STDERR, "ERRO: o bloco original aparece {$n}x (esperado: 1) em {$alvo}.\n");
    fwrite(STDERR, "      O ficheiro mudou desde a análise? Aplica esta alteração manualmente.\n");
    exit(1);
}

$pos = strpos($norm, $antigo);
$norm = substr_replace($norm, $novo, $pos, strlen($antigo));

file_put_contents($alvo, $crlf ? str_replace("\n", "\r\n", $norm) : $norm);
echo "  + alterado: {$alvo}\n";
