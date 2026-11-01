{{-- Coluna agrupada: número, data, cliente completo e (opcional) empresa. Tudo escapado pelo Blade. --}}
<div class="px-3 py-2" style="min-width: 13rem; max-width: 100%;">
    <div class="flex flex-wrap gap-x-2" style="align-items: baseline;">
        <span class="text-sm font-bold leading-6 text-gray-950 dark:text-white" style="overflow-wrap: anywhere;">{{ $title }}</span>
        @if (filled($date))
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $date }}</span>
        @endif
    </div>
    @if (filled($client))
        <div class="whitespace-normal break-words text-sm leading-5 text-gray-950 dark:text-white" style="overflow-wrap: anywhere;">{{ $client }}</div>
    @else
        <div class="text-sm italic leading-5 text-gray-500 dark:text-gray-400">Sem cliente</div>
    @endif
    @if (filled($company))
        <div class="whitespace-normal text-xs text-gray-500 dark:text-gray-400" style="overflow-wrap: anywhere;">{{ $company }}</div>
    @endif
</div>
