<?php
namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

    public function register()
    {

    }

    public function boot()
    {
        if (Schema::hasTable('settings')) {
            View::composer('pdf.*', function ($view) {
                $settings = Setting::all()->pluck('value', 'key');
                $view->with('settings', $settings);
            });
        }
    }
}
