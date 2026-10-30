# Testes de navegador fiscal

Execute na raiz:

```bash
tests/Browser/run.sh
```

Requisitos: Bash, curl, bun, PHP CLI 8.2+ com as extensões do projeto (incluindo SQLite) e Chromium. O runner usa /usr/bin/php; configure BROWSER_TEST_PHP para outro PHP nativo. Não use o wrapper lerd sem repassar as variáveis de ambiente ao contêiner.

Playwright 1.56.1 é instalado somente num diretório temporário; package.json e lockfiles do projeto não são alterados. O Chromium disponível nesta máquina é usado pelo caminho padrão do runner. Configure CHROMIUM_PATH se o navegador estiver em outro local.

## Isolamento

O runner cria SQLite, armazenamento, sessões, caches e credenciais sintéticas em /tmp. O .env da aplicação não é carregado. Uma verificação HTTP confirma a identidade do servidor antes do navegador acessar qualquer formulário. A porta é escolhida dinamicamente; BROWSER_TEST_PORT permite testar a recusa de uma porta ocupada.

O servidor carrega exclusivamente o bootstrap de tests/Browser, inclusive para caminhos .php. A Focus é simulada no servidor, com autenticação sintética e chave de acesso estável por referência. Operações não previstas e hosts externos são bloqueados. O navegador também bloqueia hosts externos; a única URL simulada de PDF da Focus é atendida localmente pelo Playwright.

Não há opção para rodar estes testes contra produção ou o painel normal. Os testes reais opt-in da Focus são separados.

## Cobertura executada

Em 1440×900 e 390×844:
- Login por formulário real.
- CPF, UF e IBGE inválidos: erros simultâneos e preservação dos dados.
- Correção e cadastro de cliente válido.
- Cadastro de produto e venda pelo painel.
- Produto sem NCM: orientação específica, sem abrir aba nem oferecer PDF.
- Correção do NCM pelo formulário.
- Prévia DANFE: PDF e abertura em nova aba.
- Confirmação de emissão com clique duplo: somente um POST fiscal por venda.
- Estado autorizado, bloqueio de nova emissão e abertura da DANFE simulada.
- Ausência de erros JavaScript e overflow horizontal no formulário do cliente.

A validação da interface com respostas 401/422/429/5xx/timeout, CFOP interestadual, estoque insuficiente, descontos e outros tipos de documento ainda precisa ser ampliada. Esses casos não devem ser considerados cobertos por este runner apenas porque existem testes de integração.

As capturas permanecem em um diretório /tmp/younotas-browser-evidence-* indicado na saída. O banco, as sessões e o pacote temporário são removidos e o servidor é encerrado mesmo em falhas. Avatares externos aparecem sem imagem nas capturas devido ao bloqueio deliberado de rede; isso não comprova uma falha no produto.

O nome login.mjs foi preservado da primeira entrega, mas o arquivo executa toda a sequência acima.
