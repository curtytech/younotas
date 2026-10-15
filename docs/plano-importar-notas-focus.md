# Auditoria e Plano: Importar Notas da Focus

## 1. Resultado da auditoria atual

### Verificações executadas

- `php artisan test`: 22 testes passam, com 88 asserções.
- Todos os testes são reportados como `deprecated` por causa do uso de `PDO::MYSQL_ATTR_SSL_CA` em `config/database.php`.
- Os migrations, seeders e factories auditados não possuem erro de sintaxe.
- Há alterações já existentes no worktree em `app/Providers/Filament/AdminPanelProvider.php`; elas não foram modificadas.

### Problemas que devem ser corrigidos antes ou junto da importação

#### Alta: `SaleItemFactory` depende de produto com ID fixo

`database/factories/SaleItemFactory.php` cria uma venda via factory, mas fixa `product_id` em `1`. Em um banco limpo, a factory pode falhar por chave estrangeira ou apontar para um produto inexistente.

**Melhoria:** usar `Product::factory()` ou permitir que o estado da factory forneça `product_id`.

#### Alta: seed de movimento de estoque não é realmente idempotente

`AllTablesSeeder` procura a referência `STOCK-SEED-001`, mas a factory usada para criar o movimento gera outra referência aleatória. Executar o seeder novamente pode criar registros duplicados.

**Melhoria:** passar `reference => 'STOCK-SEED-001'` para a factory ou usar `updateOrCreate` com os dados completos.

#### Alta: credencial da Focus versionada

`database/seeders/FocusTestCompanySeeder.php` contém uma API key. Mesmo sendo destinada a homologação, deve ser considerada comprometida.

**Melhoria:** revogar/rotacionar a chave, removê-la do código e carregar o valor via `.env` ou secret manager. O seeder deve falhar de forma controlada quando a variável não existir, ou usar uma chave explicitamente fictícia.

#### Alta: migration inicial mistura schema e criação de usuários

`0001_01_01_000000_create_users_table.php` cria usuários dentro do migration e usa `Hash::make()` sem import explícito de `Illuminate\Support\Facades\Hash`.

**Melhoria:** deixar migrations responsáveis apenas pelo schema e mover usuários padrão para um seeder. Se a criação permanecer temporariamente, adicionar o import explícito e cobrir uma instalação limpa.

#### Alta: tabela `vehicle` fora da convenção do model

`2026_07_29_000019_create_fleet_and_appeals_tables.php` cria a tabela singular `vehicle`, enquanto a relação `User::vehicles()` usa a convenção Eloquent `vehicles`.

**Melhoria:** renomear a tabela para `vehicles` em uma migration corretiva ou declarar o nome da tabela explicitamente na relação/model. Adicionar teste que acessa `User::first()->vehicles`.

#### Média: migration de arquitetura de O.S. é destrutiva

`2026_07_29_000018_create_service_orders_architecture.php` remove tabelas e dados antigos de serviços, técnicos, O.S., anexos e webhooks antes de recriá-los. O `down()` também não restaura a estrutura anterior.

**Melhoria:** em produção, separar migração de dados da alteração estrutural e exigir backup. Se a remoção for uma decisão definitiva, documentar a migration como irreversível e não prometer rollback completo.

#### Média: rollback das views não é seguro

As migrations `000017` e `000018` recriam a view `fiscal_documents` em momentos diferentes. Ao reverter a arquitetura de O.S., migrations anteriores podem tentar recriar uma view que referencia `services`, já removida.

**Melhoria:** testar rollback em banco limpo e definir uma estratégia única para a view. A futura importação deve substituir a dependência de uma view derivada por armazenamento persistente.

#### Média: banco de testes não valida as regras do banco de produção

Os testes usam SQLite em memória. Os `enum`, foreign keys, views e comportamentos de índices podem divergir de MySQL/MariaDB.

**Melhoria:** manter os testes rápidos em SQLite, mas adicionar job de CI com MySQL/MariaDB para migrations, constraints, views, índices únicos e rollback.

#### Média: testes degradados por configuração de PDO

