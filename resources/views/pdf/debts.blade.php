<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #333;
        }

        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid #e63946;
            padding-bottom: 10px;
        }

        .header h1 {
            color: #e63946;
            margin: 0;
            font-size: 18px;
            text-transform: uppercase;
        }

        .meta {
            position: absolute;
            top: 0;
            right: 0;
            text-align: right;
            font-size: 9px;
            color: #666;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background-color: #2c3e50;
            color: white;
            padding: 6px;
            text-align: left;
            text-transform: uppercase;
            font-size: 8px;
        }

        td {
            padding: 6px;
            border-bottom: 1px solid #eee;
        }

        .group-header {
            background-color: #f8f9fa;
            font-weight: bold;
            border-top: 1px solid #ddd;
        }

        .group-header td {
            color: #2c3e50;
            font-size: 14px;
        }

        .child-row td {
            color: #555;
        }

        .text-end {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .text-danger {
            color: #e63946;
            font-weight: bold;
        }

        .text-success {
            color: #2ecc71;
        }

        .badge {
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 7px;
            color: white;
            display: inline-block;
        }

        .bg-danger {
            background-color: #e63946;
        }

        .bg-success {
            background-color: #2ecc71;
        }

        .bg-warning {
            background-color: #f1c40f;
            color: #333;
        }

        .bg-secondary {
            background-color: #95a5a6;
        }

        .footer {
            margin-top: 20px;
            text-align: right;
            font-size: 12px;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <div style="font-size: 10px; font-weight: bold; margin-bottom: 5px;">{{ $settings['site_name'] ?? '' }} (ИНН: {{ $settings['site_inn'] ?? '' }})</div>
        <div class="meta">
            Дата отчета: {{ $date }}<br>
            Всего записей: {{ $debts->count() }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th width="30">ID</th>
                <th>Клиент</th>
                <th>Телефон</th>
                <th>Заказ</th>
                <th class="text-end">Всего</th>
                <th class="text-end">Оплачено</th>
                <th class="text-end">Остаток</th>
                <th class="text-center">Статус</th>
                <th class="text-center">Срок</th>
            </tr>
        </thead>
        <tbody>
            @if ($isGrouped)
                @foreach ($groupedDebts as $userId => $group)
                    <tr class="group-header">
                        <td class="text-center">-</td>
                        <td style="padding-left: 10px;">
                            {{ $group['user']->name ?? 'Дебитор' }}
                        </td>
                        <td>{{ $group['user']->phone ?? '-' }}</td>
                        <td>{{ count($group['debts']) }} зак.</td>
                        <td class="text-end">{{ number_format($group['total_amount'], 2, '.', ' ') }}</td>
                        <td class="text-end">{{ number_format($group['paid_amount'], 2, '.', ' ') }}</td>
                        <td class="text-end text-danger">{{ number_format($group['remaining_amount'], 2, '.', ' ') }}
                        </td>
                        <td class="text-center">
                            @if ($group['remaining_amount'] > 0)
                                <span class="badge bg-danger">ДОЛГ</span>
                            @else
                                <span class="badge bg-success">ОПЛАЧЕН</span>
                            @endif
                        </td>
                        <td class="text-center">-</td>
                    </tr>

                    @if (in_array((string) $userId, $expandedGroups))
                        @foreach ($group['debts'] as $debt)
                            <tr class="child-row">
                                <td class="text-center">{{ $debt->id }}</td>
                                <td></td>
                                <td></td>
                                <td>#{{ $debt->order_id }}</td>
                                <td class="text-end">{{ number_format($debt->total_amount, 2, '.', ' ') }}</td>
                                <td class="text-end">{{ number_format($debt->paid_amount, 2, '.', ' ') }}</td>
                                <td class="text-end">{{ number_format($debt->remaining_amount, 2, '.', ' ') }}</td>
                                <td class="text-center">
                                    @php
                                        $statusClass = 'bg-secondary';
                                        $statusText = $debt->status;
                                        if ($debt->status == 'active') {
                                            $statusClass = 'bg-danger';
                                            $statusText = 'Активен';
                                        } elseif ($debt->status == 'partial') {
                                            $statusClass = 'bg-warning';
                                            $statusText = 'Частично';
                                        } elseif ($debt->status == 'paid') {
                                            $statusClass = 'bg-success';
                                            $statusText = 'Оплачен';
                                        }
                                    @endphp
                                    <span class="badge {{ $statusClass }}">{{ $statusText }}</span>
                                </td>
                                <td class="text-center">
                                    {{ $debt->due_date ? date('d.m.Y', strtotime($debt->due_date)) : '-' }}</td>
                            </tr>
                        @endforeach
                    @endif
                @endforeach
            @else
                @foreach ($debts as $debt)
                    <tr>
                        <td class="text-center">{{ $debt->id }}</td>
                        <td>{{ $debt->user->name ?? 'Гость' }}</td>
                        <td>{{ $debt->user->phone ?? '-' }}</td>
                        <td>#{{ $debt->order_id }}</td>
                        <td class="text-end">{{ number_format($debt->total_amount, 2, '.', ' ') }}</td>
                        <td class="text-end">{{ number_format($debt->paid_amount, 2, '.', ' ') }}</td>
                        <td class="text-end text-danger">{{ number_format($debt->remaining_amount, 2, '.', ' ') }}</td>
                        <td class="text-center">
                            @php
                                $statusClass = 'bg-secondary';
                                $statusText = $debt->status;
                                if ($debt->status == 'active') {
                                    $statusClass = 'bg-danger';
                                    $statusText = 'Активен';
                                } elseif ($debt->status == 'partial') {
                                    $statusClass = 'bg-warning';
                                    $statusText = 'Частично';
                                } elseif ($debt->status == 'paid') {
                                    $statusClass = 'bg-success';
                                    $statusText = 'Оплачен';
                                }
                            @endphp
                            <span class="badge {{ $statusClass }}">{{ $statusText }}</span>
                        </td>
                        <td class="text-center">{{ $debt->due_date ? date('d.m.Y', strtotime($debt->due_date)) : '-' }}
                        </td>
                    </tr>
                @endforeach
            @endif
        </tbody>
    </table>

    <div class="footer">
        ИТОГО К ЗАЧИСЛЕНИЮ: {{ number_format($debts->sum('remaining_amount'), 2, '.', ' ') }} сом
    </div>
</body>

</html>
