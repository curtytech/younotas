<?php

namespace App\Console\Commands;

use App\Services\Mage\MageNfseProbe;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class TestMageNfse extends Command
{
    protected $signature = 'mage:nfse-teste
        {acao=diagnostico : diagnostico, validar ou enviar}
        {--ambiente=homologacao}
        {--operacao=GerarNfse}
        {--xml= : Caminho do XML ABRASF já preparado}
        {--xsd= : Caminho do XSD municipal confiável}
        {--versao=3.02}
        {--permitir-http : Permite dados de teste no HTTP da homologação}';

    protected $description = 'Diagnostica e testa o SOAP municipal de Magé, sem passar pela Focus.';

    public function handle(MageNfseProbe $probe): int
    {
        try {
            $action = $this->argument('acao');
            if ($action === 'diagnostico') {
                $operations = $probe->diagnose($this->option('ambiente'));
                $this->info('WSDL acessível. Operações: '.implode(', ', $operations));
                $this->warn('Acesso ao WSDL não confirma credenciamento nem disponibilidade de emissão.');

                return self::SUCCESS;
            }
            if (! in_array($action, ['validar', 'enviar'], true)) {
                throw new RuntimeException('Ação deve ser diagnostico, validar ou enviar.');
            }
            $path = $this->option('xml');
            if (! $path || ! is_file($path) || ! is_readable($path) || filesize($path) > 512 * 1024) {
                throw new RuntimeException('Informe --xml com arquivo legível de até 512 KB.');
            }
            $xml = file_get_contents($path);
            $probe->validate($xml, $this->option('operacao'), (string) $this->option('xsd'));
            $this->info('XML válido no XSD informado; enquadramento fiscal e assinatura não foram validados.');
            if ($action === 'validar') {
                return self::SUCCESS;
            }
            $reply = $probe->send($this->option('ambiente'), $this->option('operacao'), $xml, $this->option('versao'), $this->option('permitir-http'));
            $directory = storage_path('app/private/mage-tests');
            if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                throw new RuntimeException('Não foi possível criar diretório privado de respostas.');
            }
            $file = $directory.'/'.bin2hex(random_bytes(16)).'.xml';
            $handle = fopen($file, 'x');
            if (! $handle) {
                throw new RuntimeException('Não foi possível salvar a resposta.');
            }
            chmod($file, 0600);
            fwrite($handle, $reply);
            fclose($handle);
            $this->info('Retorno municipal salvo em: '.$file);
            $this->warn('HTTP 2xx não significa autorização: examine o XML para erros, protocolo ou NFS-e.');

            return self::SUCCESS;
        } catch (ConnectionException) {
            $this->error('Falha de conexão/timeout municipal. Sem reenvio automático. Se houve envio, consulte antes de repetir.');
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
