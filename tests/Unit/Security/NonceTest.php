<?php declare(strict_types=1);

namespace Kreblu\Tests\Unit\Security;

use Kreblu\Core\Security\Nonce;
use Kreblu\Tests\TestCase;

final class NonceTest extends TestCase
{
	private Nonce $nonce;

	protected function setUp(): void
	{
		parent::setUp();
		$this->nonce = new Nonce(secretKey: 'test-secret-key-for-unit-tests-only');
	}

	public function test_create_returns_hex_string(): void
	{
		$token = $this->nonce->create('save_post');

		$this->assertNotEmpty($token);
		$this->assertEquals(64, strlen($token));
		$this->assertTrue(ctype_xdigit($token));
	}

	public function test_verify_valid_token(): void
	{
		$token = $this->nonce->create('save_post');
		$this->assertTrue($this->nonce->verify($token, 'save_post'));
	}

	public function test_verify_fails_for_wrong_action(): void
	{
		$token = $this->nonce->create('save_post');
		$this->assertFalse($this->nonce->verify($token, 'delete_post'));
	}

	public function test_verify_fails_for_empty_token(): void
	{
		$this->assertFalse($this->nonce->verify('', 'save_post'));
	}

	public function test_verify_fails_for_garbage_token(): void
	{
		$this->assertFalse($this->nonce->verify('not-a-real-token', 'save_post'));
	}

	public function test_same_action_produces_same_token(): void
	{
		$token1 = $this->nonce->create('save_post');
		$token2 = $this->nonce->create('save_post');
		$this->assertEquals($token1, $token2);
	}

	public function test_different_actions_produce_different_tokens(): void
	{
		$token1 = $this->nonce->create('save_post');
		$token2 = $this->nonce->create('delete_post');
		$this->assertNotEquals($token1, $token2);
	}

	public function test_different_keys_produce_different_tokens(): void
	{
		$nonce1 = new Nonce(secretKey: 'key-one');
		$nonce2 = new Nonce(secretKey: 'key-two');

		$this->assertNotEquals(
			$nonce1->create('save_post'),
			$nonce2->create('save_post')
		);
	}

	public function test_token_from_different_key_fails_verification(): void
	{
		$nonce1 = new Nonce(secretKey: 'key-one');
		$nonce2 = new Nonce(secretKey: 'key-two');

		$token = $nonce1->create('save_post');
		$this->assertFalse($nonce2->verify($token, 'save_post'));
	}

	public function test_field_generates_html_input(): void
	{
		$html = $this->nonce->field('save_post');

		$this->assertStringContainsString('<input type="hidden"', $html);
		$this->assertStringContainsString('name="_kb_nonce"', $html);
		$this->assertStringContainsString('value="', $html);
	}

	public function test_field_custom_name(): void
	{
		$html = $this->nonce->field('save_post', 'my_nonce');
		$this->assertStringContainsString('name="my_nonce"', $html);
	}

	public function test_field_token_is_verifiable(): void
	{
		$html = $this->nonce->field('save_post');
		preg_match('/value="([^"]+)"/', $html, $matches);
		$token = $matches[1];

		$this->assertTrue($this->nonce->verify($token, 'save_post'));
	}

	public function test_throws_on_empty_key(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Security keys are not configured');
		new Nonce(secretKey: '');
	}

	public function test_throws_on_placeholder_key(): void
	{
		$this->expectException(\RuntimeException::class);
		new Nonce(secretKey: 'put-your-unique-phrase-here');
	}
}
