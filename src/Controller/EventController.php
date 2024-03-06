<?php

// File: modules/custom/google_events/src/Controller/EventController.php

namespace Drupal\google_events\Controller;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

class EventController extends ControllerBase
{

  public function downloadICalFile($event_id = NULL)
  {
    // Load your GCCommonService class.
    $gcCommonService = new \Drupal\google_events\GCCommonService();

    // Fetch events based on the presence of the event_id parameter.
    $events = $event_id ? $this->getEventById($event_id) : $this->getAllEvents();

    // Create the iCal file content.
    $icalContent = $gcCommonService->createICalFile($events);

    // Set the headers for file download.
    $headers = [
      'Content-Type' => 'text/calendar; charset=utf-8',
      'Content-Disposition' => 'attachment; filename=events.ics',
    ];

    // Create a response object.
    $response = new Response($icalContent, 200, $headers);
    // Send the response.
    $response->send();
    // Redirect the user to the previous page.
// $referer = \Drupal::request()->headers->get('referer');
// return new RedirectResponse($referer);
    return $response;
  }

  protected function getAllEvents()
  {
    // Get the current date and time.
    $current_date_time = new DrupalDateTime('now', new \DateTimeZone('UTC'));

    // Format the current date and time.
    $current_date_time_formatted = $current_date_time->format(DATE_ISO8601);

    // Fetch all events of the specified type that will occur today or in the future.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'event')
      ->condition('field_event_start', $current_date_time_formatted, '>=');
    $nids = $query->execute();

    $events = [];
    foreach ($nids as $nid) {
      $node = \Drupal\node\Entity\Node::load($nid);
      // Build an array of events with the necessary data.
      $events[] = [
        'summary' => $node->getTitle(),
        'description' => $node->get('body')->value,
        'start' => $node->get('field_event_start')->value,
        'location' => $node->get('field_event_address')->value,
        // Add other fields as needed.
      ];
    }

    return $events;
  }

  protected function getEventById($event_id)
  {
    // Fetch a specific event by ID.
    $node = \Drupal\node\Entity\Node::load($event_id);

    $events = [];
    if ($node) {
      // Build an array with the necessary data for the specific event.
      $events[] = [
        'summary' => $node->getTitle(),
        'description' => $node->get('body')->value,
        'start' => $node->get('field_event_start')->value,
        'location' => $node->get('field_event_address')->value,
        // Add other fields as needed.
      ];
    }

    return $events;
  }

}
