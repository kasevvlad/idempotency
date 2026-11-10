<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Tests\Symfony;

use Kasevvlad\Idempotency\Idempotency;
use Kasevvlad\Idempotency\Symfony\Attribute\Idempotent;
use Kasevvlad\Idempotency\Symfony\IdempotencySubscriber;
use Kasevvlad\Idempotency\Symfony\SymfonyStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class IdempotencySubscriberTest extends TestCase
{
    private int $calls = 0;
    private HttpKernel $kernel;

    protected function setUp(): void
    {
        $idempotency = new Idempotency(new SymfonyStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new IdempotencySubscriber($idempotency));

        $this->kernel = new HttpKernel($dispatcher, $this->routes(), new RequestStack(), new ArgumentResolver());
    }

    public function testRepeatedRequestIsReplayed(): void
    {
        $first = $this->handle('/orders');
        $second = $this->handle('/orders');

        self::assertSame(1, $this->calls);
        self::assertSame(201, $second->getStatusCode());
        self::assertSame($first->getContent(), $second->getContent());
        self::assertSame('true', $second->headers->get(Idempotency::REPLAYED_HEADER));
    }

    public function testControllerWithoutAttributeIsUntouched(): void
    {
        $this->handle('/plain');
        $this->handle('/plain');

        self::assertSame(2, $this->calls);
    }

    public function testRequiredOption(): void
    {
        self::assertSame(400, $this->handle('/payments', key: null)->getStatusCode());
        self::assertSame(0, $this->calls);
    }

    public function testUnhandledExceptionReleasesLock(): void
    {
        for ($i = 0; $i < 2; ++$i) {
            try {
                $this->handle('/failing');
                self::fail('Exception expected');
            } catch (\RuntimeException $e) {
                self::assertSame('boom', $e->getMessage());
            }
        }

        self::assertSame(2, $this->calls);
    }

    private function handle(string $path, ?string $key = 'abc'): Response
    {
        $server = $key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key];
        $request = Request::create($path, 'POST', server: $server, content: '{"amount":100}');

        $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    private function routes(): ControllerResolverInterface
    {
        $controllers = [
            '/orders' => #[Idempotent] fn () => new JsonResponse(['id' => ++$this->calls], 201),
            '/payments' => #[Idempotent(required: true)] fn () => new JsonResponse(['id' => ++$this->calls]),
            '/plain' => fn () => new JsonResponse(['id' => ++$this->calls]),
            '/failing' => #[Idempotent] function (): Response {
                ++$this->calls;

                throw new \RuntimeException('boom');
            },
        ];

        return new class($controllers) implements ControllerResolverInterface {
            /**
             * @param array<string, \Closure> $controllers
             */
            public function __construct(private readonly array $controllers)
            {
            }

            public function getController(Request $request): callable|false
            {
                return $this->controllers[$request->getPathInfo()] ?? false;
            }
        };
    }
}
