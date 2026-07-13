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
            'pos_allow_debt', 'pos_allow_price_change',
            'receipt_header', 'receipt_title', 'receipt_phone', 'receipt_footer',
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
        'pos_allow_debt', 'pos_allow_price_change', 'pos_hot_products_title',
        'receipt_header', 'receipt_title', 'receipt_phone', 'receipt_footer',
    ];

    public function update(Request $request)
    {
        $settings = $request->input('settings', []);

        foreach ($settings as $key => $value) {
            if (! in_array($key, self::EDITABLE_KEYS, true)) {
                continue;
            }

            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return response()->json(['message' => 'Settings updated']);
    }

    public function uploadFile(Request $request)
    {
        $request->validate([
            'file' => 'required|image|max:2048',
            'key'  => 'required|string',
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
