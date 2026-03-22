<?php declare(strict_types=1);

namespace Kreblu\Core\Content;

use Kreblu\Core\Database\Connection;

/**
 * Media Manager
 *
 * Handles file uploads, MIME verification (magic bytes, not extension),
 * image processing (resize, thumbnails, WebP conversion, EXIF stripping),
 * and database record management.
 *
 * Uploaded files are organized by year/month: kb-content/uploads/2026/03/
 *
 * Image sizes generated automatically:
 * - thumbnail: 150x150 (cropped square)
 * - medium: 300px wide (proportional)
 * - large: 1024px wide (proportional)
 * - WebP variant of the original (if source is JPEG/PNG)
 */
final class MediaManager
{
	/** @var array<string, string> Allowed MIME types and their extensions */
	private const ALLOWED_TYPES = [
		// Images
		'image/jpeg'    => 'jpg',
		'image/png'     => 'png',
		'image/gif'     => 'gif',
		'image/webp'    => 'webp',
		'image/svg+xml' => 'svg',

		// Documents
		'application/pdf'    => 'pdf',
		'application/msword' => 'doc',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'application/vnd.ms-excel' => 'xls',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',

		// Audio
		'audio/mpeg' => 'mp3',
		'audio/wav'  => 'wav',
		'audio/ogg'  => 'ogg',

		// Video
		'video/mp4'  => 'mp4',
		'video/webm' => 'webm',

		// Archives
		'application/zip' => 'zip',
	];

	/** @var array<string, array{width: int, height: int, crop: bool}> Image size definitions */
	private const IMAGE_SIZES = [
		'thumbnail' => ['width' => 150,  'height' => 150,  'crop' => true],
		'medium'    => ['width' => 300,  'height' => 0,    'crop' => false],
		'large'     => ['width' => 1024, 'height' => 0,    'crop' => false],
	];

	private readonly string $uploadsPath;

	public function __construct(
		private readonly Connection $db,
		string $basePath,
		private readonly int $maxFileSize = 33554432, // 32MB default
	) {
		$this->uploadsPath = rtrim($basePath, '/') . '/kb-content/uploads';
	}

	// ---------------------------------------------------------------
	// Upload
	// ---------------------------------------------------------------

	/**
	 * Process and store an uploaded file.
	 *
	 * @param array{name: string, tmp_name: string, error: int, size: int, type?: string} $file PHP upload array ($_FILES['field'])
	 * @param int $userId The uploader's user ID
	 * @return int The media record ID
	 * @throws \RuntimeException On upload or processing errors
	 * @throws \InvalidArgumentException On validation failure
	 */
	public function upload(array $file, int $userId): int
	{
		$this->validateUpload($file);

		// Verify MIME type from file content (not the client-provided type)
		$mimeType = $this->detectMimeType($file['tmp_name']);

		if (!isset(self::ALLOWED_TYPES[$mimeType])) {
			throw new \InvalidArgumentException(
				"File type '{$mimeType}' is not allowed."
			);
		}

		// Generate a safe filename
		$extension = self::ALLOWED_TYPES[$mimeType];
		$safeName = $this->generateSafeFilename($file['name'], $extension);

		// Create the upload directory (year/month structure)
		$subDir = date('Y') . '/' . date('m');
		$uploadDir = $this->uploadsPath . '/' . $subDir;

		if (!is_dir($uploadDir)) {
			if (!@mkdir($uploadDir, 0755, true)) {
				throw new \RuntimeException("Failed to create upload directory: {$uploadDir}");
			}
		}

		// Move the uploaded file
		$filePath = $uploadDir . '/' . $safeName;

		if (is_uploaded_file($file['tmp_name'])) {
			if (!move_uploaded_file($file['tmp_name'], $filePath)) {
				throw new \RuntimeException('Failed to move uploaded file.');
			}
		} else {
			// For testing or CLI uploads (not from HTTP POST)
			if (!copy($file['tmp_name'], $filePath)) {
				throw new \RuntimeException('Failed to copy file.');
			}
		}

		// Get file info
		$relativePath = $subDir . '/' . $safeName;
		$fileSize = filesize($filePath);
		$width = null;
		$height = null;
		$meta = [];

		// Process images
		if ($this->isImage($mimeType)) {
			$imageInfo = @getimagesize($filePath);

			if ($imageInfo !== false) {
				$width = $imageInfo[0];
				$height = $imageInfo[1];
			}

			// Strip EXIF data by re-saving the image
			if ($mimeType === 'image/jpeg') {
				$this->stripExif($filePath);
			}

			// Generate thumbnails and resized versions
			$sizes = $this->generateImageSizes($filePath, $mimeType);
			if (!empty($sizes)) {
				$meta['sizes'] = $sizes;
			}

			// Generate WebP variant (if source is JPEG or PNG)
			if (in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
				$webpPath = $this->generateWebP($filePath, $mimeType);
				if ($webpPath !== null) {
					$meta['webp'] = $webpPath;
				}
			}
		}

		// Create database record
		$mediaId = $this->db->table('media')->insert([
			'user_id'   => $userId,
			'filename'  => $safeName,
			'filepath'  => $relativePath,
			'mime_type' => $mimeType,
			'file_size' => $fileSize ?: 0,
			'width'     => $width,
			'height'    => $height,
			'alt_text'  => '',
			'title'     => pathinfo($file['name'], PATHINFO_FILENAME),
			'meta'      => !empty($meta) ? json_encode($meta) : null,
		]);

		return $mediaId;
	}

