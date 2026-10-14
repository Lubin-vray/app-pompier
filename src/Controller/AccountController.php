<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    #[Route('/compte', name: 'app_account', methods: ['GET'])]
    public function index(
        #[Autowire('%env(SUPABASE_URL)%')]
        string $supabaseUrl,

        #[Autowire('%env(SUPABASE_PUBLIC_KEY)%')]
        string $supabasePublicKey,
    ): Response {
        return $this->render('account/index.html.twig', [
            'supabase_url' => $supabaseUrl,
            'supabase_public_key' => $supabasePublicKey,
        ]);
    }
}