<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            font-family: 'DejaVu Sans ', sans-serif;
            width: 72mm;
            /* margin: 0 auto; */
            border:1px solid black;
            padding: 12mm 0 0;
            float: right;
            font-size: 12px;
            color: #000;
            line-height: 1.2;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .divider { border-top: 1px dashed #000; margin: 2mm 0; }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2mm;
        }
        th {
            text-align: left;
            border-bottom: 1px solid #000;
            font-size: 10px;
            padding-bottom: 1mm;
        }
        td {
            padding: 1mm 0;
            vertical-align: top;
            font-size: 10px;
        }

        .summary-table td {
            padding: 0.5mm 0;
        }
        .total-row {
            font-size: 14px;
            font-weight: bold;
        }
        .shop-header {
            margin-bottom: 3mm;
        }
        .shop-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 1mm;
        }
        .info-line {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="shop-header center">
            <div class="shop-name">{{ $settings['receipt_title'] ?? $settings['site_name'] ?? 'Мой Магазин' }}</div>
            @if(!empty($settings['site_inn']))
                <div style="font-size: 9px;">ИНН: {{ $settings['site_inn'] }}</div>
            @endif
            @if(!empty($settings['contact_address']))
                <div style="font-size: 9px;">{{ $settings['contact_address'] }}</div>
            @endif
            <div class="info-line" style="margin-top: 2mm;">
                <span>Дата: {{ now()->format('d.m.Y H:i') }}</span>
                <span>Чек №{{ $order->order_number ?: $order->id }}</span>
            </div>
            <div class="info-line">
                <span>Кассир: {{ $order->staff->name ?? 'Админ' }}</span>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 45%;">Наименование</th>
                    <th style="width: 15%;">Кол.</th>
                    <th style="width: 20%;">Цена</th>
                    <th style="width: 20%; text-align: right;">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $item)
                <tr>
                    <td>{{ $item->product_name }}</td>
                    <td>{{ (float)$item->quantity }}</td>
                    <td>{{ number_format($item->price, 0, '.', '') }}</td>
                    <td style="text-align: right;">{{ number_format($item->total, 0, '.', '') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="divider"></div>

        <table class="summary-table">
            @if($order->discount > 0)
            <tr>
                <td>Скидка:</td>
                <td style="text-align: right;">-{{ number_format($order->discount, 0, '.', '') }} сом</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>ИТОГО:</td>
                <td style="text-align: right;">{{ number_format($order->total, 0, '.', '') }} сом</td>
            </tr>
            @if($order->cash_received > 0)
            <tr>
                <td>Наличными:</td>
                <td style="text-align: right;">{{ number_format($order->cash_received, 0, '.', '') }} сом</td>
            </tr>
            @endif
            @if($order->transfer_received > 0)
            <tr>
                <td>Безналичными:</td>
                <td style="text-align: right;">{{ number_format($order->transfer_received, 0, '.', '') }} сом</td>
            </tr>
            @endif
            @php
                $total_received = ($order->cash_received ?? 0) + ($order->transfer_received ?? 0);
                $change = $total_received - $order->total;
            @endphp
            @if($change > 0)
            <tr style="font-weight: bold; border-top: 1px dashed #eee;">
                <td style="padding-top: 1mm;">СДАЧА:</td>
                <td style="text-align: right; padding-top: 1mm;">{{ number_format($change, 0, '.', '') }} сом</td>
            </tr>
            @endif
        </table>

        <div class="center" style="margin-top: 4mm; font-size: 10px; white-space: pre-wrap; font-style: italic;">
            {{ $receipt_footer ?? 'Спасибо за покупку!' }}
        </div>

        <div class="divider" style="margin-top: 5mm; border-top-style: solid; border-top-width: 2px;"></div>
    </div>
</body>
</html>
