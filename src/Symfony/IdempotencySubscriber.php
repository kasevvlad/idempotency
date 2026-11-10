<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Symfony;

use Kasevvlad\Idempotency\Idempotency;
use Kasevvlad\Idempotency\Symfony\Attribute\Idempotent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class IdempotencySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
            KernelEvents::RESPONSE => 'onResponse',
            KernelEvents::FINISH_REQUEST => 'onFinishRequest',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        /** @var list<Idempotent> $attributes */
        $attributes = $event->getAttributes()[Idempotent::class] ?? [];

        if ($attributes === []) {
            return;
        }

        $attribute = end($attributes);
        $options = $this->idempotency->options()->with($attribute->ttl, $attribute->required);
        $request = $event->getRequest();

        if ($early = $this->idempotency->begin($request, $this->scope($request), $options)) {
            $event->setController(static fn () => $early);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->idempotency->complete($event->getRequest(), $event->getResponse());
        }
    }

    /**
     * Without a response (an exception nobody caught) the lock would hang until it expires.
     */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->idempotency->abort($event->getRequest());
        }
    }

    private function scope(Request $request): string
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user !== null ? 'user:'.$user->getUserIdentifier() : 'ip:'.$request->getClientIp();
    }
}