O acesso direto a `PDO::MYSQL_ATTR_SSL_CA` gera depreciações em todas as execuções, mesmo quando a opção não está configurada.

**Melhoria:** montar a opção SSL apenas quando a constante disponível e a variável estiver preenchida, usando a constante compatível com a versão do PHP.

#### Baixa: precisão e semântica fiscal

Diversas colunas monetárias usam `decimal(10,2)`, potencialmente insuficiente para empresas com valores maiores. Além disso, `FiscalDocument::$casts['issued_at']` usa `date`, embora uma das fontes seja timestamp.

**Melhoria:** definir a precisão fiscal necessária e usar `datetime` para preservar hora e timezone quando essa informação existir.

## 2. Limites da API da Focus

### NF-e emitidas

- `GET /nfe/{referencia}` consulta uma nota individual, opcionalmente com `completa=1`.
- A API não apresenta uma listagem geral de NF-e emitidas por período.
- `GET /backups/{cnpj}.json` lista backups mensais de XMLs e DANFEs de NF-e, NFC-e, CT-e e MDF-e.
- Para uma carga histórica, a estratégia principal deve ser baixar os ZIPs mensais e processar os XMLs.
- Quando as referências forem conhecidas, a consulta individual pode complementar ou atualizar os dados.

### NFS-e emitidas

- `GET /nfse/{referencia}` consulta apenas uma referência conhecida.
- A documentação consultada não apresenta uma listagem histórica em lote nem backup equivalente para NFS-e.
- A primeira versão deve aceitar referências informadas pelo usuário ou XMLs fornecidos manualmente.
- A descoberta automática de NFS-e sem referências deve ser tratada como dependência de confirmação com a Focus.

### Fora do escopo inicial

- `/nfes_recebidas` é para notas em que a empresa é destinatária, não para recuperar NF-e emitidas pela própria empresa.
- O endpoint `/nfe/importacao` importa XML para dentro da Focus; ele não importa notas da Focus para o younNotas.

## 3. Decisão arquitetural recomendada

Não criar vendas ou ordens de serviço artificiais para representar notas antigas. Isso poderia alterar estoque, financeiro, clientes e regras comerciais.

Recomenda-se transformar o histórico fiscal em uma entidade persistente própria, capaz de representar tanto documentos emitidos pelo younNotas quanto documentos importados:

- substituir gradualmente a `VIEW fiscal_documents` por uma tabela persistente `fiscal_documents`, ou criar uma tabela persistente de importados e uma camada de leitura unificada;
- manter `source_type` e `source_id` nulos para documentos sem vínculo com venda/O.S.;
- identificar NF-e por chave de acesso e NFS-e por combinação de emissor, referência, número e demais identificadores disponíveis;
- guardar o XML original em storage privado, não no JSON da tabela;
- guardar hash SHA-256 do XML para idempotência e auditoria;
- separar metadados indexáveis de payloads brutos criptografados;
- registrar origem (`focus_api`, `xml_upload`, `manual`) e ambiente (`produção` ou `homologação`).

Campos mínimos sugeridos:

- `user_id`;
- `document_type` (`NF-e` ou `NFS-e`);
- `provider`;
- `source_type` e `source_id`;
- `focus_reference`;
- `access_key`;
- `document_number` e `series`;
- `status`;
- `issued_at` e `authorized_at`;
- `issuer_document` e `recipient_document`;
- `total_amount`;
- `xml_path`, `pdf_url` e `cancelation_xml_path`;
- `payload_hash`;
- `raw_response` e metadados de importação criptografados;
- `imported_at`, `last_synced_at` e `last_error`.

Constraints e índices recomendados:

- unicidade por empresa e chave de acesso quando a chave existir;
- unicidade por empresa, provedor, tipo e referência quando a referência existir;
- índice por empresa, tipo, status e data de emissão;
- índice por hash do XML;
- nenhuma chave única deve tratar `NULL` como uma referência válida compartilhada.

## 4. Plano de implementação

### Fase 0: saneamento técnico

