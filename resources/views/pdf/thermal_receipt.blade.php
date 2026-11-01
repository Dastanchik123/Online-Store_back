<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="print-roll-width-mm" content="80">
    <style>
        /* Термолента 80мм, 203 dpi. Ширина ровно 72мм (область печати) по
           центру, только #000, без курсива, жирность строго 400/700 —
           подробности в RECEIPT_BASE_CSS (Online-Store_front/app/composables/usePrinter.ts).
           Высоту листа проставляет код печати; для dompdf — setPaper в контроллере.
           Tahoma/Verdana — для Windows-кассы; dompdf их не знает и берёт DejaVu Sans
           (Arial перед DejaVu ставить нельзя: dompdf подменит его на Helvetica без кириллицы). */
        @page { margin: 0; }
        html { color-scheme: light; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; }
        body {
            font-family: Tahoma, Verdana, 'DejaVu Sans', sans-serif;
            width: 72mm;
            margin: 0 auto;
            /* Браузер: при margin auto на листе A4 чек уезжал за термоголовку
               (она печатает левые 72мм). Отступ 4мм — только на листе ровно
               80мм (Electron), на широком листе и окне растра 72мм — 0.
               dompdf max()/min()/calc() не понимает и остаётся на margin auto выше. */
            margin: 0 0 0 max(0mm, min(calc((100% - 72mm) / 2), calc(4mm - (100% - 80mm))));
            padding: 0 0 4mm;
            font-size: 13px;
            font-weight: 400;
            color: #000;
            line-height: 1.3;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .divider { border-top: 1px dashed #000; margin: 2.5mm 0; }
        .divider-solid { border-top: 2px solid #000; margin: 3mm 0 2mm; }

        table { width: 100%; border-collapse: collapse; }

        .shop-header { margin-bottom: 3mm; }
        .shop-name { font-size: 20px; font-weight: 700; line-height: 1.15; margin-bottom: 1mm; }
        .shop-meta { font-size: 12px; line-height: 1.35; }

        table.meta-table td { font-size: 12px; padding: 0.3mm 0; }

        .receipt-title { font-size: 15px; margin-bottom: 2mm; }

        table.items-table th {
            text-align: left;
            border-bottom: 1px solid #000;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            padding-bottom: 1mm;
            white-space: nowrap;
        }
        table.items-table th.right { text-align: right; }
        table.items-table th.center { text-align: center; }
        table.items-table th.unit-col,
        .item-row td.unit-col { width: 1%; white-space: nowrap; padding-left: 2mm; }

        .item-row td { padding: 1.2mm 0; font-size: 13px; vertical-align: top; }
        .item-row td:first-child { overflow-wrap: anywhere; padding-right: 1mm; }
        .item-row td.center { text-align: center; white-space: nowrap; }
        .item-row td.right { text-align: right; white-space: nowrap; padding-left: 1.5mm; }
        table.items-table th.right { padding-left: 1.5mm; }
        .item-row td:last-child { font-weight: 700; padding-left: 1.5mm; }

        table.summary-table td { padding: 0.7mm 0; font-size: 13px; }
        table.summary-table td.right { text-align: right; }
        .total-row td { font-size: 26px; font-weight: 700; padding: 0 0 1mm; }

        .footer-text { margin-top: 4mm; font-size: 12px; white-space: pre-wrap; line-height: 1.4; }
    </style>
</head>
<body>
    @php
        // Имя/адрес/телефон из формы заказа приоритетнее данных аккаунта —
        // заказ может оформляться на другого человека (см. pdf/order.blade.php)
        $addressName = trim(
            ($order->shippingAddress->first_name ?? '') . ' ' . ($order->shippingAddress->last_name ?? '')
        );
        $customerName = $addressName !== '' ? $addressName : ($order->user->name ?? 'Розничный покупатель');
        $customerPhone = $order->shippingAddress->phone ?? $order->user->phone ?? '';
        $customerAddress = $order->shippingAddress
            ? implode(', ', array_filter([$order->shippingAddress->city, $order->shippingAddress->address_line_1]))
            : '';
    @endphp

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
            <td colspan="2">Продавец: {{ $settings['site_name'] ?? 'Мой Магазин' }}</td>
        </tr>
        <tr>
            <td colspan="2">Покупатель: {{ $customerName }}</td>
        </tr>
        @if($customerPhone)
        <tr>
            <td colspan="2">Телефон: {{ $customerPhone }}</td>
        </tr>
        @endif
        @if($customerAddress)
        <tr>
            <td colspan="2">Адрес: {{ $customerAddress }}</td>
        </tr>
        @endif
    </table>

    <div class="divider"></div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Наименование</th>
                <th class="center">Кол-во</th>
                <th class="unit-col">Ед.</th>
                <th class="right">Цена</th>
                <th class="right">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
            @php
                $itemUnit = $item->is_package
                    ? ($item->product?->package_unit ?? $item->product?->unit ?? 'шт')
                    : ($item->product?->unit ?? 'шт');
            @endphp
            <tr class="item-row">
                <td>{{ $item->product_name }}</td>
                <td class="center">{{ (float)$item->quantity }}</td>
                <td class="unit-col">{{ $itemUnit }}</td>
                <td class="right">{{ number_format($item->price, 0, '.', ' ') }}</td>
                <td class="right">{{ number_format($item->total, 0, '.', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="divider"></div>

    {{-- Суммы как 1,034.00. cash_received хранится уже без сдачи, поэтому
         «Наличные» (сколько внёс покупатель) = cash_received + change_amount --}}
    @php
        $change = (float) ($order->change_amount ?? 0);
    @endphp
    <table class="summary-table">
        @if($order->discount > 0)
        <tr>
            <td>Скидка</td>
            <td class="right">-{{ number_format($order->discount, 2, '.', ',') }}</td>
        </tr>
        @endif
        <tr class="total-row">
            <td>ИТОГО:</td>
            <td class="right">{{ number_format($order->total, 2, '.', ',') }}</td>
        </tr>
        <tr>
            <td>Наличные</td>
            <td class="right">{{ number_format((float) ($order->cash_received ?? 0) + $change, 2, '.', ',') }}</td>
        </tr>
        <tr>
            <td>Безналичные</td>
            <td class="right">{{ number_format($order->transfer_received ?? 0, 2, '.', ',') }}</td>
        </tr>
        <tr>
            <td>Сдача</td>
            <td class="right">{{ number_format($change, 2, '.', ',') }}</td>
        </tr>
    </table>

    <div class="divider-solid"></div>

    <div class="footer-text center">{{ $receipt_footer ?? 'Спасибо за покупку! Ждём вас снова!' }}</div>

    {{-- Метка под QR электронной копии чека: сам QR рисует касса при печати
         (usePrinter: injectReceiptQr) — с модулем ровно в точки термоголовки.
         В PDF (dompdf) блок пустой и ничего не занимает. --}}
    @if(!empty($receipt_public_url) && $order->uuid)
        <div class="receipt-qr" data-url="{{ rtrim($receipt_public_url, '/') }}/receipt/{{ $order->uuid }}"></div>
    @endif
</body>
</html>
