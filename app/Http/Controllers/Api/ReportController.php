<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ReportController extends Controller
{

    public function reconciliation(Supplier $supplier, Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->get('date_to', now()->toDateString());

        $startBalance = 0;

        $purchases = Purchase::where('supplier_id', $supplier->id)
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->get();

        $transactions = [];
        $totalDebit   = 0;
        $totalCredit  = 0;

        foreach ($purchases as $p) {
            $transactions[] = [
                'date'        => $p->created_at->format('d.m.Y'),
                'description' => "Закупка №{$p->id}",
                'debit'  => $p->total_amount,
                'credit' => 0,
            ];
            $totalDebit += $p->total_amount;

            if ($p->paid_amount > 0) {
                $transactions[] = [
                    'date'        => $p->created_at->format('d.m.Y'),
                    'description' => "Оплата по закупке №{$p->id}",
                    'debit'  => 0,
                    'credit' => $p->paid_amount,
                ];
                $totalCredit += $p->paid_amount;
            }
        }

        usort($transactions, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        $endBalance = $supplier->debt_to_supplier;

        $data = [
            'supplier'      => $supplier,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'transactions'  => $transactions,
            'start_balance' => $startBalance,
            'total_debit'   => $totalDebit,
            'total_credit'  => $totalCredit,
            'end_balance'   => $endBalance,
        ];

        $pdf = Pdf::loadView('pdf.reconciliation', $data);
        return $pdf->download("reconciliation_{$supplier->id}.pdf");
    }

    public function purchase(Purchase $purchase)
    {
        $purchase->load(['supplier', 'items.product']);
        $pdf = Pdf::loadView('pdf.purchase', ['purchase' => $purchase]);
        return $pdf->download("purchase_{$purchase->id}.pdf");
    }

    public function products(Request $request)
    {
        $query = Product::query()->with('category');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('in_stock')) {
            $query->where('in_stock', $request->boolean('in_stock'));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('name')->get();

        $pdf = Pdf::loadView('pdf.products', ['products' => $products]);
        return $pdf->download("products_report.pdf");
    }

    public function productsExcel(Request $request)
    {
        $query = Product::query()->with('category');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('in_stock')) {
            $query->where('in_stock', $request->boolean('in_stock'));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('name')->get();

        $headings = ['ID', 'Название', 'SKU', 'Категория', 'Закупка', 'Продажа', 'Акция', 'Остаток', 'Статус'];
        $rows     = [];
        foreach ($products as $p) {
            $rows[] = [
                $p->id,
                $p->name,
                $p->sku,
                $p->category ? $p->category->name : 'Без категории',
                $p->purchase_price,
                $p->price,
                $p->sale_price,
                $p->stock_quantity,
                $p->is_active ? 'Активен' : 'Скрыт',
            ];
        }

        return $this->exportExcel($rows, $headings, "products_report_" . date('Y-m-d'));
    }

    public function debtsPdf(Request $request)
    {
        $query = \App\Models\CustomerDebt::with(['user', 'order.items.product']);

        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'active') {
                $query->whereIn('status', ['active', 'partial']);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })
                    ->orWhere('order_id', 'like', "%{$search}%");
            });
        }

        $debts          = $query->get();
        $isGrouped      = $request->boolean('isGrouped');
        $expandedGroups = $request->get('expandedGroups', []);

        $data = [
            'debts'          => $debts,
            'isGrouped'      => $isGrouped,
            'expandedGroups' => $expandedGroups,
            'groupedDebts'   => [],
            'title'          => 'БИЗНЕС-ОТЧЕТ: ДЕБИТОРСКАЯ ЗАДОЛЖЕННОСТЬ',
            'date'           => date('d.m.Y H:i'),
        ];

        if ($isGrouped) {
            $grouped = [];
            foreach ($debts as $debt) {
                $userName = $debt->user->name ?? 'Гость';
                $userId   = $debt->user_id ?? ('guest-' . $userName);
                if (! isset($grouped[$userId])) {
                    $grouped[$userId] = [
                        'user'             => $debt->user,
                        'debts'            => [],
                        'total_amount'     => 0,
                        'paid_amount'      => 0,
                        'remaining_amount' => 0,
                    ];
                }
                $grouped[$userId]['debts'][]           = $debt;
                $grouped[$userId]['total_amount']     += $debt->total_amount;
                $grouped[$userId]['paid_amount']      += $debt->paid_amount;
                $grouped[$userId]['remaining_amount'] += $debt->remaining_amount;
            }
            $data['groupedDebts'] = $grouped;
        }

        $pdf = Pdf::loadView('pdf.debts', $data);
        return $pdf->download("debts_report_" . date('Y-m-d') . ".pdf");
    }

    public function debtsExcel(Request $request)
    {
        $query = \App\Models\CustomerDebt::with('user', 'order');

        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'active') {
                $query->whereIn('status', ['active', 'partial']);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })
                    ->orWhere('order_id', 'like', "%{$search}%");
            });
        }

        $debts          = $query->get();
        $isGrouped      = $request->boolean('isGrouped');
        $expandedGroups = $request->get('expandedGroups', []);
        $filename       = "debts_report_" . date('Y-m-d');

        $rows = [];
        if ($isGrouped) {
            $headings = ['ID', 'Клиент', 'Телефон', 'Заказ №', 'Общая сумма', 'Оплачено', 'Остаток', 'Статус', 'Срок'];

            $grouped = [];
            foreach ($debts as $debt) {
                $userName           = $debt->user->name ?? 'Гость';
                $userId             = $debt->user_id ?? ('guest-' . $userName);
                $grouped[$userId][] = $debt;
            }

            foreach ($grouped as $userId => $userDebts) {
                $user      = $userDebts[0]->user;
                $userName  = $user ? $user->name : 'N/A';
                $userPhone = $user ? $user->phone : '';

                $totalSum = collect($userDebts)->sum('total_amount');
                $paidSum  = collect($userDebts)->sum('paid_amount');
                $remSum   = collect($userDebts)->sum('remaining_amount');
                $count    = count($userDebts);

                $rows[] = [
                    '',
                    $userName,
                    $userPhone,
                    $count . ' зак.',
                    $totalSum,
                    $paidSum,
                    $remSum,
                    $remSum > 0 ? 'ДОЛГ' : 'ОПЛАЧЕН',
                    '',
                ];

                if (in_array((string) $userId, $expandedGroups)) {
                    foreach ($userDebts as $d) {
                        $rows[] = [
                            $d->id,
                            '',
                            '',
                            '#' . $d->order_id,
                            $d->total_amount,
                            $d->paid_amount,
                            $d->remaining_amount,
                            $d->status,
                            $d->due_date,
                        ];
                    }
                }

                $rows[] = ['', '', '', '', '', '', '', '', ''];
            }
        } else {
            $headings = ['ID', 'Клиент', 'Телефон', 'Заказ №', 'Общая сумма', 'Оплачено', 'Остаток', 'Статус', 'Срок'];
            foreach ($debts as $d) {
                $rows[] = [
                    $d->id,
                    $d->user ? $d->user->name : 'N/A',
                    $d->user ? $d->user->phone : '',
                    '#' . $d->order_id,
                    $d->total_amount,
                    $d->paid_amount,
                    $d->remaining_amount,
                    $d->status,
                    $d->due_date,
                ];
            }
        }

        return $this->exportExcel($rows, $headings, $filename);
    }

    public function order(Order $order)
    {
        $order->load(['items.product', 'user', 'shippingAddress']);
        $pdf = Pdf::loadView('pdf.order', ['order' => $order]);
        return $pdf->stream("order_invoice_{$order->id}.pdf");
    }

    public function thermalReceipt(Order $order)
    {
        $order->load(['items.product', 'user', 'staff']);
        $settings = $this->getReceiptSettings();

        $pdf = Pdf::loadView('pdf.thermal_receipt', array_merge(['order' => $order], $settings));
        $pdf->setPaper([0, 0, 226.77, 600], 'portrait');
        return $pdf->stream("receipt_{$order->id}.pdf");
    }

    public function orderHtml(Order $order)
    {
        $order->load(['items.product', 'user', 'shippingAddress']);
        $settings = $this->getReceiptSettings();
        return view('pdf.order', array_merge(['order' => $order], $settings));
    }

    public function thermalReceiptHtml(Order $order)
    {
        $order->load(['items.product', 'user', 'staff']);
        $settings = $this->getReceiptSettings();
        return view('pdf.thermal_receipt', array_merge(['order' => $order], $settings));
    }

    private function getReceiptSettings()
    {
        $keys = [
            'receipt_header',
            'receipt_title',
            'receipt_phone',
            'receipt_footer',
            'site_name',
            'contact_phone',
        ];

        $settings = \App\Models\Setting::whereIn('key', $keys)->pluck('value', 'key');

        return [
            'receipt_header' => $settings['receipt_header'] ?? $settings['site_name'] ?? 'Shop',
            'receipt_title'  => $settings['receipt_title'] ?? '',
            'receipt_phone'  => $settings['receipt_phone'] ?? $settings['contact_phone'] ?? '',
            'receipt_footer' => $settings['receipt_footer'] ?? "СПАСИБО ЗА ПОКУПКУ!\nТовар обмену и возврату подлежит\nв течение 14 дней при наличии чека",
        ];
    }

    public function barcode(Product $product)
    {
        $pdf = Pdf::loadView('pdf.barcode', ['product' => $product]);
        $pdf->setPaper([0, 0, 164.41, 113.39], 'portrait');
        return $pdf->stream("barcode_{$product->sku}.pdf");
    }

    private function exportExcel($rows, $headings, $filename)
    {
        $output  = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" /></head><body>';
        $output .= '<table border="1">';
        $output .= '<tr style="background-color: #f2f2f2;">';
        foreach ($headings as $heading) {
            $output .= '<th>' . htmlspecialchars($heading) . '</th>';
        }
        $output .= '</tr>';

        foreach ($rows as $row) {
            $output .= '<tr>';
            foreach ($row as $cell) {
                $output .= '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $output .= '</tr>';
        }
        $output .= '</table></body></html>';

        return response($output, 200, [
            'Content-Type'        => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.xls"',
        ]);
    }
}
