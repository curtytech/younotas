<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

$dir = getenv('BROWSER_TEST_DIR');
if (! $dir || ! str_starts_with($dir, '/tmp/younotas-browser-') || ! is_dir($dir) || is_link($dir) || realpath($dir) !== $dir) {
    throw new RuntimeException('Diretório isolado de teste obrigatório.');
}
if (is_file($dir.'/config.php') || is_link($dir.'/database.sqlite') || is_link($dir.'/storage')) {
    throw new RuntimeException('Cache ou armazenamento de teste não confiável.');
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)), 'APP_CONFIG_CACHE' => $dir.'/config.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dir.'/database.sqlite', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'younotas_browser_test', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_DOMAIN' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr', 'MAIL_MAILER' => 'array', 'FILESYSTEM_DISK' => 'local', 'FOCUS_NFE_API_KEY' => '', 'FOCUS_NFE_API_PASSWORD' => '', 'FOCUS_NFE_API_KEY_HOMOLOGACAO' => '', 'FOCUS_NFE_API_KEY_PRODUCAO' => '', 'APP_URL' => getenv('BROWSER_TEST_URL') ?: 'http://127.0.0.1', 'APP_ROUTES_CACHE' => $dir.'/routes.php', 'APP_EVENTS_CACHE' => $dir.'/events.php', 'APP_SERVICES_CACHE' => $dir.'/services.php', 'APP_PACKAGES_CACHE' => $dir.'/packages.php'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($dir);
$app->useStoragePath($dir.'/storage');
$app->make(Kernel::class)->bootstrap();
if ($app->environment() !== 'testing' || $app->configurationIsCached()) {
    throw new RuntimeException('Ambiente de teste inválido.');
}
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $dir.'/database.sqlite'
    || config('queue.default') !== 'sync' || config('session.driver') !== 'file') {
    throw new RuntimeException('Configuração não isolada.');
}
Http::preventStrayRequests();
Http::fake(function (Request $request) use ($dir) {
    $url = parse_url($request->url());
    if (($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== 'homologacao.focusnfe.com.br') {
        throw new StrayRequestException('Destino externo não previsto no teste.');
    }
    if ($request->header('Authorization') !== ['Basic '.base64_encode('browser-synthetic-token:')]) {
        throw new RuntimeException('Autenticação fiscal diferente da credencial sintética.');
    }
    $path = $url['path'] ?? '';
    parse_str($url['query'] ?? '', $query);
    file_put_contents($dir.'/requests.jsonl', json_encode(['method' => $request->method(), 'path' => $path]).PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($path === '/v2/nfe/danfe' && $request->method() === 'POST') {
        return Http::response(file_get_contents($dir.'/danfe.pdf'), 200, ['Content-Type' => 'application/pdf']);
    }
    if ($path === '/v2/nfe' && $request->method() === 'POST') {
        return Http::response(['status' => 'autorizado', 'status_sefaz' => '100',
            'chave_nfe' => substr(strtr(hash('sha256', (string) ($query['ref'] ?? '')), 'abcdef', '123456'), 0, 44), 'caminho_danfe' => '/arquivos/e2e.pdf'], 201);
    }
    throw new StrayRequestException('Operação fiscal não prevista no teste.');
});

return $app;
