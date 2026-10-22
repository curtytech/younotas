<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Support\NfeStatus;

/** Turns stored provider answers into a short explanation, the field to fix and the next step. */
class FiscalIssueDiagnosis
{
    public const STUCK_AFTER_MINUTES = 30;

    private const CODE_HINTS = [
        'permissao_negada' => 'A credencial não tem permissão para este emitente ou ambiente. Confira o token e a habilitação da empresa na Focus.',
        'empresa_nao_habilitada' => 'A empresa ainda não está habilitada para este tipo de documento. Habilite-a na Focus.',
        'certificado_vencido' => 'O certificado digital está vencido. Envie um certificado válido na Focus.',
        'requisicao_invalida' => 'A Focus recusou os dados enviados. Corrija os campos indicados e reenvie.',
        'erro_validacao_schema' => 'O XML não passou na validação de estrutura. Corrija os campos indicados e reenvie.',
        'nfe_nao_encontrada' => 'A referência não existe neste ambiente. Verifique se a emissão foi feita neste ambiente.',
        'nfse_nao_encontrada' => 'A referência não existe neste ambiente. Verifique se a emissão foi feita neste ambiente.',
    ];

    /** @return array{category: string, summary: string, details: list<string>, action: string} */
    public function explain(FiscalDocument $document): array
    {
        $error = $document->last_error ?? [];
        $response = is_array($error['response'] ?? null) ? $error['response'] : ($document->raw_response ?? []);
        $details = $this->details($response);

        if (blank($document->fiscal_connection_id) || blank($document->environment)) {
            return $this->result('reconciliation', 'Origem da emissão não confirmada.', $details, 'Reconcilie a origem antes de consultar, reenviar ou cancelar.');
        }

        if (in_array($document->status, [NfeStatus::SENDING, NfeStatus::PROCESSING], true) && $this->isStuck($document)) {
            return $this->result('stuck', 'Documento sem retorno há mais de '.self::STUCK_AFTER_MINUTES.' minutos.', $details, 'Consulte a nota. Se continuar sem retorno, confira a disponibilidade da prefeitura ou da SEFAZ.');
        }

        if ($document->status === NfeStatus::TRANSPORT_ERROR) {
            $ambiguous = NfeStatus::isAmbiguousFailure($error);

            return $this->result('communication', $error['message'] ?? 'Falha de comunicação com a Focus.', $details, $ambiguous
                ? 'O resultado é incerto. Consulte a referência antes de reenviar; o reenvio preserva a mesma referência.'
                : 'Corrija a causa indicada e reenvie a nota.');
        }

        if ($document->status === NfeStatus::CANCELLATION_ERROR) {
            return $this->result('cancellation', 'O cancelamento foi recusado.', $details, 'Confira o prazo e a justificativa do cancelamento e tente novamente.');
        }

        if (in_array($document->status, [NfeStatus::SENDING, NfeStatus::PROCESSING], true)) {
            return $this->result('processing', 'A nota está em processamento.', $details, 'Aguarde: a consulta automática atualiza a situação.');
        }

        if (! in_array($document->status, [NfeStatus::AUTHORIZATION_ERROR, NfeStatus::DENIED], true)) {
            return $this->result('none', 'Nenhum problema identificado nesta nota.', $details, 'Nenhuma ação necessária.');
        }

        $code = (string) ($response['codigo'] ?? '');
        $summary = $response['mensagem_sefaz'] ?? $response['mensagem'] ?? $error['message'] ?? 'A nota foi rejeitada.';

        return $this->result('rejection', (string) $summary, $details, self::CODE_HINTS[$code] ?? 'Corrija os campos indicados e reenvie a nota. A referência e o ambiente originais serão mantidos.');
    }

    public function isStuck(FiscalDocument $document): bool
    {
        $since = $document->last_checked_at ?? $document->last_sent_at ?? $document->updated_at;

        return $since !== null && $since->lt(now()->subMinutes(self::STUCK_AFTER_MINUTES));
    }

    /** @return list<string> */
    private function details(array $response): array
    {
        $details = [];

        foreach (array_slice((array) ($response['erros'] ?? []), 0, 5) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $message = (string) ($item['mensagem'] ?? '');
            $field = (string) ($item['campo'] ?? '');
            $fix = (string) ($item['correcao'] ?? '');
            $line = trim(($field !== '' ? $field.': ' : '').$message.($fix !== '' ? ' — '.$fix : ''));

            if ($line !== '') {
                $details[] = mb_substr($line, 0, 300);
            }
        }

        return $details;
    }

    private function result(string $category, string $summary, array $details, string $action): array
    {
        return ['category' => $category, 'summary' => mb_substr($summary, 0, 300), 'details' => $details, 'action' => $action];
    }
}
