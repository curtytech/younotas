<div class="space-y-3 text-sm">
    <p class="font-medium">{{ $diagnosis['summary'] }}</p>
    @if ($diagnosis['details'] !== [])
        <ul class="list-disc space-y-1 pl-5">
            @foreach ($diagnosis['details'] as $detail)
                <li>{{ $detail }}</li>
            @endforeach
        </ul>
    @endif
    <p class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><span class="font-medium">Próximo passo:</span> {{ $diagnosis['action'] }}</p>
</div>
