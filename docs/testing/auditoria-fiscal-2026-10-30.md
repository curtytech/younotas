# Auditoria fiscal — Mil de Magé — 30/10/2026

## Resultado comprovado

- Acesso à VPS por SSH com chave, sem alterações nos dados de produção.
- Aplicação encontrada no contêiner reiteste-php, em /var/www, revisão 3770509.
- Dois cadastros da MIL DE MAGE CENTRO AUTOMOTIVO LTDA: IDs 2 (homologação) e 3 (produção).
- Os dois cadastros possuem conexões habilitadas nos dois ambientes e tokens diferentes entre ambientes.
- Os parâmetros efetivamente carregados do emitente estão preenchidos; campos ocultos na serialização não significam configuração ausente.
- Consultas reais de referências aleatórias inexistentes: HTTP 404 nas quatro combinações empresa/ambiente; nenhum HTTP 401.
- Prévia real da DANFE em homologação: HTTP 200 e assinatura de arquivo %PDF.
- NF-e real em homologação: referência audit4176f7be2076f886596076dd, autorizada, status SEFAZ 100.
- Consulta posterior da mesma referência e download da DANFE autorizada: passaram; PDF validado.
- O teste real usa SQLite em memória com RefreshDatabase e cadastros sintéticos. Não grava clientes, produtos ou vendas no banco da VPS.
- Cadastro de cliente no navegador local: CPF inválido, UF inexistente e IBGE curto rejeitados juntos; mensagens junto aos campos e valores preservados.
- Suíte local antes dos novos testes opt-in: 237 testes passaram, 1499 verificações.
- Testes reais opt-in de prévia e download: 2 passaram, 9 verificações. O envio anterior passou com 5 verificações.

## Limites

Não houve emissão, cancelamento ou alteração de documentos em produção. HTTP 404 em consulta não prova habilitação para emissão, certificado válido ou autorização pela SEFAZ.

A emissão real foi verificada por integração. Na revisão posterior, o runner tests/Browser/run.sh passou no desktop e celular com cadastros completos, prévia, emissão simulada e abertura da DANFE. A matriz de erros do navegador ainda não está completa; consulte tests/Browser/README.md.

As falhas 401/422/429/5xx, timeout, JSON inválido, idempotência e isolamento possuem cobertura na suíte com respostas simuladas; não foram provocadas deliberadamente no provedor real.

## Segredos

O .env e as configurações resolvidas foram obtidos em arquivos privados fora do Git. Não copiar tokens, payload fiscal completo ou XML para relatórios. Não substituir o .env local pelo de produção.

## Reexecução

Os testes LiveFocusAuditTest são ignorados por padrão. Para executar a prévia real, fornecer FOCUS_AUDIT_CONFIG apontando para um JSON privado com a configuração resolvida de homologação. O arquivo contém uma lista de objetos com company_id, environment e config. A configuração deve conter o host de homologação; produção é rejeitada.

FOCUS_AUDIT_EMIT=1 autoriza o teste a emitir uma nova NF-e de homologação com referência única. Cada execução com essa opção emite outra nota de teste. Sem essa opção, o teste não envia nova emissão. O resultado fica em focus-audit-result.json ao lado da configuração e permite consultar e baixar a DANFE da referência existente.

No ambiente lerd, variáveis do host não foram repassadas pelo wrapper php. A execução real usou podman exec -e com as variáveis explícitas no contêiner PHP e arquivo privado em storage/app/private, ignorado pelo Git.

## Revisão dos testes de navegador

O Terra entregou a base e um login móvel. A revisão do agente principal corrigiu o isolamento, o conflito de porta e o fake com chave duplicada, e implementou o fluxo de cadastro até DANFE nos dois layouts. Foram corrigidos também o nome acessível do menu da venda e a exposição de SQL nos erros fiscais. Os testes da aplicação passaram com 239 testes e dois testes reais opt-in ignorados por padrão.
