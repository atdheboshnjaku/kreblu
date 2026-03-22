<?php declare(strict_types=1);

namespace Kreblu\Tests\Integration;

use Kreblu\Core\Auth\AuthManager;
use Kreblu\Core\Content\MediaManager;
use Kreblu\Core\Database\Connection;
use Kreblu\Core\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the Media Manager.
 *
 * Creates test images using GD to avoid shipping binary fixtures.
 */
final class MediaIntegrationTest extends TestCase
{
	private static ?Connection $db = null;
	private static ?MediaManager $media = null;
	private static int $testUserId = 0;
	private static string $tempDir = '';
	private static string $basePath = '';

	public static function setUpBeforeClass(): void
	{
		$host   = $_ENV['KB_DB_HOST'] ?? 'db';
		$port   = (int) ($_ENV['KB_DB_PORT'] ?? 3306);
		$name   = $_ENV['KB_DB_NAME'] ?? 'kreblu_test';
		$user   = $_ENV['KB_DB_USER'] ?? 'kreblu';
		$pass   = $_ENV['KB_DB_PASS'] ?? 'kreblu_dev';
		$prefix = $_ENV['KB_DB_PREFIX'] ?? 'kb_test_';

		try {
			self::$db = new Connection(
				host: $host, port: $port, name: $name,
				user: $user, pass: $pass, prefix: $prefix,
			);

			$migrationsPath = dirname(__DIR__, 2) . '/kb-core/Database/migrations';
			$schema = new Schema(self::$db, $migrationsPath);
			$schema->ensureMigrationsTable();
			if (!empty($schema->getPendingMigrations())) {
				$schema->runPending();
			}

			// Create temp directory structure for uploads
			self::$tempDir = sys_get_temp_dir() . '/kreblu_media_test_' . uniqid();
			self::$basePath = self::$tempDir;
			$uploadsDir = self::$basePath . '/kb-content/uploads';
			mkdir($uploadsDir, 0755, true);

			self::$media = new MediaManager(self::$db, self::$basePath);

			// Create a test user
			$auth = new AuthManager(self::$db, bcryptCost: 4);
			self::$testUserId = self::$db->table('users')->insert([
				'email'         => 'media_test@example.com',
				'username'      => 'media_tester',
				'password_hash' => $auth->hashPassword('TestPass123'),
				'display_name'  => 'Media Tester',
				'role'          => 'admin',
			]);
		} catch (\PDOException $e) {
			self::markTestSkipped('Database not available: ' . $e->getMessage());
		}
	}

	protected function setUp(): void
	{
		if (self::$db === null) {
			$this->markTestSkipped('Database not available.');
		}

		// Clear media records before each test
		self::$db->execute('DELETE FROM ' . self::$db->tableName('media'));
	}

	public static function tearDownAfterClass(): void
	{
		// Clean up temp directory
		if (self::$tempDir !== '' && is_dir(self::$tempDir)) {
			self::recursiveDelete(self::$tempDir);
		}

		// Clean up test user
		if (self::$db !== null && self::$db->isConnected()) {
			self::$db->execute('SET FOREIGN_KEY_CHECKS = 0');
			self::$db->execute('DELETE FROM ' . self::$db->tableName('users') . ' WHERE id = ?', [self::$testUserId]);
			self::$db->execute('SET FOREIGN_KEY_CHECKS = 1');
		}
	}

	private function media(): MediaManager { return self::$media; }
	private function db(): Connection { return self::$db; }
	private function userId(): int { return self::$testUserId; }

	/**
	 * Create a test JPEG image using GD.
	 *
	 * @return string Path to the created file
	 */
	private function createTestJpeg(int $width = 800, int $height = 600, string $filename = 'test.jpg'): string
	{
		$image = imagecreatetruecolor($width, $height);
		$color = imagecolorallocate($image, 100, 150, 200);
		imagefill($image, 0, 0, $color);

		$path = sys_get_temp_dir() . '/' . $filename;
		imagejpeg($image, $path, 90);

		return $path;
	}

	/**
	 * Create a test PNG image using GD.
	 */
	private function createTestPng(int $width = 400, int $height = 400, string $filename = 'test.png'): string
	{
		$image = imagecreatetruecolor($width, $height);
		imagesavealpha($image, true);
		$transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
		imagefill($image, 0, 0, $transparent);

		$color = imagecolorallocate($image, 255, 0, 0);
		imagefilledellipse($image, $width / 2, $height / 2, $width / 2, $height / 2, $color);

		$path = sys_get_temp_dir() . '/' . $filename;
		imagepng($image, $path);

		return $path;
	}

