<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit;

use Kreblu\Core\App;
use Kreblu\Tests\TestCase;

/**
 * Tests for the App service container.
 */
final class AppTest extends TestCase
{
    public function test_singleton_returns_same_instance(): void
    {
        $app1 = App::getInstance();
        $app2 = App::getInstance();

        $this->assertSame($app1, $app2);
    }

    public function test_reset_creates_new_instance(): void
    {
        $app1 = App::getInstance();
        App::resetInstance();
        $app2 = App::getInstance();

        $this->assertNotSame($app1, $app2);
    }

    public function test_register_and_get_service(): void
    {
        $app = App::getInstance();
        $app->register('test_service', function () {
            return new \stdClass();
        });

        $service = $app->get('test_service');
        $this->assertInstanceOf(\stdClass::class, $service);
    }

    public function test_service_is_singleton(): void
    {
        $app = App::getInstance();
        $callCount = 0;

        $app->register('counter', function () use (&$callCount) {
            $callCount++;
            return new \stdClass();
        });

        $first = $app->get('counter');
        $second = $app->get('counter');

        $this->assertSame($first, $second);
        $this->assertEquals(1, $callCount, 'Factory should only be called once');
    }

    public function test_has_returns_true_for_registered_service(): void
    {
        $app = App::getInstance();
        $app->register('exists', fn () => true);

        $this->assertTrue($app->has('exists'));
    }

    public function test_has_returns_false_for_missing_service(): void
    {
        $app = App::getInstance();

        $this->assertFalse($app->has('nonexistent'));
    }

    public function test_get_throws_for_missing_service(): void
    {
        $app = App::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Service "missing" is not registered');

        $app->get('missing');
    }

    public function test_re_register_clears_resolved_instance(): void
    {
        $app = App::getInstance();

        $app->register('mutable', fn () => 'first');
        $this->assertEquals('first', $app->get('mutable'));

        $app->register('mutable', fn () => 'second');
        $this->assertEquals('second', $app->get('mutable'));
    }

    public function test_factory_receives_app_instance(): void
    {
        $app = App::getInstance();
        $receivedApp = null;

        $app->register('checker', function (App $a) use (&$receivedApp) {
            $receivedApp = $a;
            return true;
        });

        $app->get('checker');
        $this->assertSame($app, $receivedApp);
    }

    public function test_is_not_booted_initially(): void
    {
        $app = App::getInstance();
        $this->assertFalse($app->isBooted());
    }
}