	// ---------------------------------------------------------------
	// Read
	// ---------------------------------------------------------------

	/**
	 * Find a media record by ID.
	 */
	public function findById(int $id): ?object
	{
		return $this->db->table('media')
			->where('id', '=', $id)
			->first();
	}

	/**
	 * Get the full filesystem path for a media file.
	 */
	public function getFullPath(string $relativePath): string
	{
		return $this->uploadsPath . '/' . $relativePath;
	}

	/**
	 * Get the URL for a media file (relative to site root).
	 */
	public function getUrl(string $relativePath): string
	{
		return 'kb-content/uploads/' . $relativePath;
	}

	/**
	 * List media files with optional filters.
	 *
	 * @param array{mime_type?: string, user_id?: int, search?: string, limit?: int, offset?: int} $args
	 * @return array<int, object>
	 */
	public function list(array $args = []): array
	{
		$query = $this->db->table('media');

		if (isset($args['mime_type'])) {
			$query->where('mime_type', 'LIKE', $args['mime_type'] . '%');
		}

		if (isset($args['user_id'])) {
			$query->where('user_id', '=', $args['user_id']);
		}

		if (isset($args['search']) && $args['search'] !== '') {
			$query->where('title', 'LIKE', '%' . $args['search'] . '%');
		}

		$query->orderBy('created_at', 'DESC');

		if (isset($args['limit'])) {
			$query->limit((int) $args['limit']);
		}

		if (isset($args['offset'])) {
			$query->offset((int) $args['offset']);
		}

		return $query->get();
	}

	/**
	 * Count media files.
	 */
	public function count(?string $mimePrefix = null): int
	{
		$query = $this->db->table('media');

		if ($mimePrefix !== null) {
			$query->where('mime_type', 'LIKE', $mimePrefix . '%');
		}

		return $query->count();
	}

	// ---------------------------------------------------------------
	// Update
	// ---------------------------------------------------------------

	/**
	 * Update media metadata (alt text, title, caption).
	 *
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): bool
	{
		$allowed = ['alt_text', 'title', 'caption'];
		$updateData = [];

		foreach ($allowed as $field) {
			if (array_key_exists($field, $data)) {
				$updateData[$field] = $data[$field];
			}
		}

		if (empty($updateData)) {
			return false;
		}

		$affected = $this->db->table('media')
			->where('id', '=', $id)
			->update($updateData);

		return $affected > 0;
	}

	// ---------------------------------------------------------------
	// Delete
	// ---------------------------------------------------------------

	/**
	 * Delete a media record and its associated files.
	 */
	public function delete(int $id): bool
	{
		$media = $this->findById($id);

		if ($media === null) {
			return false;
		}

		// Delete the physical file
		$fullPath = $this->getFullPath($media->filepath);
		if (file_exists($fullPath)) {
			@unlink($fullPath);
		}

		// Delete generated sizes
		if ($media->meta !== null) {
			$meta = json_decode($media->meta, true);

			if (isset($meta['sizes']) && is_array($meta['sizes'])) {
				foreach ($meta['sizes'] as $sizeInfo) {
					$sizePath = $this->uploadsPath . '/' . ($sizeInfo['path'] ?? '');
					if (file_exists($sizePath)) {
						@unlink($sizePath);
					}
				}
			}

			if (isset($meta['webp'])) {
				$webpPath = $this->uploadsPath . '/' . $meta['webp'];
				if (file_exists($webpPath)) {
					@unlink($webpPath);
				}
			}
		}

		// Delete database record
		$affected = $this->db->table('media')
			->where('id', '=', $id)
			->delete();

		return $affected > 0;
	}

