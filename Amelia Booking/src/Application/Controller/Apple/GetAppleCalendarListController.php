<?php

namespace AmeliaBooking\Application\Controller\Apple;

use AmeliaBooking\Application\Commands\Apple\GetAppleCalendarListCommand;
use AmeliaBooking\Application\Commands\Command;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

class GetAppleCalendarListController extends Controller
{
    protected function instantiateCommand(Request $request, $args): Command
    {
        $command = new GetAppleCalendarListCommand($args);

        $requestBody = $request->getParsedBody();

        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}