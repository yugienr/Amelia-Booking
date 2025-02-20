<?php

namespace AmeliaBooking\Infrastructure\Routes\Apple;

use AmeliaBooking\Application\Controller\Apple\DisconnectFromAppleCalendarController;
use AmeliaBooking\Application\Controller\Apple\GetAppleCalendarListController;
use Slim\App;

class Apple
{
    public static function routes(App $app)
    {
        $app->get('/apple/calendar-list/{id:[0-9]+}', GetAppleCalendarListController::class);

        $app->post('/apple/disconnect/{id:[0-9]+}', DisconnectFromAppleCalendarController::class);
    }
}