<?php

namespace App\Providers;

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Listeners\RunQueueWorkerOnce;
use App\Listeners\SendOrderPlacedEmail;
use App\Listeners\SendOrderStatusEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{

    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        JobQueued::class => [
            RunQueueWorkerOnce::class,
        ],
        NewOrderPlaced::class => [
            SendOrderPlacedEmail::class,
        ],
        OrderStatusUpdated::class => [
            SendOrderStatusEmail::class,
        ],
    ];

    
    public function boot()
    {
        
    }
}
