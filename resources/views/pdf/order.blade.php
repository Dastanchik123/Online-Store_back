<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Накладная №{{ $order->id }}</title>
<style>
    @page { margin: 14mm 12mm; }
    html { color-scheme: light; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 11px;
        color: #1a1a1a;
        background: #fff;
        line-height: 1.4;
        margin: 0;
    }
    .doc { border: 1.5px solid #000; padding: 7mm 9mm; }

    table.top { width: 100%; border-collapse: collapse; }
    table.top td { vertical-align: top; padding: 0; }

    .company-name { font-size: 17px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; }
    .company-details { font-size: 9.5px; color: #444; margin-top: 2mm; line-height: 1.6; }

    .doc-title-block { text-align: right; }
    .doc-title { font-size: 15px; font-weight: bold; text-transform: uppercase; }
    .doc-number { font-size: 12px; margin-top: 2mm; }
    .doc-date { font-size: 10px; color: #444; margin-top: 1mm; }

    hr.sep { border: none; border-top: 1.5px solid #000; margin: 4mm 0 5mm; }

    table.parties { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
    table.parties td { width: 50%; vertical-align: top; padding-right: 6mm; }
    .p-label { font-size: 8.5px; text-transform: uppercase; color: #777; letter-spacing: 0.6px; margin-bottom: 1mm; }
    .p-value { font-weight: bold; font-size: 11.5px; }
    .p-sub { font-size: 10px; color: #555; margin-top: 0.5mm; }

    table.items { width: 100%; border-collapse: collapse; }
    table.items th {
        border: 1px solid #000;
        background: #ededed;
        padding: 5px 6px;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        text-align: center;
    }
    table.items td {
        border: 1px solid #000;
        padding: 5px 6px;
        font-size: 10.5px;
        vertical-align: top;
    }
    table.items td.num { text-align: right; }
    table.items td.center { text-align: center; }
    .item-name { font-weight: bold; }
    .item-sku { font-size: 8px; color: #888; margin-top: 0.5mm; }

    table.totals { width: 70mm; margin-left: auto; margin-top: 5mm; border-collapse: collapse; }
    table.totals td { padding: 1.2mm 0; font-size: 11px; white-space: nowrap; }
    table.totals td.t-label { color: #444; }
    table.totals td.t-value { text-align: right; }
    table.totals tr.grand td { border-top: 1.5px solid #000; padding-top: 2.5mm; font-weight: bold; }
    table.totals tr.grand td.t-value { font-size: 16px; }

    .footer-note { margin-top: 7mm; font-size: 9px; color: #444; line-height: 1.6; }

    table.signatures { width: 100%; margin-top: 14mm; border-collapse: collapse; }
    table.signatures td { width: 45%; font-size: 10.5px; padding-top: 1mm; border-top: 1px solid #000; color: #444; }
    table.signatures td.gap { width: 10%; border-top: none; }
</style>
</head>
<body>
    <div class="doc">
        <table class="top">
            <tr>
                <td>
                    <div class="company-name">{{ $settings['receipt_title'] ?? $settings['site_name'] ?? 'Мой Магазин' }}</div>
                    <div class="company-details">
                        @if(!empty($settings['site_inn']))
                            ИНН: {{ $settings['site_inn'] }}<br>
                        @endif
                        @if(!empty($settings['contact_address']))
                            Адрес: {{ $settings['contact_address'] }}<br>
                        @endif
                        @if(!empty($settings['contact_phone']))
                            Тел: {{ $settings['contact_phone'] }}
                        @endif
                    </div>
                </td>
                <td class="doc-title-block">
                    <div class="doc-title">Товарная накладная</div>
                    <div class="doc-number">№ {{ $order->id }}</div>
                    <div class="doc-date">от {{ $order->created_at->format('d.m.Y H:i') }}</div>
                </td>
            </tr>
        </table>

        <hr class="sep">

        <table class="parties">
            <tr>
                <td>
                    <div class="p-label">Получатель (клиент)</div>
                    <div class="p-value">{{ $order->user->name ?? 'Розничный покупатель' }}</div>
                    @if(!empty($order->user->email))
                        <div class="p-sub">{{ $order->user->email }}</div>
                    @endif
                </td>
                <td>
                    <div class="p-label">Адрес доставки</div>
                    <div class="p-value">
                        @if($order->shippingAddress)
                            {{ $order->shippingAddress->city }}, {{ $order->shippingAddress->address_line_1 }}
                        @else
                            Самовывоз
                        @endif
                    </div>
                    @if($order->shippingAddress && $order->shippingAddress->postal_code)
                        <div class="p-sub">{{ $order->shippingAddress->postal_code }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 6%;">№</th>
                    <th style="width: 44%; text-align: left;">Наименование товара</th>
                    <th style="width: 14%;">Кол-во</th>
                    <th style="width: 16%;">Цена</th>
                    <th style="width: 20%;">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $index => $item)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>
                        <div class="item-name">{{ $item->product_name }}</div>
                        @if($item->product && $item->product->sku)
                            <div class="item-sku">Арт: {{ $item->product->sku }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ number_format($item->price, 2, '.', ' ') }}</td>
                    <td class="num" style="font-weight: bold;">{{ number_format($item->total, 2, '.', ' ') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            @if(!empty($order->discount) && $order->discount > 0)
            <tr>
                <td class="t-label">Подытог:</td>
                <td class="t-value">{{ number_format($order->total + $order->discount, 2, '.', ' ') }}</td>
            </tr>
            <tr>
                <td class="t-label">Скидка:</td>
                <td class="t-value">-{{ number_format($order->discount, 2, '.', ' ') }}</td>
            </tr>
            @endif
            <tr class="grand">
                <td class="t-label">Всего к оплате:</td>
                <td class="t-value">{{ number_format($order->total, 2, '.', ' ') }} с</td>
            </tr>
        </table>

        <div class="footer-note">
            {!! nl2br(e($receipt_footer ?? '')) !!}<br>
            * Товар получен в полном объёме, претензий к качеству и количеству не имею.
        </div>

        <table class="signatures">
            <tr>
                <td>Продавец (отпустил)</td>
                <td class="gap"></td>
                <td>Покупатель (получил)</td>
            </tr>
        </table>
    </div>
</body>
</html>
