<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Отчет по остаткам товаров</title>
    <style>
        @page {
            margin: 1cm;
            header: page-header;
            footer: page-footer;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #333;
        }
        .report-header {
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .report-title { font-size: 18px; font-weight: bold; color: #2c3e50; }
        .report-meta { float: right; text-align: right; color: #7f8c8d; }

        table.products-table { width: 100%; border-collapse: collapse; }
        table.products-table th {
            background: #2c3e50;
            color: #fff;
            padding: 8px 4px;
            text-align: left;
            border: 1px solid #34495e;
        }
        table.products-table td {
            padding: 6px 4px;
            border: 1px solid #ecf0f1;
        }
        table.products-table tr:nth-child(even) { background: #f9f9f9; }

        .badge {
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 8px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .badge-success { background: #27ae60; color: #fff; }
        .badge-danger { background: #e74c3c; color: #fff; }

        .summary { margin-top: 20px; font-size: 11px; font-weight: bold; text-align: right; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="report-meta">
            Дата отчета: {{ date('d.m.Y H:i') }}<br>
            Всего позиций: {{ count($products) }}
        </div>
        <div class="report-title">ОТЧЕТ ПО СКЛАДСКИМ ОСТАТКАМ</div>
    </div>

    <table class="products-table">
        <thead>
            <tr>
                <th width="30">ID</th>
                <th>Наименование товара</th>
                <th style="white-space: nowrap;" width="1%">Артикул</th>
                <th width="100">Категория</th>
                <th width="auto" class="text-right">Закуп</th>
                <th width="auto" class="text-right">Продажа</th>
                <th width="auto" class="text-right">Остаток</th>
                <th width="auto" class="text-center">Статус</th>
            </tr>
        </thead>
        <tbody>
            @foreach($products as $index => $product)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td style="font-weight: bold;">{{ $product->name }}</td>
                <td style="white-space: nowrap;"><code>{{ $product->sku }}</code></td>
                <td>{{ $product->category->name ?? '-' }}</td>
                <td class="text-right">{{ number_format($product->purchase_price, 2, '.', ' ') }} с</td>
                <td class="text-right">
                    {{ number_format($product->price, 2, '.', ' ') }} с
                    @if($product->sale_price)
                        <br><small style="color: #e74c3c;">Акция: {{ number_format($product->sale_price, 2, '.', ' ') }} с</small>
                    @endif
                </td>
                <td class="text-center" style="font-weight: bold;">{{ $product->stock_quantity }}</td>
                <td class="text-center">
                    @if($product->stock_quantity > 0)
                        <span class="badge badge-success">В наличии</span>
                    @else
                        <span class="badge badge-danger">Нет</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
         <tfoot>
        <tr>
            <td colspan="4" class="text-right" style="font-weight: bold;">Итого:</td>
            <td class="text-right" style="font-weight: bold;">
                {{ number_format($products->sum(fn($p) => $p->purchase_price * $p->stock_quantity), 2, '.', ' ') }} с
            </td>
            <td class="text-right" style="font-weight: bold;">
                {{ number_format($products->sum(fn($p) => $p->price * $p->stock_quantity), 2, '.', ' ') }} с
            </td>
            <td class="text-center" style="font-weight: bold;">
                {{ $products->sum('stock_quantity') }}
            </td>
            <td></td>
        </tr>
    </tfoot>
    </table>

    <div class="summary">
        Общая стоимость закупа:
        {{ number_format($products->sum(fn($p) => $p->purchase_price * $p->stock_quantity), 2, '.', ' ') }} с
        <br>
        Ожидаемая выручка:
        {{ number_format($products->sum(fn($p) => $p->price * $p->stock_quantity), 2, '.', ' ') }} с
    </div>

    <div style="margin-top: 50px; font-size: 8px; color: #bdc3c7; text-align: center;">
        Документ сформирован автоматически в системе учета "{{ $settings['site_name'] ?? 'Мой Магазин' }}"
    </div>
</body>
</html>
