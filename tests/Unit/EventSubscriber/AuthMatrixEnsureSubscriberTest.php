<?php

declare(strict_types=1);

namespace Shared\Tests\Unit\EventSubscriber;

use Mockery;
use Mockery\MockInterface;
use Shared\EventSubscriber\AuthMatrixEnsureSubscriber;
use Shared\Service\Security\Authorization\AuthorizationEntryRequestStore;
use Shared\Service\Security\Authorization\Entry;
use Shared\Tests\Unit\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;

class AuthMatrixEnsureSubscriberTest extends UnitTestCase
{
    private AuthorizationEntryRequestStore&MockInterface $store;
    private AuthMatrixEnsureSubscriber $subscriber;
    private Request $request;

    protected function setUp(): void
    {
        $this->store = Mockery::mock(AuthorizationEntryRequestStore::class);
        $this->request = Request::create('/balie/gebruikers');
        $this->subscriber = new AuthMatrixEnsureSubscriber($this->store);
    }

    public function testOnlyMainRequestsAreChecked(): void
    {
        $event = new ControllerArgumentsEvent(
            Mockery::mock(Kernel::class),
            static fn () => true,
            [],
            $this->request,
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->expectNotToPerformAssertions();

        $this->subscriber->__invoke($event);
    }

    public function testNonBalieUrlsAreNotChecked(): void
    {
        $this->request = Request::create('/contact');

        $event = new ControllerArgumentsEvent(
            Mockery::mock(Kernel::class),
            static fn () => true,
            [],
            $this->request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->subscriber->__invoke($event);
    }

    public function testWhitelistedAdminUrlIsNotChecked(): void
    {
        $this->request = Request::create('/balie/admin');

        $event = new ControllerArgumentsEvent(
            Mockery::mock(Kernel::class),
            static fn () => true,
            [],
            $this->request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->subscriber->__invoke($event);
    }

    public function testBalieUrlWithoutStoredEntriesTriggersAccessDenied(): void
    {
        $this->request = Request::create('/balie/dossiers');

        $this->store->expects('getEntries')->andReturn([]);

        $event = new ControllerArgumentsEvent(
            Mockery::mock(Kernel::class),
            static fn () => true,
            [],
            $this->request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->expectException(AccessDeniedHttpException::class);

        $this->subscriber->__invoke($event);
    }

    public function testBalieUrlsWithStoredEntriesIsAccepted(): void
    {
        $this->request = Request::create('/balie/dossiers');

        $this->store->expects('getEntries')->andReturn([Mockery::mock(Entry::class)]);

        $event = new ControllerArgumentsEvent(
            Mockery::mock(Kernel::class),
            static fn () => true,
            [],
            $this->request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->subscriber->__invoke($event);
    }

    public function testApiDocsUrlIsWhitelisted(): void
    {
        $this->request = Request::create('/balie/api-docs');

        $this->store->shouldNotReceive('getEntries');

        $event = new ControllerArgumentsEvent(
            Mockery::mock(Kernel::class),
            static fn () => true,
            [],
            $this->request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->subscriber->__invoke($event);
    }
}
