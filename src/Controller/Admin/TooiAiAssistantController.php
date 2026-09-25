<?php

declare(strict_types=1);

namespace Shared\Controller\Admin;

use Shared\Ai\AiAssistant;
use Shared\Tooi\TooiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Small AJAX endpoints used by the admin forms (TOOI lookup + AI text gen).
 * CSP-safe: called with fetch() to the same origin.
 */
#[IsGranted('AuthMatrix.department.read')]
class TooiAiAssistantController extends AbstractController
{
    public function __construct(
        private readonly TooiService $tooiService,
        private readonly AiAssistant $aiAssistant,
    ) {
    }

    #[Route('/balie/tooi/zoek-org', name: 'app_admin_tooi_search_org', methods: ['GET'])]
    public function searchOrganisations(Request $request): JsonResponse
    {
        $query = (string) $request->query->get('q', '');

        return $this->json([
            'results' => $this->tooiService->searchOrganisations($query, 25),
        ]);
    }

    #[Route('/balie/tooi/zoek-cat', name: 'app_admin_tooi_search_cat', methods: ['GET'])]
    public function searchCategories(Request $request): JsonResponse
    {
        $query = (string) $request->query->get('q', '');

        return $this->json([
            'results' => $this->tooiService->search($query, 25),
        ]);
    }

    #[Route('/balie/ai/generate', name: 'app_admin_ai_generate', methods: ['POST'])]
    #[IsGranted('AuthMatrix.user.update')]
    public function generate(Request $request): JsonResponse
    {
        if (! $this->isCsrfTokenValid('ai_generate', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'invalid_token'], 403);
        }

        $context = trim(strip_tags((string) $request->request->get('context', '')));
        $current = trim(strip_tags((string) $request->request->get('current', '')));

        $prompt = 'Schrijf een korte, zakelijke Nederlandse overheidstekst' .
            ($context !== '' ? ' voor: ' . $context : '') .
            '. Maximaal 4 zinnen, geen opsomming, geen aanhef.' .
            ($current !== '' ? ' Bouw voort op deze bestaande tekst: "' . $current . '"' : '');

        try {
            $text = $this->aiAssistant->chat($prompt);

            return $this->json(['text' => $text]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'ai_unavailable'], 502);
        }
    }
}