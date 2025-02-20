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
use AmeliaBooking\Application\Services\Payment\PaymentApplicationService;
use AmeliaBooking\Application\Services\WebHook\AbstractWebHookApplicationService;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Booking\Appointment\Appointment;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
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
 * Class AppointmentEditedEventHandler
 *
 * @package AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment
 */
class AppointmentEditedEventHandler
{
    /** @var string */
    const APPOINTMENT_EDITED = 'appointmentEdited';
    /** @var string */
    const APPOINTMENT_STATUS_AND_TIME_UPDATED = 'appointmentStatusAndTimeUpdated';
    /** @var string */
    const TIME_UPDATED = 'bookingTimeUpdated';
    /** @var string */
    const BOOKING_STATUS_UPDATED = 'bookingStatusUpdated';
    /** @var string */
    const ZOOM_USER_CHANGED = 'zoomUserChanged';
    /** @var string */
    const ZOOM_LICENCED_USER_CHANGED = 'zoomLicencedUserChanged';
    /** @var string */
    const APPOINTMENT_STATUS_AND_ZOOM_LICENCED_USER_CHANGED = 'appointmentStatusAndZoomLicencedUserChanged';
    /** @var string */
    const BOOKING_ADDED = 'bookingAdded';

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
        /** @var PaymentApplicationService $paymentAS */
        $paymentAS = $container->get('application.payment.service');

        $appointment = $commandResult->getData()[Entities::APPOINTMENT];

        $bookings = $commandResult->getData()['bookingsWithChangedStatus'];

        $appointmentStatusChanged = $commandResult->getData()['appointmentStatusChanged'];

        $appointmentRescheduled = $commandResult->getData()['appointmentRescheduled'];

        $appointmentEmployeeChanged = $commandResult->getData()['appointmentEmployeeChanged'];

        $appointmentZoomUserChanged = $commandResult->getData()['appointmentZoomUserChanged'];

        $bookingAdded = !empty($commandResult->getData()['bookingAdded']) ? $commandResult->getData()['bookingAdded'] : null;

        $appointmentZoomUsersLicenced = $commandResult->getData()['appointmentZoomUsersLicenced'];

        $createPaymentLinks = !empty($commandResult->getData()['createPaymentLinks']) ? $commandResult->getData()['createPaymentLinks'] : null;

        /** @var Appointment $reservationObject */
        $reservationObject = AppointmentFactory::create($appointment);

        $bookingApplicationService->setReservationEntities($reservationObject);

        /** @var CustomerBooking $bookingObject */
        foreach ($reservationObject->getBookings()->getItems() as $bookingObject) {
            foreach ($appointment['bookings'] as $index => $bookingArray) {
                if ($bookingArray['id'] === $bookingObject->getId()->getValue()) {
                    $appointment['bookings'][$index]['customer'] = $bookingObject->getCustomer()->toArray();
                }
            }
        }

        $commandSlug = self::APPOINTMENT_EDITED;

        if ($appointmentEmployeeChanged && $appointmentZoomUserChanged && $appointmentZoomUsersLicenced && $appointmentStatusChanged) {
            $commandSlug = self::APPOINTMENT_STATUS_AND_ZOOM_LICENCED_USER_CHANGED;
        } elseif ($appointmentEmployeeChanged && $appointmentZoomUserChanged && $appointmentZoomUsersLicenced) {
            $commandSlug = self::ZOOM_LICENCED_USER_CHANGED;
        } elseif ($appointmentEmployeeChanged && $appointmentZoomUserChanged) {
            $commandSlug = self::ZOOM_USER_CHANGED;
        } elseif ($appointmentStatusChanged && $appointmentRescheduled) {
            $commandSlug = self::APPOINTMENT_STATUS_AND_TIME_UPDATED;
        } elseif ($appointmentStatusChanged) {
            $commandSlug = self::BOOKING_STATUS_UPDATED;
        } elseif ($appointmentRescheduled) {
            $commandSlug = self::TIME_UPDATED;
        }

        $applicationIntegrationService->handleAppointment(
            $reservationObject,
            $appointment,
            $commandSlug,
            [
                ApplicationIntegrationService::SKIP_GOOGLE_CALENDAR  => true,
                ApplicationIntegrationService::SKIP_OUTLOOK_CALENDAR => true,
                ApplicationIntegrationService::SKIP_APPLE_CALENDAR => true
            ]
        );

        $applicationIntegrationService->handleAppointmentEmployeeChange(
            $reservationObject,
            $appointment,
            $appointmentEmployeeChanged
        );

