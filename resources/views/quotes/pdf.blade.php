@php
    $money = fn ($value): string => 'R$ '.number_format((float) $value, 2, ',', '.');
    $quantity = fn ($value): string => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $subtotal = (float) $quote->items->sum('total_amount');
    $company = $quote->company;
    $client = $quote->client;
    $clientAddress = $client->hasProvisionalData() ? '' : collect([
        trim(collect([$client->address, $client->address_number])->filter()->implode(', ')),
        $client->neighborhood,
        collect([$client->city, $client->state])->filter()->implode('/'),
    ])->filter()->implode(' · ');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Orçamento {{ $quote->number }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10.5px; line-height: 1.45; }
        .muted { color: #6b7280; }
        .header { width: 100%; border-bottom: 3px solid #f59e0b; padding-bottom: 12px; }
        .header td { vertical-align: top; border: 0; padding: 0; }
        .logo { max-width: 140px; max-height: 70px; }
        .company { font-size: 15px; font-weight: bold; margin-bottom: 3px; }
        .doc { text-align: right; }
        .doc-title { font-size: 20px; font-weight: bold; letter-spacing: 1px; color: #111827; }
        .doc-number { font-size: 13px; color: #b45309; font-weight: bold; }
        .cards { width: 100%; margin: 16px 0 6px; border-collapse: separate; border-spacing: 0; }
        .cards td { vertical-align: top; padding: 10px 12px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .6px; color: #6b7280; margin-bottom: 2px; }
        .items { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .items th { background: #111827; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; padding: 7px 6px; text-align: left; }
        .items td { border-bottom: 1px solid #e5e7eb; padding: 7px 6px; }
        .items tr:nth-child(even) td { background: #f9fafb; }
        .right { text-align: right !important; }
        .type { font-size: 8.5px; color: #6b7280; }
        .totals { width: 42%; margin-left: 58%; margin-top: 12px; border-collapse: collapse; }
        .totals td { padding: 4px 6px; }
        .totals .grand td { border-top: 2px solid #111827; font-size: 14px; font-weight: bold; padding-top: 8px; }
        .notes { margin-top: 22px; padding: 10px 12px; border-left: 3px solid #f59e0b; background: #fffbeb; white-space: pre-line; }
        .footer { position: fixed; bottom: -10px; left: 0; right: 0; text-align: center; font-size: 8.5px; color: #9ca3af; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 60%">
                @if ($logoDataUri)
                    <img class="logo" src="{{ $logoDataUri }}" alt="Logo de {{ $company->name }}"><br>
                @endif
                <div class="company">{{ $company->name }}</div>
                @if ($company->cnpj)<div class="muted">CNPJ {{ $company->cnpj }}</div>@endif
                <div class="muted">
                    {{ collect([$company->address, $company->address_number, $company->address_complement, $company->neighborhood, $company->city, $company->state, $company->zip_code])->filter()->implode(', ') }}
                </div>
                @if ($company->phone)<div class="muted">Telefone {{ $company->phone }}</div>@endif
            </td>
            <td class="doc">
                <div class="doc-title">ORÇAMENTO</div>
                <div class="doc-number">{{ $quote->number }}</div>
                <div class="muted">Emitido em {{ $quote->quote_date?->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="cards">
        <tr>
            <td style="width: 64%">
                <div class="label">Cliente</div>
                <strong>{{ $client->name }}</strong>
                @if ($client->document)<br>{{ strtoupper((string) $client->document_type) }} {{ $client->document }}@endif
                @if ($clientAddress !== '')<br><span class="muted">{{ $clientAddress }}</span>@endif
                @if ($client->phone || ($client->email && ! $client->hasProvisionalData()))
                    <br><span class="muted">{{ collect([$client->phone, $client->hasProvisionalData() ? null : $client->email])->filter()->implode(' · ') }}</span>
                @endif
            </td>
            <td style="width: 36%; border-left: 0">
                <div class="label">Validade</div>
                <strong>{{ $quote->valid_until ? $quote->valid_until->format('d/m/Y') : 'Sem prazo definido' }}</strong>
                <div class="label" style="margin-top: 8px">Valor total</div>
                <strong style="font-size: 13px">{{ $money($quote->total_amount) }}</strong>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th style="width: 46%">Descrição</th><th>Un.</th><th class="right">Qtd.</th><th class="right">Unitário</th><th class="right">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($quote->items as $item)
                <tr>
                    <td>{{ $item->name }}<br><span class="type">{{ $item->item_type === 'service' ? 'Serviço' : 'Produto' }}</span></td>
                    <td>{{ $item->unit }}</td>
                    <td class="right">{{ $quantity($item->quantity) }}</td>
                    <td class="right">{{ $money($item->unit_price) }}</td>
                    <td class="right">{{ $money($item->total_amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @if ((float) $quote->discount_amount > 0)
            <tr><td>Subtotal</td><td class="right">{{ $money($subtotal) }}</td></tr>
            <tr><td>Desconto</td><td class="right">− {{ $money($quote->discount_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="right">{{ $money($quote->total_amount) }}</td></tr>
    </table>

    @if ($quote->notes)
        <div class="notes"><strong>Observações</strong><br>{{ $quote->notes }}</div>
    @endif

    <div class="footer">Orçamento {{ $quote->number }} · {{ $company->name }} · Este documento não é nota fiscal.</div>
</body>
</html>