	// ---------------------------------------------------------------
	// Image processing
	// ---------------------------------------------------------------

	/**
	 * Generate resized versions of an image.
	 *
	 * @return array<string, array{path: string, width: int, height: int}>
	 */
	private function generateImageSizes(string $filePath, string $mimeType): array
	{
		if (!$this->isProcessableImage($mimeType)) {
			return [];
		}

		$image = $this->loadImage($filePath, $mimeType);
		if ($image === null) {
			return [];
		}

		$origWidth = imagesx($image);
		$origHeight = imagesy($image);
		$sizes = [];
		$dir = dirname($filePath);
		$baseName = pathinfo($filePath, PATHINFO_FILENAME);
		$ext = pathinfo($filePath, PATHINFO_EXTENSION);
		$relativeDir = $this->getRelativeDir($filePath);

		foreach (self::IMAGE_SIZES as $sizeName => $sizeConfig) {
			$targetWidth = $sizeConfig['width'];
			$targetHeight = $sizeConfig['height'];
			$crop = $sizeConfig['crop'];

			// Skip if original is smaller than target
			if ($origWidth <= $targetWidth && ($targetHeight === 0 || $origHeight <= $targetHeight)) {
				continue;
			}

			if ($crop) {
				$resized = $this->cropResize($image, $origWidth, $origHeight, $targetWidth, $targetHeight);
			} else {
				$resized = $this->proportionalResize($image, $origWidth, $origHeight, $targetWidth);
			}

			if ($resized === null) {
				continue;
			}

			$sizeFilename = "{$baseName}-{$sizeName}.{$ext}";
			$sizePath = $dir . '/' . $sizeFilename;

			$this->saveImage($resized, $sizePath, $mimeType);

			$sizes[$sizeName] = [
				'path'   => $relativeDir . '/' . $sizeFilename,
				'width'  => imagesx($resized) ?: $targetWidth,
				'height' => imagesy($resized) ?: $targetHeight,
			];
		}

		return $sizes;
	}

	/**
	 * Generate a WebP variant of an image.
	 *
	 * @return string|null Relative path to the WebP file, or null on failure
	 */
	private function generateWebP(string $filePath, string $mimeType): ?string
	{
		if (!function_exists('imagewebp')) {
			return null;
		}

		$image = $this->loadImage($filePath, $mimeType);
		if ($image === null) {
			return null;
		}

		$webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $filePath);
		if ($webpPath === null || $webpPath === $filePath) {
			return null;
		}

		$result = imagewebp($image, $webpPath, 82);

		if (!$result || !file_exists($webpPath)) {
			return null;
		}

