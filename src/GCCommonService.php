<?php

namespace Drupal\google_events;

use Google_Client;
use Google_Service_Calendar;
use Drupal\node\Entity\Node;
use Google\Service\Calendar\Event as Google_Service_Calendar_Event;
use Eluceo\iCal\Component\Calendar;
use Eluceo\iCal\Component\Event;

require 'vendor/autoload.php';

class GCCommonService
{

    /**
     * Returns an authenticated Google_Client instance.
     *
     * @return Google_Client
     *   An authenticated Google_Client instance.
     */
    public function googleAuth()
    {
        $config = \Drupal::config('google_events.settings');
        $fileId = $config->get('google_calendar_credentials_json')[0];
        $calendarId = $config->get('google_calendar_id');
        $apiKey = $config->get('google_api_key');

        if (!empty($fileId) && !empty($calendarId) && !empty($apiKey)) {
            if ($fileId) {
                // Load the file entity.
                $file = \Drupal\file\Entity\File::load($fileId);
                if ($file instanceof \Drupal\file\FileInterface) {
                    $json_key_path = $file->getFileUri();
                    $client = new Google_Client();
                    $client->setAuthConfig($json_key_path);
                    $client->setApplicationName('EN');

                    $client->setScopes([
                        \Google_Service_Calendar::CALENDAR,
                        \Google_Service_Calendar::CALENDAR_READONLY,
                    ]);

                    // You can add any additional configuration or authentication steps here.

                    return $client;
                } else {
                    \Drupal::logger('google_events')->error('Failed to load file entity with ID: @id', ['@id' => $fileId]);
                }
            } else {
                \Drupal::logger('google_events')->error('Invalid file ID in the configuration: @id', ['@id' => $fileId]);
            }
        }
    }

    public function getCalendarEvents()
    {
        $client = $this->googleAuth();
        $service = new Google_Service_Calendar($client);
        // Calculate the date and time one year ago
        $oneYearAgo = new \DateTime();
        $oneYearAgo->modify('-1 year');
        $timeMin = $oneYearAgo->format('c');

        // Define the time range for events (optional).
        $optParams = array(
            'timeMin' => $timeMin,
            'maxResults' => 1000,
            'singleEvents' => true,
            'orderBy' => 'startTime',
        );
        $config = \Drupal::config('google_events.settings');
        $calendarId = $config->get('google_calendar_id');
        $events = $service->events->listEvents($calendarId, $optParams);

        $result = array();
        foreach ($events->getItems() as $event) {
            $result[] = array(
                'id' => $event->getId(),
                'summary' => $event->getSummary(),
                'description' => $event->getDescription(), // Added description
                'start' => $event->start->dateTime ?? $event->start->date,
                'end' => $event->end->dateTime ?? $event->end->date,
                'location' => $event->getLocation(), // Added location
                'coordinates' => $this->getEventCoordinates($event), // Added coordinates
            );
        }

        return $result;
    }

    /**
     * Helper function to extract coordinates from the Google Calendar event.
     *
     * @param Google_Service_Calendar_Event $event
     *   The Google Calendar event.
     *
     * @return array
     *   An associative array with 'lat' and 'lng' keys for coordinates.
     */
    private function getEventCoordinates($event)
    {
        $coordinates = array();

        if ($event->getLocation()) {
            // Use Google Maps Geocoding API to convert address to coordinates.
            $address = $event->getLocation();
            $coordinates = $this->geocodeAddress($address);

            if (empty($coordinates)) {
                \Drupal::logger('google_events')->error('Failed to retrieve coordinates for address: @address', ['@address' => $address]);
            }
        }

        return $coordinates;
    }

