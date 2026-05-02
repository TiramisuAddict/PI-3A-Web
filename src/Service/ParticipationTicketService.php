<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ParticipationTicketService
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTicketByParticipationId(int $participationId): ?array
    {
        $row = $this->connection->executeQuery(
            'SELECT p.id_participation, p.utilisateur_id, p.post_id, p.statut, p.date_action,
                    COALESCE(e.prenom, "") AS prenom, COALESCE(e.nom, "") AS nom,
                    COALESCE(post.titre, "") AS post_titre, post.date_evenement, post.date_fin_evenement, COALESCE(post.lieu, "") AS lieu
             FROM participation p
             INNER JOIN post ON post.id_post = p.post_id
             LEFT JOIN employe e ON e.id_employe = p.utilisateur_id
             WHERE p.id_participation = ?',
            [$participationId]
        )->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        return $this->normalizeTicketRow($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function normalizeTicketRow(array $row): array
    {
        return [
            'id_participation' => (int) $row['id_participation'],
            'utilisateur_id' => (int) $row['utilisateur_id'],
            'post_id' => (int) $row['post_id'],
            'statut' => (string) ($row['statut'] ?? ''),
            'date_action' => $this->normalizeDateValue($row['date_action'] ?? null),
            'date_evenement' => $this->normalizeDateValue($row['date_evenement'] ?? null, 'Y-m-d'),
            'date_fin_evenement' => $this->normalizeDateValue($row['date_fin_evenement'] ?? null, 'Y-m-d'),
            'prenom' => (string) ($row['prenom'] ?? ''),
            'nom' => (string) ($row['nom'] ?? ''),
            'post_titre' => (string) ($row['post_titre'] ?? ''),
            'lieu' => (string) ($row['lieu'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $ticket
     */
    public function generateSignature(array $ticket): string
    {
        $payload = implode('|', [
            (int) ($ticket['id_participation'] ?? 0),
            (int) ($ticket['utilisateur_id'] ?? 0),
            (int) ($ticket['post_id'] ?? 0),
            (string) ($ticket['date_action'] ?? ''),
        ]);

        return substr(hash_hmac('sha256', $payload, $this->appSecret), 0, 24);
    }

    /**
     * @param array<string, mixed> $ticket
     */
    public function isSignatureValid(array $ticket, string $signature): bool
    {
        return hash_equals($this->generateSignature($ticket), $signature);
    }

    /**
     * @param array<string, mixed> $ticket
     */
    public function getDisplayName(array $ticket): string
    {
        $label = trim(sprintf('%s %s', (string) ($ticket['prenom'] ?? ''), (string) ($ticket['nom'] ?? '')));

        return $label !== '' ? $label : 'Employe';
    }

    private function normalizeDateValue(mixed $value, string $fallbackFormat = \DateTimeInterface::ATOM): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($fallbackFormat);
        }

        try {
            return (new \DateTimeImmutable((string) $value))->format($fallbackFormat);
        } catch (\Throwable) {
            return null;
        }
    }
}
