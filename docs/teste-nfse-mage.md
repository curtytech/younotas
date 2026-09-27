# Teste direto do webservice de Magé

Comando experimental, sem interface e independente da Focus. Recebe XML ABRASF preparado externamente; não converte O.S./JSON Focus, não gera assinatura digital e não altera documentos fiscais do sistema. A presença de Signature é conferida, mas sua validade criptográfica não é verificada localmente. O município fará essa verificação.

## Diagnóstico

```bash
php artisan mage:nfse-teste diagnostico
php artisan mage:nfse-teste diagnostico --ambiente=producao
```

Em 08/09/2026, o WSDL de produção respondeu e listou GerarNfse, ConsultarLoteRps e ConsultarNfsePorRps. A conexão com o host de homologação na porta 8013 expirou em 12 segundos. Isso não comprova indisponibilidade para todas as redes. Ler WSDL não emite nota nem comprova credenciamento.

## Preparação e validação local

É necessário credenciamento municipal para webservice, XML ABRASF assinado pelo certificado do prestador e XSD municipal correspondente à versão. Mantenha certificado, XML e senha fora do Git. Configure no ambiente:

```dotenv
MAGE_NFSE_CERTIFICATE=/caminho/privado/cliente.pem
MAGE_NFSE_CERTIFICATE_PASSWORD=
```

O PEM deve conter certificado e chave privada, com acesso restrito ao usuário que executa o comando. O token Focus não autentica no município.

```bash
php artisan mage:nfse-teste validar \
  --xml=/caminho/privado/rps-assinado.xml \
  --xsd=/caminho/schemas/nfse.xsd
```

A validação verifica XML, raiz/namespace, limite de 512 KB e o XSD fornecido. Não confirma cadastro municipal, alíquota ou enquadramento. Use somente XSD confiável: seus imports são processados pelo libxml. Não forneça um schema permissivo apenas para passar na validação.

## Envio de teste

```bash
php artisan mage:nfse-teste enviar \
  --xml=/caminho/privado/rps-assinado.xml \
  --xsd=/caminho/schemas/nfse.xsd \
  --versao=3.02 --permitir-http
```

O endereço oficial de homologação usa HTTP; a opção reconhece que o XML de teste trafegará sem TLS. O certificado privado não é incluído no corpo SOAP. Não use dados reais nesse canal. Não há fallback para produção, retry ou redirecionamento. A emissão GerarNfse em produção é bloqueada neste comando de teste.

Retornos são salvos em `storage/app/private/mage-tests`, arquivos com permissão 0600. Nenhum XML, token ou senha é impresso ou registrado pelo comando. HTTP 2xx é apenas recebimento: examine o arquivo para identificar erros, protocolo ou autorização. Após timeout, consulte antes de repetir para evitar duplicidade. O código de saída zero de enviar significa retorno XML recebido, não autorização fiscal.

Consulta direta (também exige XML assinado e XSD):

```bash
php artisan mage:nfse-teste enviar --ambiente=producao \
  --operacao=ConsultarNfsePorRps --versao=2.02 \
  --xml=/caminho/privado/consulta.xml --xsd=/caminho/schemas/nfse.xsd
```

As raízes suportadas são GerarNfseEnvio, ConsultarNfseRpsEnvio e ConsultarLoteRpsEnvio. O envelope segue o WSDL real (RPC/encoded, namespace http://DefaultNamespace, SOAPAction vazio, parâmetros Nfsecabecmsg e Nfsedadosmsg). O manual descreve Document/Literal, em desacordo com esse WSDL.

## Limites documentais e dados da nota escaneada

A página 2 é DANFSe municipal. Permite ler inscrição municipal 1005235, Simples Nacional, manutenção automotiva (item 14.01), valor R$ 50,00, ISS não retido, alíquota 2% e ISS R$ 1,00. São dados daquela operação em maio/2026, não um cadastro completo de atividades/CNAEs ou regra universal para outras operações. A descrição/código de software e a alíquota de 5% do teste Focus não reproduzem essa operação. O PDF não contém certificado/chave, credenciamento nem XML assinado reutilizável.

O manual novo pede versaoDados 3.02; o ZIP de schemas vinculado pelo manual contém arquivos 2.01/2.02 datados de 2013. Não foi inventado um XSD 3.02 nem copiado o exemplo novo, que contém inconsistências. Para emissão atual, obtenha do município/provedor o XSD compatível, credenciamento e definição das tags aplicáveis. O endpoint alternativo ReceberDPS também é documentado, mas este comando utiliza SOAP, cujo contrato pôde ser verificado.

Fontes oficiais consultadas em 08/09/2026:

- https://nfs-e.mage.rj.gov.br/ver20240921/webservices/NFEServices.jws?wsdl
- https://nfs-e.mage.rj.gov.br/ver20240921/tmp/PortalServices/202602231508030387MAGE_31.pdf
- https://nfs-e.mage.rj.gov.br/ver20240921/tmp/PortalServices/202602231508250574MAGE.pdf
- https://nfs-e.mage.rj.gov.br/nfe/tmp/PortalServices/202010221625050005Schemas.zip
