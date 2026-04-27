<?php

namespace App\Service;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\EntryPoint;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;

final class GoogleMeetService
{
    public function __construct(private readonly GoogleTokenSessionService $googleTokenSession)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function createMeetEvent(
        string $summary,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        string $calendarId = 'primary',
        string $timeZone = 'UTC',
        ?string $description = null,
        ?string $attendeeEmail = null
    ): array {
        $normalizedAttendeeEmail = is_string($attendeeEmail) ? mb_strtolower(trim($attendeeEmail)) : null;
        if (!$normalizedAttendeeEmail || !filter_var($normalizedAttendeeEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid attendee email is required to send Google invite notifications.');
        }

        $service = $this->buildCalendarService();

        $conferenceRequest = new CreateConferenceRequest();
        $conferenceRequest->setRequestId('meet_' . bin2hex(random_bytes(8)));
        $conferenceRequest->setConferenceSolutionKey(new ConferenceSolutionKey([
            'type' => 'hangoutsMeet',
        ]));

        $conferenceData = new ConferenceData();
        $conferenceData->setCreateRequest($conferenceRequest);

        $eventData = [
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
            'conferenceData' => $conferenceData,
            'attendees' => [
                new EventAttendee([
                    'email' => $normalizedAttendeeEmail,
                    'responseStatus' => 'needsAction',
                ]),
            ],
        ];

        $event = new Event($eventData);

        $created = $service->events->insert($calendarId, $event, [
            'conferenceDataVersion' => 1,
            'sendUpdates' => 'all',
            'sendNotifications' => true,
        ]);

        $entryPoints = $created->getConferenceData()?->getEntryPoints() ?? [];

        return [
            'id' => $created->getId(),
            'summary' => $created->getSummary(),
            'htmlLink' => $created->getHtmlLink(),
            'meetLink' => $this->resolveMeetUrl($created->getHangoutLink(), $entryPoints),
            'status' => $created->getStatus(),
        ];
    }

    /**
     * @param array<int, EntryPoint> $entryPoints
     */
    private function resolveMeetUrl(?string $hangoutLink, array $entryPoints): ?string
    {
        if (is_string($hangoutLink) && $hangoutLink !== '') {
            return $hangoutLink;
        }

        foreach ($entryPoints as $entryPoint) {
            if ($entryPoint->getEntryPointType() === 'video' && $entryPoint->getUri()) {
                return $entryPoint->getUri();
            }
        }

        return null;
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
