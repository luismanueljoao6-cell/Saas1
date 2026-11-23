<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

/**
 * Autenticação em dois fatores (TOTP, RFC 6238: SHA-1, 6 dígitos, 30 s),
 * compatível com Google Authenticator, Microsoft Authenticator, Authy, etc.
 *
 * - O segredo e os códigos de recuperação ficam cifrados em repouso (casts do User).
 * - Os códigos de recuperação ficam só como HMAC: o texto em claro mostra-se uma vez.
 * - Um código TOTP só é aceite uma vez (two_factor_ultimo_passo) e a verificação
 *   corre sob bloqueio da linha do utilizador, por isso dois pedidos simultâneos
 *   com o mesmo código não passam ambos.
 * - Só o segredo CONFIRMADO autentica; um segredo pendente nunca serve para entrar.
 */
class DoisFatoresService
{
    public const DIGITOS = 6;

    public const PERIODO = 30;

    /** Intervalos aceites para cada lado (tolerância ao relógio do telemóvel: ±30 s). */
    public const JANELA = 1;

    public const CODIGOS_RECUPERACAO = 8;

    protected const ALFABETO_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Sem 0/O/1/I, para os códigos de recuperação se lerem sem enganos. */
    protected const ALFABETO_RECUPERACAO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function gerarSegredo(): string
    {
        return $this->base32Codificar(random_bytes(20));
    }

    /** Devolve o segredo pendente, ou cria um novo; recarregar a página não o troca. */
    public function prepararAtivacao(User $user): string
    {
        if ($user->temDoisFatoresAtivos()) {
            throw new DomainException('A autenticação em dois fatores já está ativa.');
        }

        if ($user->two_factor_secret !== null) {
            return $user->two_factor_secret;
        }

        $segredo = $this->gerarSegredo();

        $user->forceFill([
            'two_factor_secret' => $segredo,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_ultimo_passo' => null,
        ])->save();

        return $segredo;
    }

