<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Накладная №{{ $order->id }}</title>
<style>
    @page { margin: 1.5cm; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 14px;
        color: #333;
        line-height: 1.4;
        margin: 0;
        padding: 15mm; /* Отступы для HTML печати */
    }
    .header-table { width: 100%; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
    .logo { font-size: 26px; font-weight: bold; color: #e63946; letter-spacing: -1px; }
    .document-info { text-align: right; }
    .document-title { font-size: 18px; font-weight: bold; margin-bottom: 5px; }

    .client-section { width: 100%; margin-bottom: 20px; padding: 12px; border: 1px solid #eee; border-radius: 3px; background: #fcfcfc; }
    .info-col { width: 50%; vertical-align: top; }
    .label { color: #888; font-size: 11px; text-transform: uppercase; margin-bottom: 4px; }
    .value { font-size: 14px; font-weight: bold; }

    table.items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
    table.items-table th {
        background: #333;
        color: #fff;
        padding: 8px;
        text-align: left;
        font-size: 12px;
        text-transform: uppercase;
    }
    table.items-table td {
        padding: 8px;
        border-bottom: 1px solid #eee;
        font-size: 13px;
    }

    .text-right { text-align: right; }
    .total-box { margin-top: 15px; text-align: right; }
    .total-amount { font-size: 22px; font-weight: bold; color: #000; border-top: 2px solid #333; display: inline-block; padding-top: 5px; }

    .signatures { margin-top: 40px; width: 100%; }
    .sig-box { width: 45%; border-top: 1px solid #333; padding-top: 10px; text-align: center; font-size: 12px; }

    .stamp {
        position: absolute;
        bottom: 50px;
        right: 20px;
        border: 2px solid #1a4d8c;
        color: #1a4d8c;
        padding: 5px;
        border-radius: 50%;
        width: 60px;
        height: 60px;
        text-align: center;
        font-size: 8px;
        opacity: 0.3;
        transform: rotate(-15deg);
    }
</style>
</head>
<body >
    <table class="header-table">
        <tr>
            <td class="logo">
                <div class="shop-name">{{ $settings['receipt_title'] ?? $settings['site_name'] ?? 'Мой Магазин' }}</div>
                @if(!empty($settings['site_inn']))
                    <div style="font-size: 10px; color: #666; font-weight: normal; margin-top: 5px; line-height: 1.2;">ИНН: {{ $settings['site_inn'] }}</div>
                @endif
                @if(!empty($settings['contact_address']))
                    <div style="font-size: 10px; color: #666; font-weight: normal; line-height: 1.2;">Адрес: {{ $settings['contact_address'] }}</div>
                @endif
                @if(!empty($settings['contact_phone']))
                    <div style="font-size: 10px; color: #666; font-weight: normal; line-height: 1.2;">Тел: {{ $settings['contact_phone'] }}</div>
                @endif
            </td>
            <td class="document-info">
                <div class="document-title">ТОВАРНАЯ НАКЛАДНАЯ №{{ $order->id }}</div>
                <div>от {{ $order->created_at->format('d.m.Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <table class="client-section">
        <tr>
            <td class="info-col">
                <div class="label">Получатель (Клиент)</div>
                <div class="value">{{ $order->user->name ?? 'Контактное лицо не указано' }}</div>
                <div>{{ $order->user->email ?? '' }}</div>
            </td>
            <td class="info-col">
                <div class="label">Адрес доставки</div>
                <div class="value">
                    @if($order->shippingAddress)
                        {{ $order->shippingAddress->city }}, {{ $order->shippingAddress->address_line_1 }}<br>
                        {{ $order->shippingAddress->postal_code }}
                    @else
                        Самовывоз
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th width="25">№</th>
                <th>Наименование товара</th>
                <th width="40" class="text-right">Кол-во</th>
                <th width="80" class="text-right">Цена</th>
                <th width="80" class="text-right">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>
                    <div style="font-weight: bold;">{{ $item->product_name }}</div>
                    @if($item->product && $item->product->sku)
                        <div style="font-size: 8px; color: #888;">Арт: {{ $item->product->sku }}</div>
                    @endif
                </td>
                <td class="text-right">{{ $item->quantity }}</td>
                <td class="text-right">{{ number_format($item->price, 2, '.', ' ') }}</td>
                <td class="text-right" style="font-weight: bold;">{{ number_format($item->total, 2, '.', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-box">
        <div style="font-size: 9px; color: #666;">ВСЕГО К ОПЛАТЕ</div>
        <div class="total-amount">{{ number_format($order->total, 2, '.', ' ') }} с</div>
    </div>

    <div style="margin-top: 20px; color: #666; font-size: 9px;">
        <div style="margin-bottom: 5px;">{!! nl2br(e($receipt_footer)) !!}</div>
        * Товар получен в полном объеме, претензий к качеству и количеству не имею.
    </div>

    <table class="signatures">
        <tr>
            <td class="sig-box">Продавец (отпустил)</td>
            <td width="10%"></td>
            <td class="sig-box">Покупатель (получил)</td>
        </tr>
    </table>
</div>

</body>
</html>
