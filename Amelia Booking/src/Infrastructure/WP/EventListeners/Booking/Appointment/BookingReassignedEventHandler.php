<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment;

use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Services\Booking\BookingApplicationService;
use AmeliaBooking\Application\Services\Booking\IcsApplicationService;
use AmeliaBooking\Application\Services\Integration\ApplicationIntegrationService;
use AmeliaBooking\Application\Services\Notification\EmailNotificationService;
use AmeliaBooking\Application\Services\Notification\SMSNotificationService;
use AmeliaBooking\Application\Services\Notification\AbstractWhatsAppNotificationService;
use AmeliaBooking\Application\Services\WebHook\AbstractWebHookApplicationService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Booking\Appointment\Appointment;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Factory\Booking\Appointment\AppointmentFactory;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\String\BookingStatus;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use Exception;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class BookingReassignedEventHandler
 *
 * @package AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment
 */
class BookingReassignedEventHandler
{
    /** @var string */
    const TIME_UPDATED = 'bookingTimeUpdated';

    /**
     * @param CommandResult $commandResult
     * @param Container     $container
     *
     * @throws ContainerValueNotFoundException
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public static function handle($commandResult, $container)
    {
        /** @var ApplicationIntegrationService $applicationIntegrationService */
        $applicationIntegrationService = $container->get('application.integration.service');
        /** @var EmailNotificationService $emailNotificationService */
        $emailNotificationService = $container->get('application.emailNotification.service');
        /** @var SMSNotificationService $smsNotificationService */
        $smsNotificationService = $container->get('application.smsNotification.service');
        /** @var AbstractWhatsAppNotificationService $whatsAppNotificationService */
        $whatsAppNotificationService = $container->get('application.whatsAppNotification.service');
        /** @var SettingsService $settingsService */
        $settingsService = $container->get('domain.settings.service');
        /** @var AbstractWebHookApplicationService $webHookService */
        $webHookService = $container->get('application.webHook.service');
        /** @var BookingApplicationService $bookingApplicationService */
        $bookingApplicationService = $container->get('application.booking.booking.service');
        /** @var IcsApplicationService $icsService */
        $icsService = $container->get('application.ics.service');

        $booking = $commandResult->getData()['booking'];

        $booking['icsFiles'] = $icsService->getIcsData(
            Entities::APPOINTMENT,
            $booking['id'],
            [],
            true
        );

        /** @var Collection $appointments */
        $appointments = new Collection();


        $oldAppointment = $commandResult->getData()['oldAppointment'];

        $oldAppointmentStatusChanged = $commandResult->getData()['oldAppointmentStatusChanged'];

        /** @var Appointment $oldAppointmentObject */
        $oldAppointmentObject = AppointmentFactory::create($oldAppointment);

        $bookingApplicationService->setAppointmentEntities($oldAppointmentObject, $appointments);

        $appointments->addItem($oldAppointmentObject, $oldAppointmentObject->getId()->getValue(), true);

        $oldAppointment = $oldAppointmentObject->toArray();


        $newAppointment = $commandResult->getData()['newAppointment'];

        /** @var Appointment $newAppointmentObject */
        $newAppointmentObject = null;

        if ($newAppointment !== null) {
            $newAppointmentObject = AppointmentFactory::create($newAppointment);

            $bookingApplicationService->setAppointmentEntities($newAppointmentObject, $appointments);

            $appointments->addItem($newAppointmentObject, $newAppointmentObject->getId()->getValue(), true);

            $newAppointment = $newAppointmentObject->toArray();
        }


        $existingAppointment = $commandResult->getData()['existingAppointment'];

        $existingAppointmentStatusChanged = $commandResult->getData()['existingAppointmentStatusChanged'];

        /** @var Appointment $existingAppointmentObject */
        $existingAppointmentObject = null;

