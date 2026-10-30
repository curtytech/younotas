# Validação fiscal E2E

Objetivo: conferir a configuração da Mil de Magé e testar cadastro, venda, emissão e DANFE, incluindo erros compreensíveis.

Concluído:
- Identificar instalação remota e obter configurações sem expor segredos.
- Consultas sem efeitos fiscais nos dois ambientes; emissão real autorizada e DANFE em homologação.
- Receber e revisar a entrega do GPT-6.1 Terra médio: inicialmente só plano, depois infraestrutura parcial e login.
- Corrigir isolamento de caches, porta fixa sem identidade, possibilidade de executar public/index.php fora do bootstrap de teste e chamadas externas do navegador.
- Acrescentar emitente/fake Focus sintéticos e chave distinta por referência.
- Executar em desktop e celular: login, cliente inválido e válido, produto, venda, NCM ausente/correção, prévia PDF em nova aba, emissão simulada com clique duplo e abertura da DANFE autorizada.
- Corrigir nome acessível do menu de venda e sanitizar falhas de banco no diagnóstico fiscal.
- Conferir capturas de erros e autorização nos dois layouts e recusa de porta ocupada.

Tentativas e achados:
- Http::fake deve ser instalado no processo do servidor; o fake do Pest não o protege.
- O fake devolvia chave fixa, provocando colisão na segunda venda. Foi corrigido; a falha revelou SQL exposto na interface, agora sanitizado.
- A notificação persistente da prévia pode cobrir a ação de emissão; o teste a fecha pelo controle da interface.
- No mobile, o menu pode estar aberto; o teste o fecha pela sobreposição, sem forçar cliques em controles cobertos.
- O PHP do host não repassa variáveis pelo wrapper lerd; o runner usa PHP nativo.

Dúvidas abertas: nenhuma necessária para continuar.

Próximo passo: ampliar E2E com CFOP interestadual, estoque insuficiente, descontos, CNPJ/CEP e respostas Focus 401/422/429/5xx/timeout, incluindo recuperação. Esses cenários têm testes de integração, mas ainda não cobertura completa no navegador.

Documentação: tests/Browser/README.md e docs/testing/auditoria-fiscal-2026-10-30.md.