1. Remover a credencial do seeder e rotacionar a chave exposta.
2. Corrigir `SaleItemFactory`, seed de estoque e relação/tabela de veículos.
3. Ajustar a configuração de PDO para eliminar as depreciações.
4. Cobrir instalação limpa, `migrate:fresh --seed` e acesso às relações principais.
5. Decidir se a migration destrutiva de O.S. será mantida como decisão de produto ou substituída por migração segura.

### Fase 1: persistência fiscal

1. Criar a migration da tabela persistente e os índices.
2. Criar model, casts, factory e policies.
3. Migrar os documentos atualmente representados pela view de vendas e O.S. para a nova tabela.
4. Preservar os vínculos existentes com `Sale` e `ServiceOrder`.
5. Atualizar `FiscalDocumentResource` para ler a tabela e lidar com documentos sem origem.
6. Escrever testes para idempotência, isolamento por empresa e documentos órfãos.

### Fase 2: cliente Focus e ingestão de NF-e

1. Extrair um cliente Focus reutilizável para autenticação, timeout, retries e tratamento de erros.
2. Implementar consulta de backups por CNPJ.
3. Criar job de download de cada backup mensal.
4. Validar ZIP, XML, namespace, CNPJ do emitente e ambiente antes de persistir.
5. Extrair chave, número, série, status, datas, valores e links disponíveis.
6. Persistir cada nota dentro de transação e usar upsert/idempotência por chave ou hash.
7. Não reprocessar arquivos já importados, mas permitir atualização quando o XML mudar por cancelamento ou evento.
8. Registrar contagem de importadas, atualizadas, ignoradas e falhas por lote.

### Fase 3: fluxo na interface

1. Adicionar ação `Importar notas da Focus` ao Histórico Fiscal.
2. Permitir escolher tipo, ambiente e meses para NF-e.
3. Exibir confirmação com a empresa/CNPJ e quantidade estimada.
4. Enfileirar a importação; não executar downloads grandes na requisição web.
5. Mostrar progresso, último erro e opção de repetir somente falhas.
6. Permitir importar uma referência individual de NF-e ou NFS-e.
7. Permitir upload de XML quando a NFS-e não puder ser descoberta pela API.

### Fase 4: vínculo e reconciliação

1. Tentar vínculo automático por referência Focus.
2. Tentar vínculo por chave, número/série e data, com regras conservadoras.
3. Nunca criar venda/O.S. automaticamente sem confirmação do usuário.
4. Criar tela de documentos sem vínculo para associação manual.
5. Manter histórico de alterações de status e eventos de cancelamento.

### Fase 5: testes e operação

1. Testar respostas autorizada, cancelada, rejeitada e incompleta.
2. Testar ZIP inválido, XML duplicado, XML alterado e erro de rede.
3. Testar isolamento entre empresas e tokens Focus.
4. Testar reexecução do mesmo lote sem duplicar dados.
5. Testar links assinados e armazenamento privado.
6. Executar a suíte em SQLite e MySQL/MariaDB.
7. Adicionar logs sem API key, XML completo ou dados sensíveis.

## 5. Critérios de aceite

- Uma importação repetida não duplica documentos.
- Uma nota cancelada atualiza seu status sem perder o XML autorizado original.
- Documentos importados não alteram estoque, financeiro, vendas ou O.S.
- Um usuário nunca visualiza documentos de outra empresa.
- Falhas individuais não abortam todo o lote.
- O usuário consegue identificar quais documentos foram importados, atualizados, ignorados ou falharam.
- NF-e com XML completo fica disponível para consulta/download privado.
- NFS-e pode ser importada por referência ou XML, com a limitação da API explicitamente apresentada na interface.

## 6. Ordem sugerida de execução

1. Saneamento de migrations, seeders, factories e segredos.
2. Modelo persistente de documentos fiscais.
3. Migração do histórico atual para o modelo persistente.
4. Importação de NF-e via backups mensais.
5. Importação individual por referência.
6. Interface, vínculo manual e reconciliação.
7. Suporte complementar a NFS-e via referência/XML.
