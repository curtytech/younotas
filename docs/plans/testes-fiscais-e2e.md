# Validação fiscal E2E

Objetivo: conferir a configuração da Mil de Magé e testar cadastro, venda, emissão e DANFE, incluindo erros compreensíveis.

Passos concluídos:
- Identificar aplicação e obter .env e configurações sem expor segredos.
- Conferir conexão dos dois ambientes com GET sem efeitos fiscais.
- Executar suíte local: 237 testes passaram.
- Executar prévia real, emissão autorizada e download da DANFE em homologação.
- Conferir no navegador rejeição simultânea de CPF, UF e IBGE inválidos.

Tentativas: variável de ambiente do host não chegou ao contêiner pelo wrapper PHP; usar podman exec -e. O bloqueio global de chamadas externas dos testes exigiu allowStrayRequests restrito ao host de homologação. Campos ocultos na serialização da empresa exigiram inspecionar a configuração efetivamente resolvida.

Dúvidas abertas: nenhuma necessária para continuar os testes locais.

Próximo passo: concluir E2E de sucesso no navegador para cliente, produto, venda, prévia e emissão; ampliar cenários de erro no navegador e inspecionar desktop/mobile. A produção segue somente com consultas, sem emissão real.

Resultados: docs/testing/auditoria-fiscal-2026-10-30.md.
