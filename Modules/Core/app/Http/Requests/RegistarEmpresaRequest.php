<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegistarEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // registo é público (ver rota "guest")
    }

    public function rules(): array
    {
        return [
            'nome_comercial' => ['required', 'string', 'max:255'],

            // Validação de formato permissiva de propósito: confirma o
            // formato exato do NIF junto da AGT antes de o endurecer — um
            // regex demasiado restritivo pode bloquear registos válidos.
            'nif' => ['required', 'string', 'min:9', 'max:20', 'regex:/^[0-9A-Za-z]+$/', 'unique:empresas,nif'],

            'email_empresa' => ['nullable', 'email', 'max:255'],
            'telefone_empresa' => ['nullable', 'string', 'max:30'],

            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'aceita_termos' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'nif.unique' => 'Já existe uma empresa registada com este NIF.',
            'email.unique' => 'Já existe uma conta registada com este e-mail.',
            'aceita_termos.accepted' => 'Tens de aceitar os termos de utilização para continuar.',
        ];
    }

    /**
     * Separa os campos por responsabilidade, para o controller passar
     * diretamente ao RegistoEmpresaService.
     */
    public function dadosEmpresa(): array
    {
        return [
            'nome_comercial' => $this->string('nome_comercial')->toString(),
            'nif' => $this->string('nif')->toString(),
            'email' => $this->input('email_empresa'),
            'telefone' => $this->input('telefone_empresa'),
        ];
    }

    public function dadosAdministrador(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }
}
