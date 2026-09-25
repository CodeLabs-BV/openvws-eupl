<?php

declare(strict_types=1);

namespace PublicationApi\Domain\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use MinVWS\AuditLogger\AuditLoggerInterface;
use MinVWS\AuditLogger\Events\Logging\UserLoginLogEvent;
use PublicationApi\Domain\OpenApi\ProblemDetails;
use PublicationApi\Domain\OpenApi\ProblemDetailsFactory;
use PublicationApi\Domain\Security\AuditLog\LoginFailedAuditLogEvent;
use Shared\Domain\ApiKey\ApiKey;
use Shared\Domain\ApiKey\ApiKeyRepository;
use Shared\Service\Security\ApiUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Webmozart\Assert\Assert;

use function explode;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

class PublicationApiAuthenticator extends AbstractAuthenticator
{
    private const string SERVER_SSL_USERNAME_KEY = 'SSL_CLIENT_S_DN_CN';
    private const string SERVER_SSL_CLIENT_VERIFY_KEY = 'SSL_CLIENT_VERIFY';
    private const string SERVER_SSL_CLIENT_S_DN_KEY = 'SSL_CLIENT_S_DN';
    private const string BEARER_PREFIX = 'bearer ';

    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
        private readonly GlobDomainValidator $globDomainValidator,
        private readonly ProblemDetailsFactory $problemDetailsFactory,
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'publication_api_ssl_username_whitelist')]
        private readonly string $sslUserNameWhitelist,
        #[Autowire(param: 'publication_api_ssl_organization_identifier')]
        private readonly string $sslOrganizationIdentifier,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->server->has(self::SERVER_SSL_USERNAME_KEY) || $this->hasBearerToken($request);
    }

    public function authenticate(Request $request): Passport
    {
        if ($request->server->has(self::SERVER_SSL_USERNAME_KEY)) {
            return $this->authenticateWithCertificate($request);
        }

        return $this->authenticateWithApiKey($request);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): null
    {
        unset($request, $token, $firewallName);

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        unset($request);

        $problemDetails = $this->problemDetailsFactory->build($exception);
        Assert::isInstanceOf($problemDetails, ProblemDetails::class);

        return new JsonResponse(
            $problemDetails,
            $problemDetails->status,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    private function hasBearerToken(Request $request): bool
    {
        $authorization = $request->headers->get('Authorization');

        return is_string($authorization) && str_starts_with(strtolower($authorization), self::BEARER_PREFIX);
    }

    private function authenticateWithApiKey(Request $request): Passport
    {
        $authorization = (string) $request->headers->get('Authorization');
        $plainToken = trim(substr($authorization, strlen(self::BEARER_PREFIX)));
        if ($plainToken === '') {
            $this->auditLogger->log(new LoginFailedAuditLogEvent('invalid_api_key'));

            throw new AuthenticationException('Missing API key');
        }

        $apiKey = $this->apiKeyRepository->findOneByHash(ApiKey::hashToken($plainToken));
        if ($apiKey === null || ! $apiKey->isUsable(new DateTimeImmutable())) {
            $this->auditLogger->log(new LoginFailedAuditLogEvent('invalid_api_key'));

            throw new AuthenticationException('Invalid API key');
        }

        $apiKey->markUsed(new DateTimeImmutable());
        $this->entityManager->flush();

        $userBadge = new UserBadge($apiKey->getName(), static function () use ($apiKey): ApiUser {
            return new ApiUser($apiKey->getName());
        });

        return new SelfValidatingPassport($userBadge);
    }

    private function authenticateWithCertificate(Request $request): Passport
    {
        $this->checkVerified($request);

        $this->checkOrganizationIdentifier($request);

        $sslUserName = $this->checkSslUsername($request);
        $this->auditLogger->log(new UserLoginLogEvent()->withData([
            'common_name' => $sslUserName,
        ]));

        $userBadge = new UserBadge($sslUserName, static function () use ($sslUserName): ApiUser {
            return new ApiUser($sslUserName);
        });

        return new SelfValidatingPassport($userBadge);
    }

    private function extractOrganizationIdentifier(string $dn): ?string
    {
        return preg_match('/organizationIdentifier\s*=\s*([^,]+)/i', $dn, $matches)
            ? trim($matches[1])
            : null;
    }

    private function checkVerified(Request $request): void
    {
        $sslClientVerify = $request->server->get(self::SERVER_SSL_CLIENT_VERIFY_KEY);

        if ($sslClientVerify !== 'SUCCESS') {
            $this->auditLogger->log(new LoginFailedAuditLogEvent('invalid_certificate'));

            throw new AuthenticationException('Client Certificate is not verified or invalid');
        }
    }

    private function checkOrganizationIdentifier(Request $request): void
    {
        $sslClientDn = $request->server->get(self::SERVER_SSL_CLIENT_S_DN_KEY);

        $organizationIdentifier = is_string($sslClientDn) ? $this->extractOrganizationIdentifier($sslClientDn) : null;
        if ($organizationIdentifier === null || $organizationIdentifier !== $this->sslOrganizationIdentifier) {
            $this->auditLogger->log(new LoginFailedAuditLogEvent('invalid_organization_identifier'));

            throw new AuthenticationException('Client Certificate Organization Identifier is missing or invalid');
        }
    }

    /**
     * @return non-empty-string
     */
    private function checkSslUsername(Request $request): string
    {
        $sslUserName = $request->server->get(self::SERVER_SSL_USERNAME_KEY);

        try {
            Assert::stringNotEmpty($sslUserName);
        } catch (InvalidArgumentException) {
            $this->auditLogger->log(new LoginFailedAuditLogEvent('invalid_common_name'));

            throw new AuthenticationException('Client Certificate Common Name is missing or invalid');
        }

        $whitelist = explode(',', $this->sslUserNameWhitelist);
        if (! $this->globDomainValidator->isValid($whitelist, $sslUserName)) {
            $this->auditLogger->log(new LoginFailedAuditLogEvent('common_name_not_whitelisted'));

            throw new AuthenticationException(
                'Client Certificate Common Name is not whitelisted. Please read the documentation or contact your system administrator.',
            );
        }

        return $sslUserName;
    }
}