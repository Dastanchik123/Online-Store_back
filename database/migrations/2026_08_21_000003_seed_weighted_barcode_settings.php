<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class SeedWeightedBarcodeSettings extends Migration
{
    private const KEYS = [
        'weighted_barcode_prefix'       => '21',
        'weighted_barcode_weight_digits' => '5',
    ];

    public function up()
    {
        foreach (self::KEYS as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'type' => 'string', 'group' => 'pos', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down()
    {
        DB::table('settings')->whereIn('key', array_keys(self::KEYS))->delete();
    }
}
