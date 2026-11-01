<?php

use Illuminate\Support\Facades\Broadcast;



Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Живой список заказов — всем, кто видит заказы или работает на кассе.
// По правам, а не по названиям ролей: роли динамические (таблица roles).
Broadcast::channel('admin.orders', function ($user) {
    return $user->hasPermission('orders.view')
        || $user->hasPermission('pos.access')
        || $user->hasPermission('cashier.access');
});