	/**
	 * Create a test text file (non-image).
	 */
	private function createTestTextFile(string $filename = 'test.txt'): string
	{
		$path = sys_get_temp_dir() . '/' . $filename;
		file_put_contents($path, 'This is a text file, not an image.');
		return $path;
	}

	/**
	 * Build a simulated upload array (like $_FILES).
	 *
	 * @return array{name: string, tmp_name: string, error: int, size: int}
	 */
	private function fakeUpload(string $filePath, string $originalName): array
	{
		return [
			'name'     => $originalName,
			'tmp_name' => $filePath,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize($filePath),
		];
	}

	private static function recursiveDelete(string $dir): void
	{
		$items = scandir($dir);
		if ($items === false) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			if (is_dir($path)) {
				self::recursiveDelete($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}

	// ---------------------------------------------------------------
	// Upload tests
	// ---------------------------------------------------------------

	public function test_upload_jpeg(): void
	{
		$filePath = $this->createTestJpeg();
		$upload = $this->fakeUpload($filePath, 'my-photo.jpg');

		$id = $this->media()->upload($upload, $this->userId());

		$this->assertGreaterThan(0, $id);

		$record = $this->media()->findById($id);
		$this->assertNotNull($record);
		$this->assertEquals('image/jpeg', $record->mime_type);
		$this->assertEquals(800, $record->width);
		$this->assertEquals(600, $record->height);
		$this->assertEquals('my-photo', $record->title);
	}

	public function test_upload_png(): void
	{
		$filePath = $this->createTestPng();
		$upload = $this->fakeUpload($filePath, 'logo.png');

		$id = $this->media()->upload($upload, $this->userId());

		$record = $this->media()->findById($id);
		$this->assertNotNull($record);
		$this->assertEquals('image/png', $record->mime_type);
	}

	public function test_upload_rejects_invalid_type(): void
	{
		$filePath = $this->createTestTextFile();
		$upload = $this->fakeUpload($filePath, 'document.txt');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not allowed');

		$this->media()->upload($upload, $this->userId());
	}

	public function test_upload_generates_unique_filename(): void
	{
		$file1 = $this->createTestJpeg(100, 100, 'upload1.jpg');
		$file2 = $this->createTestJpeg(100, 100, 'upload2.jpg');

		$id1 = $this->media()->upload($this->fakeUpload($file1, 'same-name.jpg'), $this->userId());
		$id2 = $this->media()->upload($this->fakeUpload($file2, 'same-name.jpg'), $this->userId());

		$record1 = $this->media()->findById($id1);
		$record2 = $this->media()->findById($id2);

		// Filenames should be different due to random suffix
		$this->assertNotEquals($record1->filename, $record2->filename);
	}

	public function test_upload_creates_file_on_disk(): void
	{
		$filePath = $this->createTestJpeg(200, 200);
		$upload = $this->fakeUpload($filePath, 'disk-test.jpg');

		$id = $this->media()->upload($upload, $this->userId());
		$record = $this->media()->findById($id);

		$fullPath = $this->media()->getFullPath($record->filepath);
		$this->assertFileExists($fullPath);
	}

	public function test_upload_organizes_by_year_month(): void
	{
		$filePath = $this->createTestJpeg(100, 100);
		$upload = $this->fakeUpload($filePath, 'dated.jpg');

		$id = $this->media()->upload($upload, $this->userId());
		$record = $this->media()->findById($id);

		$expectedPrefix = date('Y') . '/' . date('m') . '/';
		$this->assertStringStartsWith($expectedPrefix, $record->filepath);
	}

	// ---------------------------------------------------------------
	// Image processing tests
	// ---------------------------------------------------------------

	public function test_jpeg_generates_webp(): void
	{
		$filePath = $this->createTestJpeg(800, 600);
		$upload = $this->fakeUpload($filePath, 'webp-test.jpg');

		$id = $this->media()->upload($upload, $this->userId());
		$record = $this->media()->findById($id);

		$meta = json_decode($record->meta, true);
		$this->assertArrayHasKey('webp', $meta);
		$this->assertStringEndsWith('.webp', $meta['webp']);

		// WebP file should exist on disk
		$webpPath = self::$basePath . '/kb-content/uploads/' . $meta['webp'];
		$this->assertFileExists($webpPath);
	}

	public function test_large_image_generates_sizes(): void
	{
		$filePath = $this->createTestJpeg(2000, 1500);
		$upload = $this->fakeUpload($filePath, 'big-image.jpg');

		$id = $this->media()->upload($upload, $this->userId());
		$record = $this->media()->findById($id);

		$meta = json_decode($record->meta, true);
		$this->assertArrayHasKey('sizes', $meta);
		$this->assertArrayHasKey('thumbnail', $meta['sizes']);
		$this->assertArrayHasKey('medium', $meta['sizes']);
		$this->assertArrayHasKey('large', $meta['sizes']);
	}

	public function test_small_image_skips_unnecessary_sizes(): void
	{
		// 100x100 is smaller than all size targets except thumbnail (150x150 crop)
		$filePath = $this->createTestJpeg(100, 100);
		$upload = $this->fakeUpload($filePath, 'tiny.jpg');

		$id = $this->media()->upload($upload, $this->userId());
		$record = $this->media()->findById($id);

		$meta = $record->meta !== null ? json_decode($record->meta, true) : [];
		$sizes = $meta['sizes'] ?? [];

		// Should not generate medium or large since original is smaller
		$this->assertArrayNotHasKey('large', $sizes);
		$this->assertArrayNotHasKey('medium', $sizes);
	}

	// ---------------------------------------------------------------
	// CRUD tests
	// ---------------------------------------------------------------

	public function test_update_metadata(): void
	{
		$filePath = $this->createTestJpeg(200, 200);
		$id = $this->media()->upload($this->fakeUpload($filePath, 'meta-update.jpg'), $this->userId());

		$this->media()->update($id, [
			'alt_text' => 'A beautiful sunset',
			'title'    => 'Sunset Photo',
			'caption'  => 'Taken at the beach.',
		]);

		$record = $this->media()->findById($id);
		$this->assertEquals('A beautiful sunset', $record->alt_text);
		$this->assertEquals('Sunset Photo', $record->title);
		$this->assertEquals('Taken at the beach.', $record->caption);
	}

	public function test_list_media(): void
	{
		$jpg = $this->createTestJpeg(100, 100, 'list1.jpg');
		$png = $this->createTestPng(100, 100, 'list2.png');

		$this->media()->upload($this->fakeUpload($jpg, 'photo.jpg'), $this->userId());
		$this->media()->upload($this->fakeUpload($png, 'logo.png'), $this->userId());

		$all = $this->media()->list();
		$this->assertCount(2, $all);

		$jpegOnly = $this->media()->list(['mime_type' => 'image/jpeg']);
		$this->assertCount(1, $jpegOnly);
	}

	public function test_count_media(): void
	{
		$jpg = $this->createTestJpeg(100, 100, 'count1.jpg');
		$png = $this->createTestPng(100, 100, 'count2.png');

		$this->media()->upload($this->fakeUpload($jpg, 'a.jpg'), $this->userId());
		$this->media()->upload($this->fakeUpload($png, 'b.png'), $this->userId());

		$this->assertEquals(2, $this->media()->count());
		$this->assertEquals(1, $this->media()->count('image/jpeg'));
	}

	public function test_delete_removes_record_and_files(): void
	{
		$filePath = $this->createTestJpeg(800, 600);
		$id = $this->media()->upload($this->fakeUpload($filePath, 'deletable.jpg'), $this->userId());

		$record = $this->media()->findById($id);
		$fullPath = $this->media()->getFullPath($record->filepath);
		$this->assertFileExists($fullPath);

		$this->media()->delete($id);

		$this->assertNull($this->media()->findById($id));
		$this->assertFileDoesNotExist($fullPath);
	}

	// ---------------------------------------------------------------
	// Validation tests
	// ---------------------------------------------------------------

	public function test_upload_error_throws(): void
	{
		$this->expectException(\RuntimeException::class);

		$this->media()->upload([
			'name'     => 'test.jpg',
			'tmp_name' => '/nonexistent',
			'error'    => UPLOAD_ERR_NO_FILE,
			'size'     => 0,
		], $this->userId());
	}

	public function test_missing_fields_throws(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->media()->upload(['incomplete' => true], $this->userId());
	}

	public function test_get_url(): void
	{
		$url = $this->media()->getUrl('2026/03/photo.jpg');
		$this->assertEquals('kb-content/uploads/2026/03/photo.jpg', $url);
	}
}