    /**
     * Helper function to geocode an address using Google Maps Geocoding API.
     *
     * @param string $address
     *   The address to geocode.
     *
     * @return array
     *   An associative array with 'lat' and 'lng' keys for coordinates.
     */
    private function geocodeAddress($address)
    {
        $config = \Drupal::config('google_events.settings');
        $googleMapsApiKey = $config->get('google_api_key');

        // Make a request to Google Maps Geocoding API.
        $httpClient = \Drupal::httpClient();
        $response = $httpClient->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'query' => [
                'address' => $address,
                'key' => $googleMapsApiKey,
            ],
        ]);
        $data = json_decode($response->getBody(), true);

        $coordinates = array();
        if (!empty($data['results'][0]['geometry']['location'])) {
            $coordinates['lat'] = $data['results'][0]['geometry']['location']['lat'];
            $coordinates['lng'] = $data['results'][0]['geometry']['location']['lng'];
        }

        return $coordinates;
    }


    /**
     * Updates an event in the specified Google Calendar.
     *
     * @param string $calendarId
     *   The ID of the Google Calendar containing the event to be updated.
     * @param string $eventId
     *   The ID of the event to be updated.
     * @param array $updateData
     *   An associative array containing the updated event data.
     * @return Google_Service_Calendar_Event
     *   The updated Google_Service_Calendar_Event instance.
     */
    public function updateCalendarEvent($eventId, array $updateData)
    {
        $client = $this->googleAuth();

        $service = new Google_Service_Calendar($client);
        $config = \Drupal::config('google_events.settings');
        $calendarId = $config->get('google_calendar_id');

        // Fetch the existing event.
        $event = $service->events->get($calendarId, $eventId);

        // Update the event data.
        foreach ($updateData as $key => $value) {
            // Check if the key is a valid property of the event.
            if (property_exists($event, $key)) {
                $event->{$key} = $value;
            }
        }

        // Update the event in the calendar.
        $updatedEvent = $service->events->update($calendarId, $eventId, $event);

        return $updatedEvent;
    }

    /**
     * Creates Drupal events from the specified Google Calendar events.
     *
     * @param array $googleCalendarEvents
     *   An array of Google Calendar events.
     */
    public function createDrupalEvents(array $googleCalendarEvents)
    {
        foreach ($googleCalendarEvents as $googleEvent) {
            // Check if a node with the given ID already exists.
            $existingNodes = \Drupal::entityQuery('node')
                ->condition('type', 'event')
                ->condition('field_id', $googleEvent['id'])
                ->execute();
            $startDateTimeString = $googleEvent['start'];
            $startDate = new \DateTime($startDateTimeString);
            $startTime = $startDate->format('H:i');

            if (empty($existingNodes)) {
                $node = Node::create([
                    'type' => 'event',
                    'title' => $googleEvent['summary'],
                    'body' => strip_tags($googleEvent['description']) ?? '',
                    'field_event_time' => $startTime,
                    'field_event_start' => date('Y-m-d', strtotime($googleEvent['start'])),
                    'field_event_address' => $googleEvent['location'] ?? '',
                    'field_event_map' => [
                        'lat' => $googleEvent['coordinates']['lat'] ?? '',
                        'lng' => $googleEvent['coordinates']['lng'] ?? '',
                    ],
                    'field_id' => $googleEvent['id'],
                ]);

                $node->save();
            } else {
                // Node with the given ID already exists.
                $nodeId = reset($existingNodes);
                $existingNode = Node::load($nodeId);

                // Check if anything has changed and update the existing node.
                if (
                    $existingNode->getTitle() !== $googleEvent['summary'] ||
                    $existingNode->get('body')->value !== ($googleEvent['description'] ?? '') ||
                    $existingNode->get('field_event_time')->value !== $startTime ||
                    $existingNode->get('field_event_start')->value !== date('Y-m-d', strtotime($googleEvent['start'])) ||
                    $existingNode->get('field_event_address')->value !== ($googleEvent['location'] ?? '') ||
                    $existingNode->get('field_event_map')->lat !== ($googleEvent['coordinates']['lat'] ?? '') ||
                    $existingNode->get('field_event_map')->lng !== ($googleEvent['coordinates']['lng'] ?? '')
                    // Add more conditions for other fields as needed.
                ) {
                    $existingNode->setTitle($googleEvent['summary']);
                    $existingNode->set('body', strip_tags($googleEvent['description']) ?? '');
                    $existingNode->set('field_event_time', $startTime);
                    $existingNode->set('field_event_start', date('Y-m-d', strtotime($googleEvent['start'])));
                    $existingNode->set('field_event_address', $googleEvent['location'] ?? '');
                    $existingNode->set('field_event_map', [
                        'lat' => $googleEvent['coordinates']['lat'] ?? '',
                        'lng' => $googleEvent['coordinates']['lng'] ?? '',
                    ]);

                    // Save the updated node.
                    $existingNode->save();

                    \Drupal::logger('google_events')->info('Node with ID @id has been updated.', ['@id' => $googleEvent['id']]);
                } else {
                    // No changes, log a message.
                    \Drupal::logger('google_events')->info('Node with ID @id already exists, skipping update.', ['@id' => $googleEvent['id']]);
                }
            }
        }
    }

    /**
     * Creates a Google Calendar event based on a Drupal event.
     *
     * @param array $drupalEvent
     *   An associative array containing the Drupal event data.
     */
    public function createGoogleCalendarEvent(array $drupalEvents)
    {
        $client = $this->googleAuth();
        $service = new Google_Service_Calendar($client);

        $createdEvents = [];

        foreach ($drupalEvents as $drupalEvent) {
            try {
                // Get the event ID from the Drupal node.
                $eventId = $drupalEvent->get('field_id')->value;

                // Check if the event with the same ID already exists on Google Calendar.
                if ($eventId === null || !$this->eventExistsOnGoogleCalendar($service, $eventId)) {
                    $eventStartDate = $drupalEvent->get('field_event_start')->value;
                    $eventTime = $drupalEvent->get('field_event_time')->value;

                    // Convert the date to a DateTime object.
                    $startDate = new \DateTime($eventStartDate);

                    // Extract the time components.
                    list($hours, $minutes) = explode(':', $eventTime);

                    // Set the time components to the DateTime object.
                    $startDate->setTime($hours, $minutes);

                    // Format the DateTime object in the desired format.
                    $formattedDateTime = $startDate->format('Y-m-d\TH:i:s\Z');
                    // Prepare event data for Google Calendar.
                    $description = strip_tags($drupalEvent->get('body')->value);
                    $googleEvent = new Google_Service_Calendar_Event([
                        'summary' => $drupalEvent->getTitle(),
                        'description' => $description,
                        'start' => ['dateTime' => $formattedDateTime],
                        'end' => ['dateTime' => $formattedDateTime],
                        'location' => $drupalEvent->get('field_event_address')->value,
                    ]);

                    // Insert the event into the calendar.
                    $config = \Drupal::config('google_events.settings');
                    $calendarId = $config->get('google_calendar_id');
                    $createdEvent = $service->events->insert($calendarId, $googleEvent);
                    $createdEventId = $createdEvent->id;

                    // Update the Drupal node with the Google Calendar event ID.
                    $drupalEvent->set('field_id', $createdEventId);
                    $drupalEvent->save();

                    // Keep track of created events.
                    $createdEvents[] = $createdEventId;
                } else {
                    \Drupal::logger('google_events')->info('Event with ID @id already exists on Google Calendar, skipping creation.', ['@id' => $eventId]);
                }
            } catch (\Exception $e) {
                \Drupal::logger('google_events')->error('Error creating Google Calendar event: @error', ['@error' => $e->getMessage()]);
            }
        }

        return $createdEvents;
    }

    /**
     * Check if an event with the specified ID already exists on Google Calendar.
     *
     * @param Google_Service_Calendar $service
     *   The Google Calendar service.
     * @param string $eventId
     *   The event ID.
     *
     * @return bool
     *   TRUE if the event exists, FALSE otherwise.
     */
    private function eventExistsOnGoogleCalendar(Google_Service_Calendar $service, $eventId)
    {
        try {
            $config = \Drupal::config('google_events.settings');
            $calendarId = $config->get('google_calendar_id');
            $event = $service->events->get($calendarId, $eventId);
            return !empty($event);
        } catch (\Google\Service\Exception $e) {
            // The event does not exist.
            return FALSE;
        }
    }


    public function deleteEventFromGoogleCalendar($eventId)
{
    if (empty($eventId)) {
        return;
    }
    // dump($eventId);die;
    $client = $this->googleAuth();
    $service = new Google_Service_Calendar($client);

    $config = \Drupal::config('google_events.settings');
    $calendarId = $config->get('google_calendar_id');

    try {
        $service->events->delete($calendarId, $eventId);
        \Drupal::logger('google_events')->info('Event with ID @id deleted from Google Calendar.', ['@id' => $eventId]);
    } catch (\Exception $e) {
        \Drupal::logger('google_events')->error('Error deleting event with ID @id from Google Calendar: @error', [
            '@id' => $eventId,
            '@error' => $e->getMessage(),
        ]);
    }
}

/**
     * Creates an iCal file based on the given events.
     *
     * @param array $events
     *   An array of events.
     *
     * @return string
     *   The content of the iCal file.
     */
    public function createICalFile(array $events)
    {
        // Instantiate the Calendar class with the appropriate timezone.
        $calendar = new Calendar('your_timezone_here');

        foreach ($events as $eventData) {
            // Create an event for each entry.
            $event = new Event();
            
            $event
                ->setSummary($eventData['summary'])
                ->setDescription(strip_tags($eventData['description']))
                ->setDtStart(new \DateTime($eventData['start']))
                // ->setDtEnd(new \DateTime($eventData['end']))
                ->setLocation($eventData['location']);

            // Add the event to the calendar.
            $calendar->addComponent($event);
        }

        // Generate iCal content.
        $icalContent = $calendar->render();

        return $icalContent;
    }



}