        if ($existingAppointment !== null) {
            $existingAppointmentObject = AppointmentFactory::create($existingAppointment);

            $bookingApplicationService->setAppointmentEntities($existingAppointmentObject, $appointments);

            $appointments->addItem($existingAppointmentObject, $existingAppointmentObject->getId()->getValue(), true);

            $existingAppointment = $existingAppointmentObject->toArray();
        }


        // appointment is rescheduled
        if ($existingAppointment === null && $newAppointment === null) {
            foreach ($oldAppointment['bookings'] as $bookingKey => $bookingArray) {
                if ($booking['id'] === $bookingArray['id'] && ($bookingArray['status'] === BookingStatus::APPROVED || $bookingArray['status'] === BookingStatus::PENDING)) {
                    $oldAppointment['bookings'][$bookingKey]['icsFiles'] = $icsService->getIcsData(
                        Entities::APPOINTMENT,
                        $bookingArray['id'],
                        [],
                        true
                    );
                }
            }

            $applicationIntegrationService->handleAppointment(
                $oldAppointmentObject,
                $oldAppointment,
                ApplicationIntegrationService::TIME_UPDATED,
                [
                    ApplicationIntegrationService::SKIP_LESSON_SPACE => true,
                ]
            );

            $oldAppointment['initialAppointmentDateTime'] = $commandResult->getData()['initialAppointmentDateTime'];

            $emailNotificationService->sendAppointmentRescheduleNotifications($oldAppointment);

            if ($settingsService->getSetting('notifications', 'smsSignedIn') === true) {
                $smsNotificationService->sendAppointmentRescheduleNotifications($oldAppointment);
            }

            if ($whatsAppNotificationService->checkRequiredFields()) {
                $whatsAppNotificationService->sendAppointmentRescheduleNotifications($oldAppointment);
            }

            $webHookService->process(self::TIME_UPDATED, $oldAppointment, []);
        }



        // old appointment got status changed to Cancelled because booking is rescheduled to new OR existing appointment
        if ($oldAppointmentObject->getStatus()->getValue() === BookingStatus::CANCELED) {
            $applicationIntegrationService->handleAppointment(
                $oldAppointmentObject,
                $oldAppointment,
                ApplicationIntegrationService::APPOINTMENT_DELETED,
                [
                    ApplicationIntegrationService::SKIP_LESSON_SPACE => true,
                ]
            );
        }

        // booking is rescheduled to new OR existing appointment
        if (($newAppointment !== null || $existingAppointment !== null) &&
            $oldAppointmentObject->getStatus()->getValue() !== BookingStatus::CANCELED
        ) {
            if ($oldAppointmentObject->getZoomMeeting()) {
                $oldAppointment['zoomMeeting'] = $oldAppointmentObject->getZoomMeeting()->toArray();
            }

            $applicationIntegrationService->handleAppointment(
                $oldAppointmentObject,
                $oldAppointment,
                ApplicationIntegrationService::BOOKING_CANCELED,
                [
                    ApplicationIntegrationService::SKIP_ZOOM_MEETING => true,
                    ApplicationIntegrationService::SKIP_LESSON_SPACE => true,
                ]
            );

            if ($oldAppointmentStatusChanged) {
                foreach ($oldAppointment['bookings'] as $bookingKey => $bookingArray) {
                    if ($bookingArray['status'] === BookingStatus::APPROVED || $bookingArray['status'] === BookingStatus::PENDING) {
                        $oldAppointment['bookings'][$bookingKey]['isChangedStatus'] = true;

                        if ($booking['id'] === $bookingArray['id']) {
                            $oldAppointment['bookings'][$bookingKey]['icsFiles'] = $icsService->getIcsData(
                                Entities::APPOINTMENT,
                                $bookingArray['id'],
                                [],
                                true
                            );
                        }
                    }
                }

                $emailNotificationService->sendAppointmentStatusNotifications($oldAppointment, true, true);

                if ($settingsService->getSetting('notifications', 'smsSignedIn') === true) {
                    $smsNotificationService->sendAppointmentStatusNotifications($oldAppointment, true, true);
                }

                if ($whatsAppNotificationService->checkRequiredFields()) {
                    $whatsAppNotificationService->sendAppointmentStatusNotifications($oldAppointment, true, true);
                }
            }
        }

