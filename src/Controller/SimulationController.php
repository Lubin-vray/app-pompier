<?php

namespace App\Controller;

use App\Service\SimulationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

final class SimulationController extends AbstractController
{
    #[Route(
        '/simulation/{id}',
        name: 'app_simulation',
        methods: ['GET']
    )]
    public function page(
        string $id,

        #[Autowire('%env(SUPABASE_URL)%')]
        string $url,

        #[Autowire('%env(SUPABASE_PUBLIC_KEY)%')]
        string $publicKey,
    ): Response {
        $this->validateId($id);

        return $this->render('simulation/index.html.twig', [
            'session_id' => $id,
            'supabase_url' => $url,
            'supabase_public_key' => $publicKey,
        ]);
    }

    #[Route(
        '/api/simulations/start/{id}',
        name: 'api_simulation_start',
        methods: ['POST']
    )]
    #[Route(
        '/api/simulations/{id}',
        name: 'api_simulation_get',
        methods: ['GET']
    )]
    public function api(
        string $id,
        Request $request,
        SimulationService $service,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $this->validateId($id);

            $authorization = $request->headers->get(
                'Authorization',
                ''
            );

            if (!preg_match('/^Bearer (.+)$/', $authorization, $matches)) {
                throw new HttpException(401, 'Connexion requise.');
            }

            $userId = $service->getUserId($matches[1]);

            if (
                $request->attributes->get('_route')
                === 'api_simulation_start'
            ) {
                $session = $service->create($id, $userId);

                return $this->json([
                    'id' => $session['id'],
                ], 201);
            }

            $session = $service->getOwnedSession($id, $userId);

            return $this->json($service->getVisibleData($session));
        } catch (HttpException $exception) {
            return $this->json([
                'error' => $exception->getMessage(),
            ], $exception->getStatusCode());
        } catch (\Throwable $exception) {
            $logger->error('Erreur de simulation.', [
                'exception' => $exception,
            ]);

            return $this->json([
                'error' => 'Erreur serveur. Consultez le journal Symfony.',
            ], 502);
        }
    }

    private function validateId(string $id): void
    {
        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id
        )) {
            throw new HttpException(400, 'Identifiant invalide.');
        }
    }
}