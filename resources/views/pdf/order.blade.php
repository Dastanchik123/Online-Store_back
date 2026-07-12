<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Накладная №{{ $order->id }}</title>
<style>
    @page { margin: 15mm; }
    html { color-scheme: light; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 12px;
        color: #000;
        background: #fff;
        line-height: 1.4;
        margin: 0;
    }

    .title {
        text-align: center;
        font-size: 16px;
        font-weight: bold;
        margin: 0 0 16px 0;
    }

    table.header-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14px;
    }
    table.header-table td {
        padding: 2px 4px;
        vertical-align: top;
        border: none;
    }
    table.header-table .label {
        width: 150px;
        font-weight: bold;
        white-space: nowrap;
    }

    table.items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8px;
    }
    table.items-table th,
    table.items-table td {
        border: 1px solid #000;
        padding: 5px 6px;
    }
    table.items-table thead th {
        text-align: center;
        font-weight: bold;
        background: #f2f2f2;
    }
    .c-num   { width: 30px;  text-align: center; }
    .c-name  { text-align: left; }
    .c-unit  { width: 60px;  text-align: center; }
    .c-price { width: 90px;  text-align: right; }
    .c-qty   { width: 70px;  text-align: center; }
    .c-sum   { width: 100px; text-align: right; }

    .total-label { text-align: right; font-weight: bold; }
    .total-sum { font-weight: bold; }

    .summary-line { margin: 5px 0; }

    table.signatures {
        width: 100%;
        border-collapse: collapse;
        margin-top: 18px;
    }
    table.signatures td {
        border: none;
        padding-bottom: 8px;
    }
    table.sig-row {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
    }
    table.sig-row td {
        border: none;
        padding: 0;
        vertical-align: bottom;
    }
    table.sig-row td.sig-title { font-weight: bold; width: 150px; white-space: nowrap; }
    table.sig-row td.sig-field { border-bottom: 1px solid #000; text-align: center; }
    .sig-caps {
        font-size: 9px;
        color: #444;
    }
    .sig-caps td { padding: 0; text-align: center; }
    .sig-caps .cap-title { text-align: left; }
</style>
</head>
<body>
@php
    $date = $order->created_at;
    $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];

    $itemsTotal = $order->items->sum(fn ($item) => $item->price * $item->quantity);
    $itemsCount = $order->items->count();

    // Имя/фамилия вводятся заново в форме оформления заказа — заказ может
    // оформляться на другого человека, чем зарегистрированный аккаунт,
    // поэтому это имя приоритетнее имени из профиля пользователя
    $addressName = trim(
        ($order->shippingAddress->first_name ?? '') . ' ' . ($order->shippingAddress->last_name ?? '')
    );
    $customerName = $addressName !== '' ? $addressName : ($order->user->name ?? 'Розничный покупатель');
    $customerPhone = $order->shippingAddress->phone ?? $order->user->phone ?? '';
@endphp

    <div class="title">
        Накладная № {{ $order->id }} от «{{ $date->day }}» {{ $months[$date->month - 1] }} {{ $date->year }} г.
    </div>

    <table class="header-table">
        <tr>
            <td class="label">Продавец:</td>
            <td>
                {{ $settings['site_name'] ?? 'Мой Магазин' }}@if(!empty($settings['site_inn'])), ИНН {{ $settings['site_inn'] }}@endif
            </td>
        </tr>
        <tr>
            <td class="label">Покупатель:</td>
            <td>{{ $customerName }}</td>
        </tr>
        <tr>
            <td class="label">Адрес покупателя:</td>
            <td>
                @if($order->shippingAddress)
                    {{ $order->shippingAddress->city }}, {{ $order->shippingAddress->address_line_1 }}
                @else
                    Самовывоз
                @endif
            </td>
        </tr>
        @if($customerPhone)
        <tr>
            <td class="label">Телефон:</td>
            <td>{{ $customerPhone }}</td>
        </tr>
        @endif
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th class="c-num">№</th>
                <th class="c-name">Товар</th>
                <th class="c-unit">Ед.</th>
                <th class="c-price">Цена</th>
                <th class="c-qty">Кол-во</th>
                <th class="c-sum">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $index => $item)
            <tr>
                <td class="c-num">{{ $index + 1 }}</td>
                <td class="c-name">{{ $item->product_name }}</td>
                <td class="c-unit">шт.</td>
                <td class="c-price">{{ number_format($item->price, 2, ',', ' ') }}</td>
                <td class="c-qty">{{ $item->quantity }}</td>
                <td class="c-sum">{{ number_format($item->price * $item->quantity, 2, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="total-label">Итого:</td>
                <td class="c-sum total-sum">{{ number_format($itemsTotal, 2, ',', ' ') }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="summary-line">Всего отпущено {{ \App\Support\NumberToWordsRu::countToWords($itemsCount) }} ({{ $itemsCount }}) {{ \App\Support\NumberToWordsRu::pluralize($itemsCount, ['наименование', 'наименования', 'наименований']) }}.</p>
    <p class="summary-line">На сумму {{ \App\Support\NumberToWordsRu::amountToWords((float) $itemsTotal) }}</p>

    <table class="signatures">
        <tr>
            <td>
                <table class="sig-row">
                    <colgroup>
                        <col style="width:140px"><col><col style="width:30px"><col>
                    </colgroup>
                    <tr>
                        <td class="sig-title">Отпустил</td>
                        <td class="sig-field">&nbsp;</td>
                        <td class="sig-gap"></td>
                        <td class="sig-field">{{ $order->staff->name ?? '' }}</td>
                    </tr>
                </table>
                <table class="sig-row sig-caps">
                    <colgroup>
                        <col style="width:140px"><col><col style="width:30px"><col>
                    </colgroup>
                    <tr>
                        <td class="cap-title"></td>
                        <td>подпись</td>
                        <td></td>
                        <td>Ф.И.О.</td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td>
                <table class="sig-row">
                    <colgroup>
                        <col style="width:140px"><col><col style="width:30px"><col>
                    </colgroup>
                    <tr>
                        <td class="sig-title">Получил</td>
                        <td class="sig-field">&nbsp;</td>
                        <td class="sig-gap"></td>
                        <td class="sig-field">{{ $customerName }}</td>
                    </tr>
                </table>
                <table class="sig-row sig-caps">
                    <colgroup>
                        <col style="width:140px"><col><col style="width:30px"><col>
                    </colgroup>
                    <tr>
                        <td class="cap-title"></td>
                        <td>подпись</td>
                        <td></td>
                        <td>Ф.И.О.</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($order->notes)
    <p class="summary-line" style="margin-top: 15px;">Примечание: {{ $order->notes }}</p>
    @endif
</body>
</html>
