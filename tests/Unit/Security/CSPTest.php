<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Security;

use Kreblu\Core\Security\CSP;
use Kreblu\Tests\TestCase;

final class CSPTest extends TestCase
{
	public function test_defaults_include_self(): void
	{
		$csp = CSP::defaults();

		$this->assertTrue($csp->hasDirective('default-src'));
		$this->assertContains("'self'", $csp->getSources('default-src'));
	}

	public function test_defaults_block_object_src(): void
	{
		$csp = CSP::defaults();

		$this->assertContains("'none'", $csp->getSources('object-src'));
	}

	public function test_build_produces_valid_string(): void
	{
		$csp = CSP::defaults();
		$header = $csp->build();

		$this->assertStringContainsString("default-src 'self'", $header);
		$this->assertStringContainsString("object-src 'none'", $header);
		$this->assertStringContainsString('; ', $header);
	}

	public function test_add_directive_new(): void
	{
		$csp = new CSP();
		$csp->addDirective('script-src', "'self'");
		$csp->addDirective('script-src', 'https://cdn.example.com');

		$sources = $csp->getSources('script-src');
		$this->assertContains("'self'", $sources);
		$this->assertContains('https://cdn.example.com', $sources);
	}

	public function test_add_directive_no_duplicates(): void
	{
		$csp = new CSP();
		$csp->addDirective('script-src', "'self'");
		$csp->addDirective('script-src', "'self'");

		$this->assertCount(1, $csp->getSources('script-src'));
	}

	public function test_remove_source(): void
	{
		$csp = CSP::defaults();
		$csp->removeSource('img-src', 'data:');

		$this->assertNotContains('data:', $csp->getSources('img-src'));
	}

	public function test_remove_source_cleans_up_empty_directive(): void
	{
		$csp = new CSP();
		$csp->addDirective('test-src', 'only-one');
		$csp->removeSource('test-src', 'only-one');

		$this->assertFalse($csp->hasDirective('test-src'));
	}

	public function test_set_directive_replaces_all(): void
	{
		$csp = CSP::defaults();
		$csp->setDirective('script-src', ['https://only-this.com']);

		$sources = $csp->getSources('script-src');
		$this->assertCount(1, $sources);
		$this->assertEquals('https://only-this.com', $sources[0]);
	}

	public function test_remove_directive(): void
	{
		$csp = CSP::defaults();
		$csp->removeDirective('frame-src');

		$this->assertFalse($csp->hasDirective('frame-src'));
	}

	public function test_has_directive(): void
	{
		$csp = CSP::defaults();

		$this->assertTrue($csp->hasDirective('default-src'));
		$this->assertFalse($csp->hasDirective('nonexistent-src'));
	}

	public function test_to_header(): void
	{
		$csp = new CSP();
		$csp->addDirective('default-src', "'self'");

		$header = $csp->toHeader();
		$this->assertStringStartsWith('Content-Security-Policy: ', $header);
		$this->assertStringContainsString("default-src 'self'", $header);
	}

	public function test_empty_csp_builds_empty_string(): void
	{
		$csp = new CSP();
		$this->assertEquals('', $csp->build());
	}

	public function test_add_is_chainable(): void
	{
		$csp = new CSP();
		$result = $csp->addDirective('script-src', "'self'")
					->addDirective('img-src', "'self'");

		$this->assertInstanceOf(CSP::class, $result);
		$this->assertTrue($csp->hasDirective('script-src'));
		$this->assertTrue($csp->hasDirective('img-src'));
	}

	public function test_kap_extending_csp(): void
	{
		// Simulates a kap (augment) adding its CDN to the CSP
		$csp = CSP::defaults();
		$csp->addDirective('script-src', 'https://maps.googleapis.com');
		$csp->addDirective('img-src', 'https://maps.gstatic.com');

		$header = $csp->build();
		$this->assertStringContainsString('https://maps.googleapis.com', $header);
		$this->assertStringContainsString('https://maps.gstatic.com', $header);

		// Defaults still present
		$this->assertStringContainsString("script-src 'self'", $header);
	}
}
