<?php

namespace App\Controller;

use App\Service\IntentAnalyzer;
use App\Service\SimulationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class SimulationMessageController extends AbstractController
{
    #[Route(
        '/api/simulations/{id}/messages',
        name: 'api_simulation_message',
        methods: ['POST']
    )]
    public function send(
        string $id,
        Request $request,
        SimulationService $simulationService,
        IntentAnalyzer $analyzer,
        LoggerInterface $logger,

        #[Autowire(service: 'limiter.simulation_messages')]
        RateLimiterFactory $limiter,
    ): JsonResponse {
        try {
            if (!preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $id
            )) {
                throw new HttpException(400, 'Identifiant invalide.');
            }

            $authorization = $request->headers->get(
                'Authorization',
                ''
            );

            if (!preg_match('/^Bearer (.+)$/', $authorization, $matches)) {
                throw new HttpException(401, 'Connexion requise.');
            }

            $userId = $simulationService->getUserId($matches[1]);

            if (!$limiter->create($userId)->consume()->isAccepted()) {
                throw new HttpException(
                    429,
                    'Trop de messages. Attendez une minute.'
                );
            }

            if (strlen($request->getContent()) > 12000) {
                throw new HttpException(413, 'Message trop volumineux.');
            }

            $body = json_decode(
                $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($body)) {
                throw new HttpException(400, 'Corps de requête invalide.');
            }

            $text = is_string($body['text'] ?? null)
                ? trim($body['text'])
                : '';

            if ($text === '' || mb_strlen($text) > 1500) {
                throw new HttpException(
                    400,
                    'Écrivez entre 1 et 1500 caractères.'
                );
            }

            $session = $simulationService->getOwnedSession($id, $userId);

            if ($session['status'] !== 'active') {
                throw new HttpException(409, 'Simulation terminée.');
            }

            if (($body['revision'] ?? null) !== $session['revision']) {
                throw new HttpException(
                    409,
                    'La simulation a changé. Rechargez la page.'
                );
            }

            $messageCount = count(array_filter(
                $session['transcript'],
                static fn (array $message): bool =>
                    $message['role'] === 'learner'
            ));

            if ($messageCount >= 60) {
                throw new HttpException(
                    400,
                    'Limite de 60 messages atteinte pour cette simulation.'
                );
            }

            $analysis = $analyzer->analyze(
                $text,
                $session['snapshot']['definition']
            );

            $updated = $simulationService->sendMessage(
                $session,
                $text,
                $analysis
            );

            return $this->json(
                $simulationService->getVisibleData($updated)
            );
        } catch (HttpException $exception) {
            return $this->json([
                'error' => $exception->getMessage(),
            ], $exception->getStatusCode());
        } catch (\JsonException $exception) {
            return $this->json([
                'error' => 'Format JSON invalide.',
            ], 400);
        } catch (\Throwable $exception) {
            $logger->error('Erreur d’analyse ou d’enregistrement.', [
                'exception' => $exception,
            ]);

            return $this->json([
                'error' => 'Échec du traitement. Consultez le journal serveur. ' .
                    'Rechargez la simulation pour vérifier son état avant de réessayer.',
            ], 502);
        }
    }
}