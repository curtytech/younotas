### Corrigido

- A importacao de clientes por CSV resolve os codigos IBGE pela lista local em `database/data/municipios-ibge.json`, sem depender da Focus ou do servidor do IBGE durante `migrate --seed`.
- Clones novos incluem as pastas de cache, sessões e views, sem versionar os arquivos gerados.