        foreach ($appointment['bookings'] as $index => $booking) {
            $newBookingKey = array_search($booking['id'], array_column($bookings, 'id'), true);
            if ($createPaymentLinks || $newBookingKey !== false) {
                $paymentId = $booking['payments'][0]['id'];
                $data      = [
                    'booking' => $booking,
                    'type' => Entities::APPOINTMENT,
                    'appointment' => $appointment,
                    'paymentId' => $paymentId,
                    'bookable' => $reservationObject->getService()->toArray(),
                    'customer' => $booking['customer']
                ];
                if (!empty($paymentId)) {
                    $paymentLinks = $paymentAS->createPaymentLink($data, $index);
                    $appointment['bookings'][$index]['payments'][0]['paymentLinks'] = $paymentLinks;
                    if ($newBookingKey !== false) {
                        $bookings[$newBookingKey]['payments'][0]['paymentLinks'] = $paymentLinks;
                    }
                }
            }
        }

        /** @var IcsApplicationService $icsService */
        $icsService = $container->get('application.ics.service');

        // check bookings with changed status for ICS files
        if ($bookings) {
            foreach ($bookings as $index => $booking) {
                if ($booking['status'] === BookingStatus::APPROVED || $booking['status'] === BookingStatus::PENDING) {
                    $bookings[$index]['icsFiles'] = $icsService->getIcsData(
                        Entities::APPOINTMENT,
                        $booking['id'],
                        [],
                        true
                    );
                    $appointment['bookings'][$index]['icsFiles'] = $bookings[$index]['icsFiles'];
                }
            }
        }

        if ($appointmentStatusChanged === true) {
            $emailNotificationService->sendAppointmentStatusNotifications($appointment, true, true, false, !empty($settingsService->getSetting('notifications', 'sendInvoice')));

            if ($settingsService->getSetting('notifications', 'smsSignedIn') === true) {
                $smsNotificationService->sendAppointmentStatusNotifications($appointment, true, true);
            }

            if ($whatsAppNotificationService->checkRequiredFields()) {
                $whatsAppNotificationService->sendAppointmentStatusNotifications($appointment, true, true);
            }
        }

        $appointment['initialAppointmentDateTime'] = $commandResult->getData()['initialAppointmentDateTime'];

        $appointment['employee_changed'] = $appointmentEmployeeChanged;

        if ($appointmentRescheduled === true) {
            foreach ($appointment['bookings'] as $index => $booking) {
                if ($booking['status'] === BookingStatus::APPROVED || $booking['status'] === BookingStatus::PENDING) {
                    $appointment['bookings'][$index]['icsFiles'] = $icsService->getIcsData(
                        Entities::APPOINTMENT,
                        $booking['id'],
                        [],
                        true
                    );
                }
            }
            $emailNotificationService->sendAppointmentRescheduleNotifications($appointment);

            if ($settingsService->getSetting('notifications', 'smsSignedIn') === true) {
                $smsNotificationService->sendAppointmentRescheduleNotifications($appointment);
            }

            if ($whatsAppNotificationService->checkRequiredFields()) {
                $whatsAppNotificationService->sendAppointmentRescheduleNotifications($appointment);
            }
        }

        if (!$appointmentRescheduled || !empty($appointment['employee_changed'])) {
            $emailNotificationService->sendAppointmentEditedNotifications(
                $appointment,
                $bookings,
                $appointmentStatusChanged,
                !empty($settingsService->getSetting('notifications', 'sendInvoice'))
            );

            if ($settingsService->getSetting('notifications', 'smsSignedIn') === true) {
                $smsNotificationService
                    ->sendAppointmentEditedNotifications($appointment, $bookings, $appointmentStatusChanged);
            }

            if ($whatsAppNotificationService->checkRequiredFields()) {
                $whatsAppNotificationService->sendAppointmentEditedNotifications($appointment, $bookings, $appointmentStatusChanged);
            }

            if (!$appointmentStatusChanged) {
                $emailNotificationService->sendAppointmentUpdatedNotifications(
                    $appointment,
                    $appointmentRescheduled
                );

                if ($settingsService->getSetting('notifications', 'smsSignedIn') === true) {
                    $smsNotificationService
                        ->sendAppointmentUpdatedNotifications($appointment, $appointmentRescheduled);
                }

                if ($whatsAppNotificationService->checkRequiredFields()) {
                    $whatsAppNotificationService->sendAppointmentUpdatedNotifications($appointment, $appointmentRescheduled);
                }
            }
        }

        if ($appointmentRescheduled === true) {
            $webHookService->process(self::TIME_UPDATED, $appointment, []);
        }

        if ($bookings) {
            $canceledBookings = [];
            $otherBookings    = [];
            foreach ($bookings as $booking) {
                if ($booking['status'] === BookingStatus::CANCELED) {
                    $canceledBookings[] = $booking;
                } else {
                    $otherBookings[] = $booking;
                }
            }

            if (count($canceledBookings) > 0) {
                $webHookService->process(BookingCanceledEventHandler::BOOKING_CANCELED, $appointment, $canceledBookings);
            }
            if (count($otherBookings) > 0) {
                $webHookService->process(($bookingAdded ? self::BOOKING_ADDED : self::BOOKING_STATUS_UPDATED), $appointment, $otherBookings);
            }
        }
    }
}
