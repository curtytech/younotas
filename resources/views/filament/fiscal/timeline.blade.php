<div class="space-y-4">
    <p class="text-sm">{{ $document->company?->name }} · {{ $document->environment === 'producao' ? 'Produção' : ($document->environment === 'homologacao' ? 'Homologação' : 'Origem a reconciliar') }} · {{ $document->focus_reference }}</p>
    @forelse ($document->events()->limit(50)->get() as $event)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <p class="font-medium">{{ [
                'emission_queued' => 'Emissão enfileirada', 'emission_started' => 'Envio iniciado',
                'document_emissao' => 'Resposta da emissão', 'document_consulta' => 'Consulta realizada',
                'document_cancelamento' => 'Cancelamento solicitado', 'document_webhook' => 'Notificação recebida',
                'operation_failed' => 'Falha na operação', 'identity_reconciled' => 'Origem da emissão confirmada',
            ][$event->event] ?? $event->event }}</p>
            <p class="text-sm text-gray-500">{{ $event->created_at?->format('d/m/Y H:i:s') }}@if ($event->actor_id) · Usuário #{{ $event->actor_id }}@endif</p>
            @if (isset($event->context['status']))<p class="text-sm">Situação: {{ $event->context['status'] }}</p>@endif
            @if (isset($event->context['http_status']))<p class="text-sm">HTTP: {{ $event->context['http_status'] }}</p>@endif
        </div>
    @empty
        <p class="text-sm text-gray-500">Este documento ainda não possui eventos registrados.</p>
    @endforelse
</div>
