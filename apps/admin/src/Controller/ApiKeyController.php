<?php

declare(strict_types=1);

namespace Admin\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Shared\Ai\AiAssistant;
use Shared\Tooi\TooiService;
use Shared\Domain\ApiKey\ApiKey;
use Shared\Domain\ApiKey\ApiKeyRepository;
use Shared\Service\Security\Authorization\AuthorizationMatrix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Webmozart\Assert\Assert;

#[IsGranted('AuthMatrix.user.read')]
class ApiKeyController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyRepository $repository,
        private readonly AuthorizationMatrix $authorizationMatrix,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly AiAssistant $aiAssistant,
        private readonly TooiService $tooiService,
    ) {
    }

    #[Route('/balie/api-beheer', name: 'app_admin_api_management', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $organisation = $this->authorizationMatrix->getActiveOrganisation();
        Assert::notNull($organisation, 'No active organisation available.');

        $apiKeys = $this->repository->findForOrganisation($organisation);

        return $this->render('admin/api_key/index.html.twig', [
            'organisation' => $organisation,
            'apiKeys' => $apiKeys,
            'createdToken' => $this->getCreatedTokenFromFlash(),
            'activeModel' => $this->aiAssistant->getActiveModel(),
            'availableModels' => $this->aiAssistant->getAvailableModels(),
            'usage' => $this->aiAssistant->getUsage(),
            'tooiQuery' => (string) $request->query->get('tooi_q', ''),
            'tooiResults' => $this->tooiService->search((string) $request->query->get('tooi_q', '')),
        ]);
    }

    #[Route('/balie/api-beheer/nieuw', name: 'app_admin_api_keys_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('api_key', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $organisation = $this->authorizationMatrix->getActiveOrganisation();
        Assert::notNull($organisation, 'No active organisation available.');

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('backend', ['danger' => 'admin.api_key.name_required']);

            return $this->redirectToRoute('app_admin_api_management');
        }

        $expiresAt = null;
        $expiresInput = trim((string) $request->request->get('expires_at', ''));
        if ($expiresInput !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $expiresInput);
            if ($parsed === false) {
                $this->addFlash('backend', ['danger' => 'admin.api_key.expires_invalid']);

                return $this->redirectToRoute('app_admin_api_management');
            }
            $expiresAt = $parsed;
        }

        $plainToken = ApiKey::generateToken();
        $apiKey = new ApiKey($organisation, $name, $plainToken, $expiresAt);

        $this->entityManager->persist($apiKey);
        $this->entityManager->flush();

        // Show the plaintext token exactly once.
        $request->getSession()->getFlashBag()->add('api_key', [
            'key' => $plainToken,
            'name' => $name,
        ]);

        $this->addFlash('backend', ['success' => 'admin.api_key.creation_success']);

        return $this->redirectToRoute('app_admin_api_management');
    }

    #[Route('/balie/api-beheer/{id}/intrekken', name: 'app_admin_api_keys_revoke', methods: ['POST'])]
    public function revoke(Request $request, string $id): Response
    {
        if (! $this->isCsrfTokenValid('api_key', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $organisation = $this->authorizationMatrix->getActiveOrganisation();
        Assert::notNull($organisation, 'No active organisation available.');

        $apiKey = $this->findKey($id, $organisation->getId()->toRfc4122());

        $apiKey->disable();
        $this->entityManager->flush();

        $this->addFlash('backend', ['success' => 'admin.api_key.revoke_success']);

        return $this->redirectToRoute('app_admin_api_management');
    }

    #[Route('/balie/api-beheer/model', name: 'app_admin_ai_model_save', methods: ['POST'])]
    public function saveModel(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('api_key', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $model = trim((string) $request->request->get('model', ''));
        if ($model === '') {
            $this->addFlash('backend', ['danger' => 'admin.ai.model_required']);

            return $this->redirectToRoute('app_admin_api_management');
        }

        $this->aiAssistant->setActiveModel($model);
        $this->addFlash('backend', ['success' => 'admin.ai.model_saved']);

        return $this->redirectToRoute('app_admin_api_management');
    }

    /**
     * @return array{key: string, name: string}|null
     */
    private function getCreatedTokenFromFlash(): ?array
    {
        $flashes = $this->requestStack->getSession()->getFlashBag()->peek('api_key');
        foreach ($flashes as $flash) {
            if (is_array($flash) && isset($flash['key'], $flash['name'])) {
                $this->requestStack->getSession()->getFlashBag()->get('api_key');

                /** @var array{key: string, name: string} $flash */
                return $flash;
            }
        }

        return null;
    }

    private function findKey(string $id, string $organisationId): ApiKey
    {
        $apiKey = $this->repository->find($id);
        if ($apiKey === null || $apiKey->getOrganisation()->getId()->toRfc4122() !== $organisationId) {
            throw $this->createNotFoundException('API key not found.');
        }

        Assert::isInstanceOf($apiKey, ApiKey::class);

        return $apiKey;
    }
}