    /**
     * Confirma a ativação com um código gerado pela app. Devolve os códigos de
     * recuperação em claro (únicos a existir), ou null se o código estiver errado.
     *
     * @return list<string>|null
     */
    public function confirmarAtivacao(User $user, string $codigo): ?array
    {
        return DB::transaction(function () use ($user, $codigo) {
            $atual = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($atual->two_factor_secret === null || $atual->temDoisFatoresAtivos()) {
                return null;
            }

            $passo = $this->passoValido($atual->two_factor_secret, $codigo, $atual->two_factor_ultimo_passo);

            if ($passo === null) {
                return null;
            }

            $codigos = $this->gerarCodigosRecuperacao();

            $atual->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_ultimo_passo' => $passo,
                'two_factor_recovery_codes' => array_map(fn (string $c) => $this->hashCodigo($c), $codigos),
            ])->save();

            $user->refresh();

            return $codigos;
        });
    }

    /** Valida o código da app no desafio de login. Cada código serve uma só vez. */
    public function validarCodigo(User $user, string $codigo): bool
    {
        return DB::transaction(function () use ($user, $codigo) {
            $atual = User::query()->lockForUpdate()->find($user->id);

            if ($atual === null || ! $atual->temDoisFatoresAtivos()) {
                return false;
            }

            $passo = $this->passoValido($atual->two_factor_secret, $codigo, $atual->two_factor_ultimo_passo);

            if ($passo === null) {
                return false;
            }

            $atual->forceFill(['two_factor_ultimo_passo' => $passo])->save();

            return true;
        });
    }

    /** Consome um código de recuperação (um só uso). Ignora maiúsculas, espaços e hífen. */
    public function usarCodigoRecuperacao(User $user, string $codigo): bool
    {
        $normalizado = $this->normalizarRecuperacao($codigo);

        if (strlen($normalizado) !== 10) {
            return false;
        }

        $hash = $this->hashCodigo($normalizado);

        return DB::transaction(function () use ($user, $hash) {
            $atual = User::query()->lockForUpdate()->find($user->id);

            if ($atual === null || ! $atual->temDoisFatoresAtivos()) {
                return false;
            }

            $restantes = [];
            $encontrado = false;

            foreach ($atual->two_factor_recovery_codes ?? [] as $guardado) {
                if (! $encontrado && hash_equals($guardado, $hash)) {
                    $encontrado = true;

                    continue;
                }

                $restantes[] = $guardado;
            }

            if (! $encontrado) {
                return false;
            }

            $atual->forceFill(['two_factor_recovery_codes' => $restantes])->save();

            return true;
        });
    }

    /**
     * Gera um conjunto novo e invalida o anterior.
     *
     * @return list<string>|null null se o 2FA não estiver ativo
     */
    public function regenerarCodigosRecuperacao(User $user): ?array
    {
        return DB::transaction(function () use ($user) {
            $atual = User::query()->lockForUpdate()->find($user->id);

            if ($atual === null || ! $atual->temDoisFatoresAtivos()) {
                return null;
            }

            $codigos = $this->gerarCodigosRecuperacao();

            $atual->forceFill([
                'two_factor_recovery_codes' => array_map(fn (string $c) => $this->hashCodigo($c), $codigos),
            ])->save();

            $user->refresh();

            return $codigos;
        });
    }

    public function desativar(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_ultimo_passo' => null,
        ])->save();
    }

    public function codigosRestantes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /** otpauth://: aberta no telemóvel, a app autenticadora oferece-se para a importar. */
    public function uriOtpauth(string $email, string $segredo): string
    {
        $emissor = (string) config('app.name', 'Saas1');

        return 'otpauth://totp/'.rawurlencode($emissor).':'.rawurlencode($email).'?'.http_build_query([
            'secret' => $segredo,
            'issuer' => $emissor,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITOS,
            'period' => self::PERIODO,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function passoDe(int $instante): int
    {
        return intdiv($instante, self::PERIODO);
    }

    /** Código TOTP de um segredo base32 num intervalo (RFC 4226 §5.3 sobre o contador). */
    public function codigo(string $segredo, int $passo): string
    {
        $hash = hash_hmac('sha1', pack('J', $passo), $this->base32Descodificar($segredo), true);

        $deslocamento = ord($hash[19]) & 0x0F;

        $inteiro = ((ord($hash[$deslocamento]) & 0x7F) << 24)
            | (ord($hash[$deslocamento + 1]) << 16)
            | (ord($hash[$deslocamento + 2]) << 8)
            | ord($hash[$deslocamento + 3]);

        return str_pad((string) ($inteiro % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * Intervalo cujo código coincide, ou null. Só aceita intervalos posteriores
     * ao último já usado. Percorre sempre toda a janela (tempo constante).
     */
    public function passoValido(string $segredo, string $codigo, ?int $ultimoPasso, ?int $agora = null): ?int
    {
        $codigo = preg_replace('/\s+/', '', $codigo);

        if (! preg_match('/^\d{'.self::DIGITOS.'}$/', $codigo)) {
            return null;
        }

        $atual = $this->passoDe($agora ?? now()->timestamp);
        $aceite = null;

        for ($passo = $atual - self::JANELA; $passo <= $atual + self::JANELA; $passo++) {
            if (hash_equals($this->codigo($segredo, $passo), $codigo) && $passo > ($ultimoPasso ?? -1)) {
                $aceite = $passo;
            }
        }

        return $aceite;
    }

    /** @return list<string> no formato XXXXX-XXXXX */
    protected function gerarCodigosRecuperacao(): array
    {
        $codigos = [];

        for ($i = 0; $i < self::CODIGOS_RECUPERACAO; $i++) {
            $texto = '';

            for ($j = 0; $j < 10; $j++) {
                $texto .= self::ALFABETO_RECUPERACAO[random_int(0, strlen(self::ALFABETO_RECUPERACAO) - 1)];
            }

            $codigos[] = substr($texto, 0, 5).'-'.substr($texto, 5);
        }

        return $codigos;
    }

    protected function normalizarRecuperacao(string $codigo): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $codigo));
    }

    protected function hashCodigo(string $codigo): string
    {
        return hash_hmac('sha256', $this->normalizarRecuperacao($codigo), (string) config('app.key'));
    }

    protected function base32Codificar(string $binario): string
    {
        $bits = '';

        foreach (str_split($binario) as $caractere) {
            $bits .= str_pad(decbin(ord($caractere)), 8, '0', STR_PAD_LEFT);
        }

        $saida = '';

        foreach (str_split($bits, 5) as $grupo) {
            $saida .= self::ALFABETO_BASE32[bindec(str_pad($grupo, 5, '0'))];
        }

        return $saida;
    }

    protected function base32Descodificar(string $texto): string
    {
        $texto = strtoupper(preg_replace('/[\s=-]+/', '', $texto));
        $bits = '';

        foreach (str_split($texto) as $caractere) {
            $posicao = strpos(self::ALFABETO_BASE32, $caractere);

            if ($posicao === false) {
                throw new DomainException('Segredo base32 inválido.');
            }

            $bits .= str_pad(decbin($posicao), 5, '0', STR_PAD_LEFT);
        }

        $saida = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $saida .= chr(bindec($byte));
            }
        }

        return $saida;
    }
}
