<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SimulationService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,

        #[Autowire('%env(SUPABASE_URL)%')]
        private readonly string $url,

        #[Autowire('%env(SUPABASE_PUBLIC_KEY)%')]
        private readonly string $publicKey,

        #[Autowire('%env(SUPABASE_SECRET_KEY)%')]
        private readonly string $secretKey,
    ) {
    }

    public function getUserId(string $accessToken): string
    {
        $response = $this->httpClient->request(
            'GET',
            rtrim($this->url, '/') . '/auth/v1/user',
            [
                'headers' => [
                    'apikey' => $this->publicKey,
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'timeout' => 15,
            ]
        );

        if ($response->getStatusCode() !== 200) {
            throw new HttpException(
                401,
                'Session invalide ou expirée. Reconnectez-vous.'
            );
        }

        $user = $response->toArray();

        if (empty($user['id'])) {
            throw new HttpException(
                401,
                'Utilisateur non identifié.'
            );
        }

        return $user['id'];
    }

    public function create(string $scenarioId, string $userId): array
    {
        $scenarios = $this->request(
            'GET',
            'scenarios',
            [
                'id' => 'eq.' . $scenarioId,
                'published' => 'eq.true',
                'select' => '*',
            ]
        );

        $scenario = $scenarios[0] ?? null;

        if ($scenario === null) {
            throw new HttpException(
                404,
                'Scénario introuvable.'
            );
        }

        $sessions = $this->request(
            'POST',
            'simulation_sessions',
            [],
            [
                'user_id' => $userId,
                'scenario_id' => $scenarioId,

                'snapshot' => [
                    'title' => $scenario['title'],
                    'brief' => $scenario['brief'],
                    'definition' => $scenario['definition'],
                ],

                'state' => [
                    'facts' => (object) (
                        $scenario['definition']['initial_state'] ?? []
                    ),
                    'done' => [],
                ],

                'transcript' => [
                    [
                        'role' => 'simulator',
                        'content' => $scenario['brief'],
                        'at' => gmdate('c'),
                    ],
                ],
            ]
        );

        if (empty($sessions[0])) {
            throw new \RuntimeException(
                'La création n’a pas retourné de simulation.'
            );
        }

        return $sessions[0];
    }

    public function getOwnedSession(
        string $sessionId,
        string $userId,
    ): array {
        $sessions = $this->request(
            'GET',
            'simulation_sessions',
            [
                'id' => 'eq.' . $sessionId,
                'user_id' => 'eq.' . $userId,
                'select' => '*',
            ]
        );

        if (empty($sessions[0])) {
            throw new HttpException(
                404,
                'Simulation introuvable.'
            );
        }

        return $sessions[0];
    }

    public function getVisibleData(array $session): array
    {
        return [
            'id' => $session['id'],
            'title' => $session['snapshot']['title'],
            'brief' => $session['snapshot']['brief'],
            'status' => $session['status'],
            'revision' => $session['revision'],

            // Les faits cachés, les règles et les événements internes
            // ne sont pas transmis au navigateur.
            'transcript' => array_map(
                static fn (array $message): array => [
                    'role' => $message['role'],
                    'content' => $message['content'],
                ],
                $session['transcript']
            ),
        ];
    }

    public function sendMessage(
        array $session,
        string $text,
        array $analysis,
    ): array {
        if ($session['status'] !== 'active') {
            throw new HttpException(
                409,
                'Cette simulation est terminée.'
            );
        }

        $definition = $session['snapshot']['definition'];

        $done = $session['state']['done'] ?? [];
        $replies = [];
        $events = [];

        if ($analysis['needs_clarification']) {
            $replies[] =
                'Simulateur : précisez votre question ou ce que vous faites ' .
                'réellement. Aucune action n’est validée pour ce message.';
        } else {
            foreach (array_unique($analysis['intents']) as $intentId) {
                $objective = null;

                foreach ($definition['objectives'] ?? [] as $item) {
                    if ($item['id'] === $intentId) {
                        $objective = $item;
                        break;
                    }
                }

                if ($objective !== null) {
                    $missingPrerequisites = array_diff(
                        $objective['prerequisites'] ?? [],
                        $done
                    );

                    if ($missingPrerequisites !== []) {
                        $replies[] =
                            'Simulateur : cette action ne peut pas être ' .
                            'comptabilisée à ce stade. Les vérifications ' .
                            'préalables prévues par ce cas ne sont pas réalisées.';

                        $events[] = [
                            'id' => $intentId,
                            'outcome' => 'blocked',
                        ];

                        continue;
                    }

                    $alreadyDone = in_array(
                        $intentId,
                        $done,
                        true
                    );

                    if (!$alreadyDone) {
                        $done[] = $intentId;
                    }

                    $replies[] = $objective['response'];

                    $events[] = [
                        'id' => $intentId,
                        'outcome' => $alreadyDone
                            ? 'repeated'
                            : 'done',
                    ];

                    continue;
                }

                foreach ($definition['questions'] ?? [] as $question) {
                    if ($question['id'] === $intentId) {
                        $replies[] = $question['response'];
                        break;
                    }
                }
            }
        }

        if ($replies === []) {
            $replies[] =
                'Simulateur : cette question ou action n’est pas prévue ' .
                'dans ce cas de démonstration. Elle n’a pas été comptabilisée.';
        }

        $transcript = $session['transcript'];
        $now = gmdate('c');

        $transcript[] = [
            'role' => 'learner',
            'content' => $text,
            'at' => $now,
            'events' => $events,
        ];

        $transcript[] = [
            'role' => 'simulator',
            'content' => implode("\n\n", $replies),
            'at' => $now,
        ];

        $state = $session['state'];
        $state['done'] = $done;
        $state['facts'] = (object) ($state['facts'] ?? []);

        $updatedSessions = $this->request(
            'PATCH',
            'simulation_sessions',
            [
                'id' => 'eq.' . $session['id'],
                'user_id' => 'eq.' . $session['user_id'],
                'revision' => 'eq.' . $session['revision'],
            ],
            [
                'state' => $state,
                'transcript' => $transcript,
                'revision' => $session['revision'] + 1,
                'updated_at' => $now,
            ]
        );

        if (empty($updatedSessions[0])) {
            throw new HttpException(
                409,
                'La simulation a changé. Rechargez la page avant de réessayer.'
            );
        }

        return $updatedSessions[0];
    }

    private function request(
        string $method,
        string $table,
        array $query = [],
        ?array $body = null,
    ): array {
        $headers = [
            'apikey' => $this->secretKey,
            'Accept' => 'application/json',
            'Prefer' => 'return=representation',
        ];

        if (!str_starts_with($this->secretKey, 'sb_secret_')) {
            $headers['Authorization'] =
                'Bearer ' . $this->secretKey;
        }

        $options = [
            'headers' => $headers,
            'query' => $query,
            'timeout' => 15,
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->httpClient->request(
            $method,
            rtrim($this->url, '/') . '/rest/v1/' . $table,
            $options
        );

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'Supabase HTTP ' . $status . ' : ' .
                $response->getContent(false)
            );
        }

        return $response->toArray();
    }
}