		return $this->getRelativeDir($filePath) . '/' . pathinfo($webpPath, PATHINFO_BASENAME);
	}

	/**
	 * Strip EXIF data from a JPEG by re-saving it.
	 */
	private function stripExif(string $filePath): void
	{
		$image = @imagecreatefromjpeg($filePath);
		if ($image === false) {
			return;
		}

		imagejpeg($image, $filePath, 92);
	}

	/**
	 * Resize an image proportionally to fit within a max width.
	 */
	private function proportionalResize(\GdImage $source, int $origW, int $origH, int $maxWidth): ?\GdImage
	{
		if ($origW <= $maxWidth) {
			return null;
		}

		$ratio = $maxWidth / $origW;
		$newWidth = $maxWidth;
		$newHeight = (int) round($origH * $ratio);

		$resized = imagecreatetruecolor($newWidth, $newHeight);
		if ($resized === false) {
			return null;
		}

		$this->preserveTransparency($source, $resized);
		imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origW, $origH);

		return $resized;
	}

	/**
	 * Crop and resize an image to exact dimensions.
	 */
	private function cropResize(\GdImage $source, int $origW, int $origH, int $targetW, int $targetH): ?\GdImage
	{
		$ratioW = $targetW / $origW;
		$ratioH = $targetH / $origH;
		$ratio = max($ratioW, $ratioH);

		$cropW = (int) round($targetW / $ratio);
		$cropH = (int) round($targetH / $ratio);
		$cropX = (int) round(($origW - $cropW) / 2);
		$cropY = (int) round(($origH - $cropH) / 2);

		$resized = imagecreatetruecolor($targetW, $targetH);
		if ($resized === false) {
			return null;
		}

		$this->preserveTransparency($source, $resized);
		imagecopyresampled($resized, $source, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);

		return $resized;
	}

	/**
	 * Preserve transparency for PNG and WebP images.
	 */
	private function preserveTransparency(\GdImage $source, \GdImage $destination): void
	{
		imagealphablending($destination, false);
		imagesavealpha($destination, true);
		$transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
		if ($transparent !== false) {
			imagefilledrectangle($destination, 0, 0, imagesx($destination) - 1, imagesy($destination) - 1, $transparent);
		}
	}

	/**
	 * Load an image file into a GD resource.
	 */
	private function loadImage(string $filePath, string $mimeType): ?\GdImage
	{
		$image = match ($mimeType) {
			'image/jpeg' => @imagecreatefromjpeg($filePath),
			'image/png'  => @imagecreatefrompng($filePath),
			'image/gif'  => @imagecreatefromgif($filePath),
			'image/webp' => @imagecreatefromwebp($filePath),
			default      => false,
		};

		return $image !== false ? $image : null;
	}

	/**
	 * Save a GD image to a file in the appropriate format.
	 */
	private function saveImage(\GdImage $image, string $path, string $mimeType): bool
	{
		return match ($mimeType) {
			'image/jpeg' => imagejpeg($image, $path, 92),
			'image/png'  => imagepng($image, $path, 6),
			'image/gif'  => imagegif($image, $path),
			'image/webp' => imagewebp($image, $path, 82),
			default      => false,
		};
	}

	// ---------------------------------------------------------------
	// Validation
	// ---------------------------------------------------------------

	/**
	 * Validate an uploaded file array.
	 *
	 * @param array<string, mixed> $file
	 */
	private function validateUpload(array $file): void
	{
		if (!isset($file['tmp_name']) || !isset($file['error']) || !isset($file['name']) || !isset($file['size'])) {
			throw new \InvalidArgumentException('Invalid upload file data.');
		}

		if ($file['error'] !== UPLOAD_ERR_OK) {
			$errorMessages = [
				UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
				UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form upload size limit.',
				UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
				UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
				UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
				UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk.',
				UPLOAD_ERR_EXTENSION  => 'A server extension stopped the upload.',
			];

			$message = $errorMessages[$file['error']] ?? 'Unknown upload error.';
			throw new \RuntimeException($message);
		}

		if ($file['size'] > $this->maxFileSize) {
			$maxMB = round($this->maxFileSize / 1048576, 1);
			throw new \InvalidArgumentException("File size exceeds the {$maxMB}MB limit.");
		}

		if (!file_exists($file['tmp_name'])) {
			throw new \RuntimeException('Uploaded file not found on server.');
		}
	}

	/**
	 * Detect MIME type from file content using magic bytes.
	 * Does NOT trust the client-provided Content-Type.
	 */
	private function detectMimeType(string $filePath): string
	{
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mimeType = $finfo->file($filePath);

		if ($mimeType === false) {
			throw new \RuntimeException('Could not determine file type.');
		}

		return $mimeType;
	}

	/**
	 * Check if a MIME type is an image type.
	 */
	private function isImage(string $mimeType): bool
	{
		return str_starts_with($mimeType, 'image/');
	}

	/**
	 * Check if an image type can be processed by GD.
	 */
	private function isProcessableImage(string $mimeType): bool
	{
		return in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
	}

	/**
	 * Generate a safe, unique filename.
	 */
	private function generateSafeFilename(string $originalName, string $extension): string
	{
		$baseName = pathinfo($originalName, PATHINFO_FILENAME);

		// Sanitize: keep only alphanumeric, hyphens, underscores
		$baseName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $baseName) ?? 'file';
		$baseName = trim(preg_replace('/-+/', '-', $baseName) ?? 'file', '-');

		if ($baseName === '') {
			$baseName = 'file';
		}

		// Add a short unique suffix to prevent collisions
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);

		return strtolower($baseName) . '-' . $suffix . '.' . $extension;
	}

	/**
	 * Get the relative directory path from a full file path.
	 */
	private function getRelativeDir(string $fullPath): string
	{
		$dir = dirname($fullPath);
		$relative = str_replace($this->uploadsPath . '/', '', $dir);
		return $relative;
	}
}
