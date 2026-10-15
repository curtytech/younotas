<div class="grid gap-4 md:grid-cols-2">
    @foreach ($checks as $environment => $check)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <p class="font-medium">{{ $environment === 'producao' ? 'Produção' : 'Homologação' }}
                <span class="ml-2 text-sm {{ $check['ready'] ? 'text-success-600' : 'text-danger-600' }}">{{ $check['ready'] ? 'Pronto para emitir' : 'Pendências de configuração' }}</span>
            </p>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($check['items'] as $item)
                    @continue($item['ok'])
                    <li>✗ {{ $item['label'] }} <span class="text-gray-500">— {{ $item['hint'] }}</span></li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>
