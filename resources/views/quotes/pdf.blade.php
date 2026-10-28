<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Orçamento {{ $quote->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #222; font-size: 11px; }
        .header { border-bottom: 2px solid #222; padding-bottom: 12px; margin-bottom: 18px; }
        .logo { max-width: 130px; max-height: 65px; float: right; }
        h1 { margin: 0 0 5px; font-size: 22px; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        .muted { color: #666; }
        .clear { clear: both; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #ddd; padding: 8px 5px; text-align: left; }
        th { background: #f1f1f1; }
        .right { text-align: right; }
        .total { font-size: 15px; font-weight: bold; margin-top: 15px; text-align: right; }
        .notes { margin-top: 22px; white-space: pre-line; }
    </style>
</head>
<body>
    <div class="header">
        @if ($logoDataUri)
            <img class="logo" src="{{ $logoDataUri }}" alt="Logo">
        @endif
        <h1>{{ $quote->company->name }}</h1>
        <div>CNPJ: {{ $quote->company->cnpj ?: 'Não informado' }}</div>
        <div>
            {{ collect([$quote->company->address, $quote->company->address_number, $quote->company->address_complement, $quote->company->neighborhood, $quote->company->city, $quote->company->state, $quote->company->zip_code])->filter()->implode(', ') }}
        </div>
        @if ($quote->company->phone)
            <div>Telefone: {{ $quote->company->phone }}</div>
        @endif
        <div class="clear"></div>
    </div>

    <h1>Orçamento {{ $quote->number }}</h1>
    <div class="muted">
        Data: {{ $quote->quote_date?->format('d/m/Y') }}
        @if ($quote->valid_until) | Validade: {{ $quote->valid_until->format('d/m/Y') }} @endif
    </div>

    <h2>Cliente</h2>
    <div>{{ $quote->client->name }}</div>
    @if ($quote->client->document)<div>Documento: {{ $quote->client->document }}</div>@endif
    @if ($quote->client->phone)<div>Telefone: {{ $quote->client->phone }}</div>@endif

    <h2>Itens</h2>
    <table>
        <thead>
            <tr><th>Descrição</th><th>Un.</th><th class="right">Qtd.</th><th class="right">Unitário</th><th class="right">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($quote->items as $item)
                <tr>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->unit }}</td>
                    <td class="right">{{ number_format((float) $item->quantity, 3, ',', '.') }}</td>
                    <td class="right">R$ {{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                    <td class="right">R$ {{ number_format((float) $item->total_amount, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="total">Total: R$ {{ number_format((float) $quote->total_amount, 2, ',', '.') }}</div>
    @if ($quote->notes)
        <div class="notes"><strong>Observações:</strong><br>{{ $quote->notes }}</div>
    @endif
</body>
</html>
