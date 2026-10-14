<?php

namespace App\Controller;

use App\Service\SupabaseService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final class ScenarioController extends AbstractController
{
    #[Route('/scenarios', name: 'app_scenarios', methods: ['GET'])]
    public function index(
        SupabaseService $supabaseService,
        LoggerInterface $logger,

        #[Autowire('%env(SUPABASE_URL)%')]
        string $supabaseUrl,

        #[Autowire('%env(SUPABASE_PUBLIC_KEY)%')]
        string $supabasePublicKey,
    ): Response {
        $scenarios = [];
        $error = null;

        try {
            $scenarios = $supabaseService->getPublishedScenarios();
        } catch (\Throwable $exception) {
            $logger->error(
                'Erreur de chargement des scénarios Supabase.',
                [
                    'exception' => $exception,
                ]
            );

            $error = 'Impossible de charger les scénarios pour le moment.';
        }

        $response = $this->render('scenario/index.html.twig', [
            'scenarios' => $scenarios,
            'error' => $error,

            'supabase_url' => $supabaseUrl,
            'supabase_public_key' => $supabasePublicKey,
        ]);

        if ($error !== null) {
            $response->setStatusCode(Response::HTTP_BAD_GATEWAY);
        }

        return $response;
    }
}
