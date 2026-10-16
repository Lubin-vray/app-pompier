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

                'status' => 'active',
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
        $transcript = $session['transcript'] ?? [];

        $learnerMessages = array_values(array_filter(
            $transcript,
            static fn (array $message): bool =>
                ($message['role'] ?? '') === 'learner'
        ));

        $simulatorMessages = array_values(array_filter(
            $transcript,
            static fn (array $message): bool =>
                ($message['role'] ?? '') === 'simulator'
        ));

        $brief = $session['snapshot']['brief'] ?? '';

        $lastLearner = $learnerMessages === []
            ? null
            : end($learnerMessages);

        $lastSimulator = null;

        if ($simulatorMessages !== []) {
            $candidate = end($simulatorMessages);

            $isInitialBrief =
                count($simulatorMessages) === 1
                && trim($candidate['content'] ?? '') === trim($brief);

            if (!$isInitialBrief) {
                $lastSimulator = $candidate;
            }
        }

        return [
            'id' => $session['id'],
            'title' => $session['snapshot']['title'],
            'brief' => $session['snapshot']['brief'],
            'status' => $session['status'],
            'revision' => $session['revision'],
            'score' => $this->calculateScore($session),
            'missing_actions' => $this->getMissingActions($session),
            'image' => $this->getCurrentImage($session),
            'hemorrhage_revealed' => $this->isHemorrhageRevealed($session),

            'last_exchange' => [
                'learner' => $lastLearner === false
                    ? null
                    : [
                        'role' => $lastLearner['role'],
                        'content' => $lastLearner['content'],
                    ],
                'simulator' => $lastSimulator === false
                    ? null
                    : [
                        'role' => $lastSimulator['role'],
                        'content' => $lastSimulator['content'],
                    ],
            ],
        ];
    }

    public function getCurrentImage(array $session): string
    {
        $definition = $session['snapshot']['definition'] ?? [];
        $images = $definition['images'] ?? [];

        $done = $session['state']['done'] ?? [];

        $hemorrhageDiscovered = in_array('check_x', $done, true)
            || in_array('identify_hemorrhage', $done, true);

        if ($hemorrhageDiscovered && !empty($images['hemorrhage'])) {
            return $images['hemorrhage'];
        }

        return $images['scene'] ?? '';
    }

    public function isHemorrhageRevealed(array $session): bool
    {
        $done = $session['state']['done'] ?? [];

        return in_array('check_x', $done, true)
            || in_array('identify_hemorrhage', $done, true);
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

        $victimReply = trim($analysis['victim_reply'] ?? '');

        if ($analysis['needs_clarification']) {
            if ($victimReply === '') {
                $replies[] =
                    'Simulateur : précisez ce que vous vérifiez ' .
                    'ou réalisez. Aucune action n’est validée.';
            }
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
                    $alreadyDone = in_array($intentId, $done, true);

                    if (!$alreadyDone) {
                        $done[] = $intentId;
                    }

                    $replies[] = $objective['response'];

                    $events[] = [
                        'id' => $intentId,
                        'outcome' => $alreadyDone ? 'repeated' : 'done',
                    ];

                    continue;
                }

                if ($victimReply === '') {
                    foreach ($definition['questions'] ?? [] as $question) {
                        if ($question['id'] === $intentId) {
                            $replies[] =
                                'Victime : ' . $question['response'];
                            break;
                        }
                    }
                }
            }
        }

        if ($victimReply !== '') {
            $replies[] = 'Victime : ' . $victimReply;
        }

        if ($replies === []) {
            $replies[] =
                'Simulateur : décrivez plus précisément votre ' .
                'question ou votre action. Aucun critère n’est validé.';
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

        $status = $session['status'];

        if ($this->isSimulationCompleted($definition, $done)) {
            $status = 'completed';
        }

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
                'status' => $status,
                'updated_at' => $now,
            ]
        );

        if (empty($updatedSessions[0])) {
            throw new HttpException(
                409,
                'La simulation a changé. ' .
                'Rechargez la page avant de réessayer.'
            );
        }

        return $updatedSessions[0];
    }

    public function calculateScore(array $session): array
    {
        $definition = $session['snapshot']['definition'] ?? [];
        $scoring = $definition['scoring'] ?? [];

        $done = $session['state']['done'] ?? [];

        $primaryPoints = 0.0;
        $hemorrhagePoints = 0.0;

        foreach ($scoring['primary_assessment'] ?? [] as $actionId => $points) {
            if (in_array($actionId, $done, true)) {
                $primaryPoints += (float) $points;
            }
        }

        foreach ($scoring['hemorrhage_management'] ?? [] as $actionId => $points) {
            if (in_array($actionId, $done, true)) {
                $hemorrhagePoints += (float) $points;
            }
        }

        $total = $primaryPoints + $hemorrhagePoints;

        return [
            'total' => round($total, 1),
            'max' => 100,
            'primary_assessment' => [
                'earned' => round($primaryPoints, 1),
                'max' => 50,
            ],
            'hemorrhage_management' => [
                'earned' => round($hemorrhagePoints, 1),
                'max' => 50,
            ],
            'completed_actions' => $done,
        ];
    }

    public function getMissingActions(array $session): array
    {
        $definition = $session['snapshot']['definition'] ?? [];
        $done = $session['state']['done'] ?? [];

        $missing = [];

        foreach ($definition['objectives'] ?? [] as $objective) {
            $actionId = $objective['id'];

            if (!in_array($actionId, $done, true)) {
                $missing[] = [
                    'id' => $actionId,
                    'label' => $objective['label']
                        ?? $objective['description']
                        ?? $actionId,
                ];
            }
        }

        return $missing;
    }

    private function isSimulationCompleted(
        array $definition,
        array $done,
    ): bool {
        $endAction = $definition['end_condition']['action'] ?? null;

        return $endAction !== null
            && in_array($endAction, $done, true);
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

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(
                'Supabase HTTP ' . $statusCode . ' : ' .
                $response->getContent(false)
            );
        }

        return $response->toArray();
    }
}