        if ($newAppointment !== null) {
            $applicationIntegrationService->handleAppointment(
                $newAppointmentObject,
                $newAppointment,
                ApplicationIntegrationService::APPOINTMENT_ADDED,
                [
                    ApplicationIntegrationService::SKIP_LESSON_SPACE => true,
                ]
            );

            foreach ($newAppointment['bookings'] as $bookingKey => $bookingArray) {
                if ($booking['id'] === $bookingArray['id'] && ($bookingArray['status'] === BookingStatus::APPROVED || $bookingArray['status'] === BookingStatus::PENDING)) {
                    $newAppointment['bookings'][$bookingKey]['icsFiles'] = $icsService->getIcsData(
                        Entities::APPOINTMENT,
                        $bookingArray['id'],
                        [],
                        true
                    );
                }
            }

            $newAppointment['initialAppointmentDateTime'] = $commandResult->getData()['initialAppointmentDateTime'];

            $emailNotificationService->sendAppointmentRescheduleNotifications($newAppointment);

            if ($settingsService->getSetting('notifications', 'smsSignedIn') === true) {
                $smsNotificationService->sendAppointmentRescheduleNotifications($newAppointment);
            }

            if ($whatsAppNotificationService->checkRequiredFields()) {
                $whatsAppNotificationService->sendAppointmentRescheduleNotifications($newAppointment);
            }

            $webHookService->process(self::TIME_UPDATED, $newAppointment, []);
        } else if ($existingAppointment !== null) {
            $applicationIntegrationService->handleAppointment(
                $existingAppointmentObject,
                $existingAppointment,
                ApplicationIntegrationService::BOOKING_ADDED,
                [
                    ApplicationIntegrationService::SKIP_LESSON_SPACE => true,
                ]
            );

            $booking['icsFiles'] = $icsService->getIcsData(
                Entities::APPOINTMENT,
                $booking['id'],
                [],
                true
            );

            $existingAppointment['initialAppointmentDateTime'] = $commandResult->getData()['initialAppointmentDateTime'];

            $emailNotificationService->sendAppointmentRescheduleNotifications(
                array_merge(
                    $existingAppointment,
                    ['bookings' => [$booking]]
                )
            );

            if ($settingsService->getSetting('notifications', 'smsSignedIn') === true) {
                $smsNotificationService->sendAppointmentRescheduleNotifications(
                    array_merge(
                        $existingAppointment,
                        ['bookings' => [$booking]]
                    )
                );
            }

            if ($whatsAppNotificationService->checkRequiredFields()) {
                $whatsAppNotificationService->sendAppointmentRescheduleNotifications(
                    array_merge(
                        $existingAppointment,
                        ['bookings' => [$booking]]
                    )
                );
            }

            if ($existingAppointmentStatusChanged) {
                foreach ($existingAppointment['bookings'] as $bookingKey => $bookingArray) {
                    if ($bookingArray['status'] === BookingStatus::APPROVED &&
                        $existingAppointment['status'] === BookingStatus::APPROVED &&
                        $bookingArray['id'] !== $booking['id']
                    ) {
                        $existingAppointment['bookings'][$bookingKey]['isChangedStatus'] = true;
                    }
                }

                $emailNotificationService->sendAppointmentStatusNotifications($existingAppointment, true, true);

                if ($settingsService->getSetting('notifications', 'smsSignedIn') === true) {
                    $smsNotificationService->sendAppointmentStatusNotifications($existingAppointment, true, true);
                }

                if ($whatsAppNotificationService->checkRequiredFields()) {
                    $whatsAppNotificationService->sendAppointmentStatusNotifications($existingAppointment, true, true);
                }
            }
        }
    }
}
