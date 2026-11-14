<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArmazemMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_chave_estrangeira_da_linha_de_rececao_tem_nome_compativel_com_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('A validação do nome da constraint é específica do MySQL.');
        }

        $constraint = DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['armazem_transferencia_rececao_linhas', 'transferencia_linha_id']
        );

        $this->assertSame('arr_rec_linhas_trans_linha_fk', $constraint?->CONSTRAINT_NAME);
    }
}
