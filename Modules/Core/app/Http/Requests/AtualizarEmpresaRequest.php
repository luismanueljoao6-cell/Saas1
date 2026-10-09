<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AtualizarEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $utilizador = $this->user();
        $empresa = $this->route('empresa');

        // Só um administrador da própria empresa pode editar as definições.
        return $utilizador !== null
            && $empresa !== null
            && (int) $utilizador->empresa_id === (int) $empresa->id
            && $utilizador->hasRole('Administrador');
    }

    public function rules(): array
    {
        $empresaId = $this->route('empresa')?->id;

        return [
            'nome_comercial' => ['required', 'string', 'max:255'],
            'nome_legal' => ['nullable', 'string', 'max:255'],
            'nif' => ['required', 'string', 'min:9', 'max:20', Rule::unique('empresas', 'nif')->ignore($empresaId)],
            'regime_fiscal' => ['nullable', 'string', 'max:100'],
            'morada' => ['nullable', 'string', 'max:255'],
            'municipio' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * Depois de emitir um documento fiscal, a identificação do emitente
     * (NIF e nome legal) passa a fazer parte do histórico fiscal — alterá-la
     * mudaria retroativamente o cabeçalho do SAF-T de documentos já assinados.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $empresa = $this->route('empresa');

            if ($empresa === null || ! $this->temDocumentosFiscaisEmitidos((int) $empresa->id)) {
                return;
            }

            if ((string) $this->input('nif') !== (string) $empresa->nif) {
                $validator->errors()->add('nif', 'O NIF não pode ser alterado depois de emitidos documentos fiscais.');
            }

            if ((string) $this->input('nome_legal') !== (string) $empresa->nome_legal) {
                $validator->errors()->add('nome_legal', 'O nome legal não pode ser alterado depois de emitidos documentos fiscais.');
            }
        }];
    }

    /**
     * O Core não conhece o módulo de Faturação: consulta a tabela só se
     * existir. (Evolução futura: um contrato no Core que a Faturação implementa.)
     */
    protected function temDocumentosFiscaisEmitidos(int $empresaId): bool
    {
        return Schema::hasTable('faturas')
            && DB::table('faturas')->where('empresa_id', $empresaId)->where('estado', 'emitida')->exists();
    }
}
