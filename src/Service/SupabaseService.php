<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SupabaseService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,

        #[Autowire('%env(SUPABASE_URL)%')]
        private readonly string $supabaseUrl,

        #[Autowire('%env(SUPABASE_SECRET_KEY)%')]
        private readonly string $supabaseSecretKey,
    ) {
    }

    public function getPublishedScenarios(): array
    {
        $headers = [
            'apikey' => $this->supabaseSecretKey,
            'Accept' => 'application/json',
        ];

        // Compatibilité avec les anciennes clés service_role.
        if (!str_starts_with($this->supabaseSecretKey, 'sb_secret_')) {
            $headers['Authorization'] =
                'Bearer ' . $this->supabaseSecretKey;
        }

        $response = $this->httpClient->request(
            'GET',
            rtrim($this->supabaseUrl, '/') . '/rest/v1/scenarios',
            [
                'headers' => $headers,

                'query' => [
                    'select' => 'id,title,brief,difficulty',
                    'published' => 'eq.true',
                    'order' => 'created_at.desc',
                ],

                'timeout' => 15,
            ]
        );

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(
                sprintf(
                    'Supabase a répondu avec le statut HTTP %d.',
                    $statusCode
                )
            );
        }

        return $response->toArray();
    }
}