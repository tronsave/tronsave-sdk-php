<?php

declare(strict_types=1);

namespace Tronsave\Tests;

use PHPUnit\Framework\TestCase;
use Tronsave\TronsaveClient;
use Tronsave\TronsaveException;

/**
 * Runs the shared, language-agnostic conformance fixtures (conformance/fixtures/*.json) against the
 * shared mock server (conformance/server.mjs). Requires Node.js on PATH.
 */
final class ConformanceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../conformance';

    /** @var resource|null */
    private static $proc = null;
    private static int $port = 0;
    /** @var array<int, resource> */
    private static array $pipes = [];

    public static function setUpBeforeClass(): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(['node', self::ROOT . '/server.mjs', '--port', '0'], $descriptors, $pipes);
        if (!is_resource($proc)) {
            self::markTestSkipped('Could not start the conformance mock server (is Node.js installed?).');
        }
        self::$proc = $proc;
        self::$pipes = $pipes;
        $line = fgets($pipes[1]) ?: '';
        if (!preg_match('/LISTENING (\d+)/', $line, $m)) {
            self::markTestSkipped('Mock server did not report a port.');
        }
        self::$port = (int) $m[1];
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function fixtureProvider(): iterable
    {
        foreach (glob(self::ROOT . '/fixtures/*.json') ?: [] as $file) {
            $cases = json_decode((string) file_get_contents($file), true);
            foreach ($cases as $case) {
                yield $case['case'] => [$case];
            }
        }
    }

    /**
     * @dataProvider fixtureProvider
     * @param array<string, mixed> $fixture
     */
    public function testConformance(array $fixture): void
    {
        $sdk = new TronsaveClient(
            apiKey: 'conformance-key',
            baseUrl: sprintf('http://127.0.0.1:%d/case/%s', self::$port, $fixture['case']),
            maxRetries: 0,
        );

        $args = $fixture['sdkCall']['args'];
        $invoke = match ($fixture['sdkCall']['method']) {
            'getUserInfo' => static fn () => $sdk->getUserInfo(),
            'estimateBuyResource' => static fn () => $sdk->estimateBuyResource($args[0]),
            'buyResource' => static fn () => $sdk->buyResource($args[0], $args[1] ?? []),
            'getOrder' => static fn () => $sdk->getOrder($args[0]),
            'getOrders' => static fn () => $sdk->getOrders($args[0]['page'] ?? 0, $args[0]['pageSize'] ?? 10),
            'getOrderBook' => static fn () => $sdk->getOrderBook($args[0] ?? []),
            'getExtendableDelegates' => static fn () => $sdk->getExtendableDelegates($args[0]),
            'extendRequest' => static fn () => $sdk->extendRequest($args[0]),
            default => $this->fail("Unmapped method {$fixture['sdkCall']['method']}"),
        };

        $expect = $fixture['expect'];
        if ($expect['kind'] === 'data') {
            $result = $invoke();
            $serialized = json_decode(json_encode($result), true);
            self::assertEquals($expect['value'], $serialized, $fixture['case']);
            return;
        }

        try {
            $invoke();
            self::fail("Expected {$fixture['case']} to throw");
        } catch (TronsaveException $e) {
            $wantClass = 'Tronsave\\' . str_replace('Error', 'Exception', $expect['errorClass']);
            self::assertInstanceOf($wantClass, $e, $fixture['case']);
            self::assertSame($expect['code'], $e->code, $fixture['case']);
            if (isset($expect['status'])) {
                self::assertSame($expect['status'], $e->status, $fixture['case']);
            }
        }
    }
}
