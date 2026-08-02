# Integração Focus NFe

## Ambiente e autenticação

- A Focus possui ambiente de homologação e produção.
- As URLs base são `https://homologacao.focusnfe.com.br` e `https://api.focusnfe.com.br`.
- As rotas REST usam o prefixo `/v2`.
- A autenticação é HTTP Basic: o token da empresa é o usuário e a senha é vazia (`token:`).
- A `ref` de uma emissão deve ser alfanumérica e única dentro do token da empresa. Depois de autorizada, não deve ser reutilizada para outra emissão.
- Nunca registrar API key/token, payload fiscal completo ou XML em logs.

## Emissão e consulta

- NF-e e NFS-e têm processamento normalmente assíncrono.
- A consulta individual é feita por referência:
  - NF-e: `GET /v2/nfe/{referencia}`;
  - NFS-e: `GET /v2/nfse/{referencia}`.
- NF-e aceita `completa=1` para retornar dados completos da requisição e do protocolo.
- A resposta pode representar processamento, autorização, cancelamento ou erro de autorização. O sistema não deve considerar uma resposta de envio como autorização definitiva.
- A API de backups lista arquivos mensais para documentos suportados, mas não substitui a consulta individual de NFS-e.
- `/nfes_recebidas` é uma API de documentos emitidos contra o CNPJ da empresa, não uma listagem de documentos emitidos pela própria empresa.
- `/nfe/importacao` importa XML para a Focus; não é o mecanismo para importar documentos da Focus para o younNotas.

## Webhooks e hospedagem

- A Focus permite cadastrar gatilhos para eventos `nfe` e `nfse`, entre outros.
- O webhook recebe um `POST` JSON com os dados de um documento por acionamento.
- Se o endpoint responder fora da família 2xx ou estiver indisponível, a Focus tenta reenviar nos intervalos documentados de 1 minuto, 30 minutos, 1 hora, 3 horas e 24 horas. Depois da última tentativa, o evento não é disparado novamente.
- É possível solicitar reenvio de uma notificação por referência usando `/v2/nfe/{referencia}/hook` ou `/v2/nfse/{referencia}/hook`.
- O endpoint de webhook deve ser público, estável e acessível por HTTPS. Não pode depender de sessão do painel, autenticação web ou IP privado.
- O processamento deve responder rapidamente com 2xx e delegar trabalho pesado para a fila. O evento deve ser persistido antes da resposta para permitir idempotência e reconciliação.
- O endpoint deve autenticar a chamada com segredo próprio, registrar o evento de forma idempotente e tolerar reenvios.
- Webhooks não são necessários para o fluxo básico, pois o sistema possui polling por referência, retries e reconciliação.
- Em produção, manter worker de fila e scheduler ativos; para receber webhooks, disponibilizar HTTPS público e estável para `/api/webhooks/focus-nfe` e `/api/webhooks/focus-nfse`.
- As rotas atuais aplicam `throttle:30,1` aos webhooks recebidos. Esse limite não afeta chamadas de saída para a Focus.

## Limites e chamadas de saída

As páginas oficiais consultadas não publicam um número universal de requisições por minuto. A própria introdução da documentação alerta que resolver o acompanhamento apenas com vários `GET` em sequência aumenta o tráfego e pode atingir limites. Portanto, a integração deve tratar limites como uma restrição real mesmo sem um valor fixo documentado.

### Restrições da integração atual

- `FocusNfeService` e `FocusNfseService` fazem chamadas HTTP diretamente.
- Jobs de consulta possuem `ShouldBeUnique`, `WithoutOverlapping`, retries e atrasos, mas esses controles são por venda/O.S., não por token Focus, empresa ou endpoint.
- Não existe um limitador global de concorrência ou de taxa para chamadas de emissão, consulta, cancelamento, backup ou reenvio.
- Não há tratamento específico para HTTP 429, `Retry-After` ou janela de rate limit.
- O scheduler pode despachar até 100 consultas de NF-e pendentes a cada cinco minutos; somado a consultas iniciadas por emissão, ações manuais e múltiplas empresas, isso pode gerar rajadas acima do limite contratado.
- A importação de backups, quando implementada, também poderá criar muitas requisições e downloads sem uma política de pacing.

Conclusão: atualmente o sistema não garante que respeitará os limites da Focus.

### Requisitos para novas chamadas

Centralizar todas as chamadas Focus em um cliente/gateway com:

1. Rate limit compartilhado por token/empresa, ambiente e operação, usando Redis em produção.
2. Limite de concorrência por token para impedir várias requisições simultâneas desnecessárias.
3. Fila própria para operações Focus, com prioridade para emissão/cancelamento e prioridade menor para polling/importação.
4. Backoff exponencial com jitter para falhas transitórias.
5. Tratamento explícito de `429`, respeitando `Retry-After` quando enviado.
6. Circuit breaker ou pausa temporária quando houver sequência de respostas de limite/indisponibilidade.
7. Deduplicação por empresa, referência e operação, evitando consultas concorrentes ao mesmo documento.
8. Polling adaptativo: espaçar consultas enquanto estiver processando e interromper imediatamente ao receber estado final via webhook.
9. Limite de lote e espaçamento entre downloads/importações de backup.
10. Métricas de chamadas por token, endpoint, status HTTP, latência, retries e respostas 429.

Os limites numéricos devem ser configuráveis por ambiente e ajustados com base na resposta formal da Focus ou no contrato da conta. Não fixar um valor presumido no código sem confirmação da Focus.
- Preferir webhooks para acompanhamento e manter consulta apenas como fallback/reconciliação.
- Não adicionar novos `Http::get/post/delete` diretos fora do cliente Focus centralizado.
- Toda nova chamada deve definir timeout, política de retry, classificação de erro e impacto no rate limit.
- Testar 2xx, 4xx, 401, 404, 422, 429, 5xx, timeout e resposta inválida.
- Manter tokens por empresa/ambiente e nunca usar um token de homologação em produção.
