<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Приходная накладная №{{ $purchase->id }}</title>
    <style>
        @page { margin: 1cm; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.4;
        }
        .header-table { width: 100%; margin-bottom: 30px; border-bottom: 2px solid #444; padding-bottom: 10px; }
        .company-info { font-size: 14px; font-weight: bold; color: #000; }
        .document-title { font-size: 20px; font-weight: bold; text-align: right; text-transform: uppercase; color: #444; }

        .info-section { width: 100%; margin-bottom: 20px; }
        .info-box { vertical-align: top; width: 50%; }
        .info-label { color: #888; font-size: 9px; text-transform: uppercase; margin-bottom: 2px; }
        .info-value { font-size: 12px; font-weight: bold; }

        table.items-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items-table th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            padding: 10px 5px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
        }
        table.items-table td {
            padding: 12px 5px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        .text-right { text-align: right; }
        .total-section { margin-top: 30px; width: 300px; float: right; }
        .total-row { padding: 5px 0; border-bottom: 1px solid #eee; }
        .total-row.grand-total { border-bottom: none; font-size: 16px; font-weight: bold; color: #000; margin-top: 5px; }

        .footer { margin-top: 80px; font-size: 10px; border-top: 1px solid #eee; padding-top: 20px; }
        .signature-line { display: inline-block; width: 200px; border-bottom: 1px solid #333; margin-right: 20px; }
        .note { margin-top: 20px; padding: 10px; background: #fdfdfd; border-left: 3px solid #ccc; color: #666; font-style: italic; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td class="company-info">
                {{ mb_strtoupper($settings['site_name'] ?? 'Мой Магазин') }}<br>
                <small style="font-weight: normal; font-size: 10px;">
                    ИНН: {{ $settings['site_inn'] ?? '—' }} | {{ $settings['contact_address'] ?? '' }}
                </small>
            </td>
            <td class="document-title">Приход №{{ $purchase->id }}</td>
        </tr>
    </table>

    <table class="info-section">
        <tr>
            <td class="info-box">
                <div class="info-label">Поставщик</div>
                <div class="info-value">{{ $purchase->supplier->name }}</div>
            </td>
            <td class="info-box" style="text-align: right;">
                <div class="info-label">Дата документа</div>
                <div class="info-value">{{ $purchase->created_at->format('d.m.Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th width="40">№</th>
                <th>Наименование товара</th>
                <th width="100">Артикул</th>
                <th width="60" class="text-right">Кол-во</th>
                <th width="100" class="text-right">Цена</th>
                <th width="100" class="text-right">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchase->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td style="font-weight: bold;">{{ $item->product->name }}</td>
                <td>{{ $item->product->sku }}</td>
                <td class="text-right">{{ $item->quantity }}</td>
                <td class="text-right">{{ number_format($item->buy_price, 2, '.', ' ') }}</td>
                <td class="text-right">{{ number_format($item->total, 2, '.', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-section">
        <div class="total-row">
            <span style="color: #888;">Промежуточный итог:</span>
            <span style="float: right;">{{ number_format($purchase->total_amount, 2, '.', ' ') }}</span>
        </div>
        <div class="total-row">
            <span style="color: #888;">Оплачено:</span>
            <span style="float: right;">{{ number_format($purchase->paid_amount, 2, '.', ' ') }}</span>
        </div>
        <div class="total-row grand-total">
            <span>ИТОГО К ПРИЕМКЕ:</span>
            <span style="float: right;">{{ number_format($purchase->total_amount, 2, '.', ' ') }} с</span>
        </div>
    </div>

    <div style="clear: both;"></div>

    @if($purchase->notes)
    <div class="note">
        <strong>Комментарий:</strong> {{ $purchase->notes }}
    </div>
    @endif

    <div class="footer">
        <table width="100%">
            <tr>
                <td>Ответственный: <span class="signature-line"></span></td>
                <td class="text-right">М.П. <span class="signature-line"></span></td>
            </tr>
        </table>
    </div>
</body>
</html>
