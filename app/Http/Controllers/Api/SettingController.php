<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(Request $request)
    {
        $group = $request->get('group');
        $query = Setting::query();
        if ($group) {
            $query->where('group', $group);
        }
        $settings = $query->get();

        $settings->transform(function ($item) {
            $imageKeys = ['site_logo', 'payment_mbank_qr_image'];
            if (in_array($item->key, $imageKeys) && $item->value && ! str_starts_with($item->value, 'http')) {
                $item->value = url(\Illuminate\Support\Facades\Storage::url($item->value));
            }
            return $item;
        });

        return response()->json($settings);
    }

    public function publicSettings()
    {
        // Кэш ответа (сбрасывается при сохранении настроек — ApiCache::bump)
        $payload = \App\Support\ApiCache::remember('settings-public', '', 600, fn () => $this->buildPublicSettings());

        return response()->json($payload);
    }

    private function buildPublicSettings(): array
    {
        $keys = [
            'site_name', 'site_logo', 'contact_phone', 'contact_email',
            'contact_address', 'social_instagram', 'social_whatsapp',
            'social_telegram', 'currency_symbol', 'free_shipping_threshold',
            'payment_contact', 'payment_recipient', 'payment_mbank_qr_image',
            'pos_allow_debt', 'pos_max_discount_percent',
            'receipt_header', 'receipt_title', 'receipt_phone', 'receipt_footer',
            'receipt_public_url',
            'label_template_price_tag', 'label_template_barcode',
            'label_active_template_price_tag', 'label_active_template_barcode',
            'label_templates_all', 'print_label_queue', 'pos_hot_groups',
            'weighted_barcode_prefix', 'weighted_barcode_weight_digits',
        ];

        $settings = Setting::whereIn('key', $keys)->get()->mapWithKeys(function ($item) {
            $value     = $item->value;
            $imageKeys = ['site_logo', 'payment_mbank_qr_image'];
            if (in_array($item->key, $imageKeys) && $value && ! str_starts_with($value, 'http')) {
                $value = url(\Illuminate\Support\Facades\Storage::url($value));
            }
            return [$item->key => $value];
        });

        return $settings->toArray();
    }

    // Ключи, которые реально редактируются через форму настроек
    // (site_logo/payment_mbank_qr_image идут отдельно через uploadFile).
    // Без whitelist сюда можно было записать любой ключ настроек системы.
    private const EDITABLE_KEYS = [
        'site_name', 'site_inn', 'currency_symbol', 'free_shipping_threshold',
        'contact_phone', 'contact_email', 'contact_address',
        'social_instagram', 'social_whatsapp', 'social_telegram',
        'payment_contact', 'payment_recipient',
        // pos_allow_price_change больше не настройка, а право роли pos.change_price
        'pos_allow_debt', 'pos_hot_products_title',
        'pos_max_discount_percent', 'self_service_payment_timeout',
        'online_order_payment_ttl_minutes',
        'receipt_header', 'receipt_title', 'receipt_phone', 'receipt_footer',
        // Адрес сайта для QR на чеке: {адрес}/receipt/{uuid заказа}
        'receipt_public_url',
        'label_template_price_tag', 'label_template_barcode',
        'label_active_template_price_tag', 'label_active_template_barcode',
        'label_templates_all', 'print_label_queue',
        'weighted_barcode_prefix', 'weighted_barcode_weight_digits',
    ];

    public function update(Request $request)
    {
        $settings = $request->input('settings', []);

        // Пустое значение — QR на чеке выключен; иначе только http(s)-адрес,
        // по нему покупатель откроет электронную копию чека
        if (isset($settings['receipt_public_url'])) {
            $url = rtrim(trim((string) $settings['receipt_public_url']), '/');
            if ($url !== '' && ! preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#i', $url)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'receipt_public_url' => ['Адрес сайта для QR должен начинаться с https:// (например, https://shop.kg).'],
                ]);
            }
            $settings['receipt_public_url'] = $url;
        }

        foreach ($settings as $key => $value) {
            if (! in_array($key, self::EDITABLE_KEYS, true)) {
                continue;
            }

            // Процент лимита скидки кассира — только число 0..100
            if ($key === 'pos_max_discount_percent') {
                $value = is_numeric($value) ? (string) max(0, min(100, (float) $value)) : '10';
            }

            // Через сколько минут отменять неоплаченный QR-заказ: от 10 минут до недели
            if ($key === 'online_order_payment_ttl_minutes') {
                $value = is_numeric($value)
                    ? (string) (int) max(10, min(10080, (float) $value))
                    : (string) \App\Console\Commands\ExpireUnpaidOrders::DEFAULT_ONLINE_TTL_MINUTES;
            }

            // Время на оплату QR в киоске, секунды: GoPay принимает 300..86400
            if ($key === 'self_service_payment_timeout') {
                $value = is_numeric($value) ? (string) (int) max(300, min(86400, (float) $value)) : '300';
            }

            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return response()->json(['message' => 'Settings updated']);
    }

    // Очередь печати этикеток — отдельно от общих настроек: её меняет любой
    // сотрудник с products.view (а /settings — только админ), и сохраняется
    // только этот ключ. Раньше страница слала весь снимок публичных настроек
    // и затирала устаревшими значениями то, что успел поменять другой админ.
    public function updatePrintQueue(Request $request)
    {
        $validated = $request->validate([
            'queue'              => 'present|array|max:500',
            'queue.*.id'         => 'required',
            'queue.*.name'       => 'nullable|string|max:255',
            'queue.*.sku'        => 'nullable|string|max:64',
            'queue.*.price'      => 'nullable|numeric|min:0',
            'queue.*.sale_price' => 'nullable|numeric|min:0',
            'queue.*.qty'        => 'required|numeric|min:1|max:10000',
        ]);

        $queue = array_map(fn ($item) => [
            'id'         => $item['id'],
            'name'       => $item['name'] ?? '',
            'sku'        => $item['sku'] ?? '',
            'price'      => (float) ($item['price'] ?? 0),
            'sale_price' => (float) ($item['sale_price'] ?? 0),
            'qty'        => (int) $item['qty'],
        ], $validated['queue']);

        Setting::updateOrCreate(['key' => 'print_label_queue'], ['value' => json_encode($queue, JSON_UNESCAPED_UNICODE)]);

        return response()->json(['message' => 'Queue saved']);
    }

    // ── Группы горячих товаров ──────────────────────────────────────────
    // Раньше группа существовала только как надпись в hot_group у товаров:
    // пустую группу создать было нельзя, а порядок групп на кассе зависел
    // от порядка товаров. Теперь список групп (с порядком) хранится в
    // настройке pos_hot_groups, а товар добавляется в уже созданную группу.

    private function hotGroupsList(): array
    {
        $raw  = Setting::where('key', 'pos_hot_groups')->value('value');
        $list = json_decode($raw ?: '[]', true);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    private function saveHotGroups(array $groups): void
    {
        $clean = [];
        foreach ($groups as $g) {
            $g = trim((string) $g);
            if ($g !== '' && ! in_array(mb_strtolower($g), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $g;
            }
        }
        Setting::updateOrCreate(['key' => 'pos_hot_groups'], ['value' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
    }

    // Сохранить список групп целиком (создание новой, смена порядка)
    public function updateHotGroups(Request $request)
    {
        $validated = $request->validate([
            'groups'   => 'present|array|max:100',
            'groups.*' => 'required|string|max:50',
        ]);

        $this->saveHotGroups($validated['groups']);

        return response()->json(['groups' => $this->hotGroupsList()]);
    }

    // Переименовать группу: и в списке, и у всех её товаров — одной транзакцией
    public function renameHotGroup(Request $request)
    {
        $validated = $request->validate([
            'old_name' => 'required|string|max:50',
            'new_name' => 'required|string|max:50',
        ]);
        $old = $validated['old_name'];
        $new = trim($validated['new_name']);

        \Illuminate\Support\Facades\DB::transaction(function () use ($old, $new) {
            \App\Models\Product::where('hot_group', $old)->update(['hot_group' => $new]);
            $groups = array_map(fn ($g) => $g === $old ? $new : $g, $this->hotGroupsList());
            if (! in_array($new, $groups, true)) {
                $groups[] = $new;
            }
            $this->saveHotGroups($groups);
        });
        // Массовый update не вызывает события модели — сбрасываем кэш API сами
        \App\Support\ApiCache::bump();

        return response()->json(['groups' => $this->hotGroupsList()]);
    }

    // Удалить группу: её товары убираются из горячих
    public function deleteHotGroup(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:50']);
        $name      = $validated['name'];

        \Illuminate\Support\Facades\DB::transaction(function () use ($name) {
            \App\Models\Product::where('hot_group', $name)
                ->update(['is_hot' => false, 'hot_order' => 0, 'hot_group' => '']);
            $this->saveHotGroups(array_filter($this->hotGroupsList(), fn ($g) => $g !== $name));
        });
        \App\Support\ApiCache::bump();

        return response()->json(['groups' => $this->hotGroupsList()]);
    }

    public function uploadFile(Request $request)
    {
        // Только настройки-картинки: раньше сюда можно было записать путь к
        // файлу в любой ключ настроек (например, перетереть pos_allow_debt)
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'key'  => 'required|string|in:site_logo,payment_mbank_qr_image',
        ]);

        if ($request->hasFile('file')) {
            $key  = $request->input('key');
            $path = $request->file('file')->store('settings', 'public');
            

            Setting::updateOrCreate(['key' => $key], [
                'value' => $path,
                'type'  => 'image',
                'group' => 'general',
            ]);

            return response()->json(['path' => url(\Illuminate\Support\Facades\Storage::url($path))]);
        }

        return response()->json(['error' => 'No file'], 400);
    }
}
