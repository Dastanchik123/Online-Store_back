<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Акт сверки - {{ $supplier->name }}</title>
    <style>
        @page { margin: 1.5cm 1cm; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.5;
        }
        .header { text-align: center; margin-bottom: 40px; }
        .document-title { font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .period { color: #666; font-size: 12px; }

        .contractors { width: 100%; margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
        .contractor-box { width: 50%; vertical-align: top; }
        .label { font-size: 9px; color: #888; text-transform: uppercase; margin-bottom: 5px; }

        table.data-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table.data-table th {
            background: #f4f4f4;
            border: 1px solid #ddd;
            padding: 8px 5px;
            font-size: 9px;
            text-transform: uppercase;
        }
        table.data-table td { border: 1px solid #eee; padding: 10px 5px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .summary-box {
            margin-top: 30px;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #eee;
            border-radius: 4px;
        }
        .balance-row { margin-bottom: 5px; display: block; }
        .balance-final { font-size: 14px; font-weight: bold; margin-top: 10px; padding-top: 10px; border-top: 1px dotted #ccc; }

        .signatures { margin-top: 60px; width: 100%; }
        .sig-col { width: 45%; vertical-align: bottom; }
        .sig-line { border-bottom: 1px solid #000; height: 30px; margin-bottom: 5px; }
        .sig-label { font-size: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="document-title">АКТ СВЕРКИ ВЗАИМНЫХ РАСЧЕТОВ</div>
        <div class="period">за период: {{ $date_from }} — {{ $date_to }}</div>
    </div>

    <table class="contractors">
        <tr>
            <td class="contractor-box">
                <div class="label">Организация</div>
                <div style="font-weight: bold; font-size: 12px;">{{ $settings['site_name'] ?? 'Мой Магазин' }}</div>
                <div style="font-size: 10px; color: #666; margin-top: 5px;">
                    ИНН: {{ $settings['site_inn'] ?? '—' }}<br>
                    Адрес: {{ $settings['contact_address'] ?? '—' }}
                </div>
            </td>
            <td class="contractor-box" style="padding-left: 50px;">
                <div class="label">Поставщик</div>
                <div style="font-weight: bold; font-size: 12px;">{{ $supplier->name }}</div>
                @if($supplier->phone) <div>Тел: {{ $supplier->phone }}</div> @endif
            </td>
        </tr>
    </table>

    <p>Мы, нижеподписавшиеся, составили настоящий акт о том, что состояние взаимных расчетов по данным учета за указанный период следующее:</p>

    <table class="data-table">
        <thead>
            <tr>
                <th width="80">Дата</th>
                <th>Наименование операции</th>
                <th width="100" class="text-right">Дебет (ПРИХОД)</th>
                <th width="100" class="text-right">Кредит (ОПЛАТА)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-center" colspan="2" style="background: #fafafa;"><strong>Сальдо начальное</strong></td>
                <td class="text-right" colspan="2"><strong>{{ number_format($start_balance, 2, '.', ' ') }}</strong></td>
            </tr>
            @foreach($transactions as $tx)
            <tr>
                <td class="text-center">{{ $tx['date'] }}</td>
                <td>{{ $tx['description'] }}</td>
                <td class="text-right">{{ $tx['debit'] > 0 ? number_format($tx['debit'], 2, '.', ' ') : '-' }}</td>
                <td class="text-right" style="color: #2a9d8f;">{{ $tx['credit'] > 0 ? number_format($tx['credit'], 2, '.', ' ') : '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary-box">
        <div class="balance-row">Обороты за период:
            <span style="float: right;">Дебет: {{ number_format($total_debit, 2, '.', ' ') }} / Кредит: {{ number_format($total_credit, 2, '.', ' ') }}</span>
        </div>
        <div class="balance-final">
            САЛЬДО КОНЕЧНОЕ:
            <span style="float: right; color: {{ $end_balance > 0 ? '#e63946' : '#2a9d8f' }};">
                {{ number_format($end_balance, 2, '.', ' ') }} с
            </span>
        </div>
        <div style="font-size: 10px; color: #888; margin-top: 10px;">
            @if($end_balance > 0)
                Задолженность в пользу <strong>{{ $settings['site_name'] ?? 'Организации' }}</strong> составляет {{ number_format($end_balance, 2, '.', ' ') }} с
            @else
                Задолженности нет.
            @endif
        </div>
    </div>

    <table class="signatures">
        <tr>
            <td class="sig-col">
                <div class="sig-line"></div>
                <div class="sig-label">От {{ $settings['site_name'] ?? 'Организации' }}</div>
            </td>
            <td width="10%"></td>
            <td class="sig-col">
                <div class="sig-line"></div>
                <div class="sig-label">От {{ $supplier->name }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
