<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recibo {{ $receipt['number'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #0f172a; background: #f1f5f9; margin: 0; padding: 32px 16px; }
        .sheet { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 40px; }
        header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #0f172a; padding-bottom: 16px; margin-bottom: 24px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin: 24px 0 8px; }
        p { margin: 2px 0; font-size: 14px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        td, th { padding: 10px 0; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { font-size: 12px; text-transform: uppercase; color: #64748b; font-weight: 600; }
        .total td { font-weight: 700; font-size: 16px; border-bottom: none; }
        .stamp { display: inline-block; margin-top: 16px; padding: 6px 12px; border: 2px solid #dc2626; color: #dc2626; border-radius: 6px; font-weight: 700; text-transform: uppercase; font-size: 12px; }
        footer { margin-top: 32px; font-size: 12px; color: #64748b; }
        .actions { max-width: 720px; margin: 0 auto 16px; text-align: right; }
        .actions button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; }
        @media print { body { background: #fff; padding: 0; } .sheet { border: none; padding: 0; } .actions { display: none; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Imprimir / salvar PDF</button></div>

    <div class="sheet">
        <header>
            <div>
                <h1>Recibo de pagamento</h1>
                <p class="muted">Nº {{ $receipt['number'] }}</p>
            </div>
            <div class="right">
                <p><strong>{{ $issuer['issuer_name'] }}</strong></p>
                @if(!empty($issuer['issuer_document']))<p>{{ $issuer['issuer_document'] }}</p>@endif
                @if(!empty($issuer['issuer_address']))<p>{{ $issuer['issuer_address'] }}</p>@endif
                @if(!empty($issuer['issuer_email']))<p>{{ $issuer['issuer_email'] }}</p>@endif
            </div>
        </header>

        <h2>Cliente</h2>
        <p><strong>{{ $customer['name'] }}</strong></p>
        <p>{{ $customer['email'] }}</p>
        @if($customer['document'])<p>CPF {{ $customer['document'] }}</p>@endif

        <h2>Pagamento</h2>
        <p>Data: {{ $receipt['paid_at']?->format('d/m/Y H:i') }}</p>
        <p>Forma: {{ $method }}</p>
        @if($receipt['payment_id'])<p>Transação: {{ $receipt['payment_id'] }}</p>@endif
        @if($receipt['period'][0] && $receipt['period'][1])
            <p>Período: {{ $receipt['period'][0]->format('d/m/Y') }} a {{ $receipt['period'][1]->format('d/m/Y') }}</p>
        @endif

        <h2>Itens</h2>
        <table>
            <thead><tr><th>Descrição</th><th class="right">Valor</th></tr></thead>
            <tbody>
                <tr><td>{{ $receipt['description'] }}</td><td class="right">R$ {{ number_format($receipt['gross'], 2, ',', '.') }}</td></tr>
                @if($receipt['credit'] > 0)
                    <tr><td>Crédito do plano anterior</td><td class="right">- R$ {{ number_format($receipt['credit'], 2, ',', '.') }}</td></tr>
                @endif
                <tr class="total"><td>Total pago</td><td class="right">R$ {{ number_format($receipt['amount'], 2, ',', '.') }}</td></tr>
            </tbody>
        </table>

        @if($refunded)
            <span class="stamp">Estornado</span>
        @endif

        <footer>Este recibo comprova o pagamento e não substitui a nota fiscal.</footer>
    </div>
</body>
</html>
