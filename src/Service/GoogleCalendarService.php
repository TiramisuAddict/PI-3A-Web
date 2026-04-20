<?php

namespace App\Service;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;

final class GoogleCalendarService
{
    public function __construct(private readonly GoogleTokenSessionService $googleTokenSession)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUpcomingEvents(string $calendarId = 'primary', int $maxResults = 10): array
    {
        $service = $this->buildCalendarService();

        $events = $service->events->listEvents($calendarId, [
            'maxResults' => $maxResults,
            'orderBy' => 'startTime',
            'singleEvents' => true,
            'timeMin' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_RFC3339),
        ]);

        $result = [];
        foreach ($events->getItems() as $item) {
            $start = $item->getStart()?->getDateTime() ?: $item->getStart()?->getDate();
            $end = $item->getEnd()?->getDateTime() ?: $item->getEnd()?->getDate();

            $result[] = [
                'id' => $item->getId(),
                'summary' => $item->getSummary(),
                'description' => $item->getDescription(),
                'htmlLink' => $item->getHtmlLink(),
                'start' => $start,
                'end' => $end,
                'status' => $item->getStatus(),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function createEvent(
        string $summary,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        string $calendarId = 'primary',
        string $timeZone = 'UTC',
        ?string $description = null
    ): array {
        $service = $this->buildCalendarService();

        $event = new Event([
            'summary' => $summary,
            'description' => $description,
            'start' => new EventDateTime([
                'dateTime' => $start->format(DATE_RFC3339),
                'timeZone' => $timeZone,
            ]),
            'end' => new EventDateTime([
                'dateTime' => $end->format(DATE_RFC3339),
                'timeZone' => $timeZone,
            ]),
        ]);

        $created = $service->events->insert($calendarId, $event);

        return [
            'id' => $created->getId(),
            'summary' => $created->getSummary(),
            'htmlLink' => $created->getHtmlLink(),
            'status' => $created->getStatus(),
        ];
    }

    private function buildCalendarService(): Calendar
    {
        $client = new Client();
        $client->setAccessToken([
            'access_token' => $this->googleTokenSession->requireAccessToken(),
        ]);

        return new Calendar($client);
    }
}
