<?php

namespace Modules\Faturacao\Services;

use DOMDocument;

/**
 * Valida o XML do SAF-T (AO) contra o XSD oficial.
 *
 * "XML bem formado" não é "XML conforme ao XSD", e nenhum dos dois é "aceite
 * pela AGT": esta classe cobre só o segundo passo, e só quando o XSD oficial
 * estiver presente em `faturacao.saft_xsd_path`.
 */
class SafTValidadorXsd
{
    public function caminhoXsd(): string
    {
        return (string) config('faturacao.saft_xsd_path');
    }

    public function disponivel(): bool
    {
        $caminho = $this->caminhoXsd();

        return $caminho !== '' && is_file($caminho);
    }

    /**
     * @return list<string> erros de validação; vazio quando o XML é conforme ao XSD
     *
     * @throws \RuntimeException se o XSD oficial não estiver disponível
     */
    public function validar(string $xml): array
    {
        if (! $this->disponivel()) {
            throw new \RuntimeException('XSD oficial do SAF-T (AO) não encontrado em "'.$this->caminhoXsd().'".');
        }

        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $doc = new DOMDocument;

            if (! $doc->loadXML($xml)) {
                return $this->erros('XML mal formado');
            }

            if ($doc->schemaValidate($this->caminhoXsd())) {
                return [];
            }

            return $this->erros('Validação XSD falhou');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    /** @return list<string> */
    protected function erros(string $mensagemPorOmissao): array
    {
        $erros = [];

        foreach (libxml_get_errors() as $erro) {
            $erros[] = trim($erro->message).' (linha '.$erro->line.')';
        }

        return $erros !== [] ? array_slice($erros, 0, 50) : [$mensagemPorOmissao.'.'];
    }
}
