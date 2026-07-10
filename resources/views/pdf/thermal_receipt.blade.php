<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        html { color-scheme: light; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            width: 72mm;
            margin: 0 auto;
            padding: 4mm 4mm 6mm;
            font-size: 11px;
            color: #000;
            background: #fff;
            line-height: 1.35;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .divider { border-top: 1px dashed #000; margin: 2.5mm 0; }
        .divider-solid { border-top: 1.5px solid #000; margin: 3mm 0 2mm; }

        table { width: 100%; border-collapse: collapse; }

        .shop-header { margin-bottom: 3mm; }
        .shop-name { font-size: 15px; font-weight: bold; margin-bottom: 1mm; }
        .shop-meta { font-size: 9px; color: #222; line-height: 1.5; }

        table.meta-table td { font-size: 9.5px; padding: 0.3mm 0; }

        .receipt-title { font-size: 12px; margin-bottom: 2mm; }

        table.items-table th {
            text-align: left;
            border-bottom: 1px solid #000;
            font-size: 9px;
            text-transform: uppercase;
            padding-bottom: 1mm;
            white-space: nowrap;
        }
        table.items-table th.right { text-align: right; }
        table.items-table th.center { text-align: center; }

        .item-row td { padding: 1.5mm 0; font-size: 10.5px; }
        .item-row td.center { text-align: center; }
        .item-row td.right { text-align: right; }

        table.summary-table td { padding: 0.7mm 0; font-size: 11px; }
        table.summary-table td.right { text-align: right; }
        .total-row td { font-size: 15px; font-weight: bold; padding-top: 1.5mm; }

        .footer-text { margin-top: 4mm; font-size: 9.5px; white-space: pre-wrap; font-style: italic; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="shop-header center">
        <div class="shop-name">{{ $settings['receipt_title'] ?? $settings['site_name'] ?? 'Мой Магазин' }}</div>
        <div class="shop-meta">
            @if(!empty($settings['site_inn']))
                ИНН: {{ $settings['site_inn'] }}<br>
            @endif
            @if(!empty($settings['contact_address']))
                {{ $settings['contact_address'] }}
            @endif
        </div>
    </div>

    <div class="divider"></div>

    <div class="center bold receipt-title">ТОВАРНЫЙ ЧЕК № {{ $order->order_number ?: $order->id }}</div>

    <table class="meta-table">
        <tr>
            <td colspan="2">Дата: {{ now()->format('d.m.Y H:i') }}</td>
        </tr>
        <tr>
            <td colspan="2">Кассир: {{ $order->staff->name ?? 'Админ' }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Наименование</th>
                <th class="center">Кол-во</th>
                <th class="right">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
            <tr class="item-row">
                <td>{{ $item->product_name }}</td>
                <td class="center">{{ (float)$item->quantity }}</td>
                <td class="right">{{ number_format($item->total, 0, '.', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="divider"></div>

    <table class="summary-table">
        @if($order->discount > 0)
        <tr>
            <td>Скидка:</td>
            <td class="right">-{{ number_format($order->discount, 0, '.', '') }} сом</td>
        </tr>
        @endif
        <tr class="total-row">
            <td>ИТОГО:</td>
            <td class="right">{{ number_format($order->total, 0, '.', '') }} сом</td>
        </tr>
        @if($order->cash_received > 0)
        <tr>
            <td>Наличными:</td>
            <td class="right">{{ number_format($order->cash_received, 0, '.', '') }} сом</td>
        </tr>
        @endif
        @if($order->transfer_received > 0)
        <tr>
            <td>Безналичными:</td>
            <td class="right">{{ number_format($order->transfer_received, 0, '.', '') }} сом</td>
        </tr>
        @endif
        @php
            $total_received = ($order->cash_received ?? 0) + ($order->transfer_received ?? 0);
            $change = $total_received - $order->total;
        @endphp
        @if($change > 0)
        <tr>
            <td class="bold">СДАЧА:</td>
            <td class="right bold">{{ number_format($change, 0, '.', '') }} сом</td>
        </tr>
        @endif
    </table>

    <div class="divider-solid"></div>

    <div class="footer-text center">{{ $receipt_footer ?? 'Спасибо за покупку! Ждём вас снова!' }}</div>
</body>
</html>
