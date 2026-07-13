<?php

namespace App\Support;

use App\Models\CustomerDebt;
use App\Models\Product;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;

// Генерация тяжёлых PDF-отчётов (полный каталог, все долги) — вынесено из
// ReportController, чтобы одну и ту же логику могли использовать и
// синхронный download (маленькие выборки), и очередь (GenerateReportExport).
class ReportExportService
{
    public function productsPdf(array $params): string
    {
        // dompdf на полном каталоге (1000+ строк) не влезает в дефолтные
        // 128M / 30s — машина имеет 1 ГБ, поднимаем лимиты точечно
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $query = Product::query()->with('category:id,name');

        if (! empty($params['category_id'])) {
            $query->where('category_id', $params['category_id']);
        }
        if (array_key_exists('is_active', $params) && $params['is_active'] !== null && $params['is_active'] !== '') {
            $query->where('is_active', filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('in_stock', $params) && $params['in_stock'] !== null && $params['in_stock'] !== '') {
            $query->where('in_stock', filter_var($params['in_stock'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('name')->get();

        return Pdf::loadView('pdf.products', ['products' => $products, 'settings' => $this->getReceiptSettings()])
            ->output();
    }

    public function debtsPdf(array $params): string
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $query = CustomerDebt::with(['user', 'order.items.product']);

        if (! empty($params['status']) && $params['status'] !== 'all') {
            if ($params['status'] === 'active') {
                $query->whereIn('status', ['active', 'partial']);
            } else {
                $query->where('status', $params['status']);
            }
        }

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })->orWhere('order_id', 'like', "%{$search}%");
            });
        }

        $debts          = $query->get();
        $isGrouped      = filter_var($params['isGrouped'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $expandedGroups = $params['expandedGroups'] ?? [];

        $data = [
            'debts'          => $debts,
            'isGrouped'      => $isGrouped,
            'expandedGroups' => $expandedGroups,
            'groupedDebts'   => [],
            'title'          => 'БИЗНЕС-ОТЧЕТ: ДЕБИТОРСКАЯ ЗАДОЛЖЕННОСТЬ',
            'date'           => date('d.m.Y H:i'),
            'settings'       => $this->getReceiptSettings(),
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

        return Pdf::loadView('pdf.debts', $data)->output();
    }

    private function getReceiptSettings(): array
    {
        $keys = [
            'receipt_header', 'receipt_title', 'receipt_phone', 'receipt_footer',
            'site_name', 'site_inn', 'contact_phone', 'contact_address',
        ];

        $settings = Setting::whereIn('key', $keys)->pluck('value', 'key');

        return [
            'receipt_header'  => $settings['receipt_header'] ?? $settings['site_name'] ?? 'Shop',
            'receipt_title'   => $settings['receipt_title'] ?? '',
            'receipt_phone'   => $settings['receipt_phone'] ?? $settings['contact_phone'] ?? '',
            'receipt_footer'  => $settings['receipt_footer'] ?? "СПАСИБО ЗА ПОКУПКУ!\nТовар обмену и возврату подлежит\nв течение 14 дней при наличии чека",
            'site_name'       => $settings['site_name'] ?? 'Мой Магазин',
            'site_inn'        => $settings['site_inn'] ?? null,
            'contact_phone'   => $settings['contact_phone'] ?? null,
            'contact_address' => $settings['contact_address'] ?? null,
        ];
    }
}
