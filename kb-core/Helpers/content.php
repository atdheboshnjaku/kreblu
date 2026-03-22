<?php declare(strict_types=1);

/**
 * Content Output Helpers
 *
 * Processes post body HTML before frontend rendering.
 * Sanitizes dangerous tags, escapes code blocks, adds SEO attributes.
 */

if (!function_exists('kb_prepare_content')) {
	/**
	 * Prepare post body HTML for safe frontend rendering.
	 */
	function kb_prepare_content(string $html): string
	{
		if ($html === '') {
			return '';
		}

		// Step 1: Extract ALL code blocks using position-based parsing
		// This must happen BEFORE sanitization so <script> inside code is preserved
		$blocks = [];
		$html = kb_extract_code_blocks($html, $blocks);

		// Step 2: Strip dangerous tags from the remaining (non-code) HTML
		$dangerousTags = ['script', 'iframe', 'object', 'embed', 'applet', 'form', 'style', 'link', 'meta', 'base'];
		foreach ($dangerousTags as $tag) {
			$html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', '', $html);
			$html = preg_replace('#<' . $tag . '\b[^>]*/?>#is', '', $html);
		}

		// Step 3: Strip dangerous attributes
		$html = preg_replace('#\s+on\w+\s*=\s*["\'][^"\']*["\']#i', '', $html);
		$html = preg_replace('#\s+on\w+\s*=\s*\S+#i', '', $html);
		$html = preg_replace('#(href|src|action)\s*=\s*["\']?\s*javascript\s*:[^"\'>\s]*["\']?#i', '$1=""', $html);
		$html = preg_replace('#(href|src|action)\s*=\s*["\']?\s*data\s*:[^"\'>\s]*["\']?#i', '$1=""', $html);
		$html = preg_replace('#\s+srcdoc\s*=\s*["\'][^"\']*["\']#i', '', $html);

		// Step 4: Add rel to external links with target="_blank"
		$html = preg_replace_callback(
			'#<a\s([^>]*?)>#i',
			function (array $m) {
				$attrs = $m[1];
				if (str_contains($attrs, 'target="_blank"') && !str_contains($attrs, 'rel=')) {
					$attrs .= ' rel="noopener noreferrer nofollow"';
				}
				return '<a ' . $attrs . '>';
			},
			$html,
		);

		// Step 5: Add lazy loading to images
		$html = preg_replace_callback(
			'#<img\s([^>]*?)>#i',
			function (array $m) {
				$attrs = $m[1];
				if (!str_contains($attrs, 'loading=')) {
					$attrs .= ' loading="lazy"';
				}
				return '<img ' . $attrs . '>';
			},
			$html,
		);

		// Step 6: Restore code blocks (already escaped)
		foreach ($blocks as $placeholder => $escaped) {
			$html = str_replace($placeholder, $escaped, $html);
		}

		return $html;
	}
}

if (!function_exists('kb_extract_code_blocks')) {
	/**
	 * Extract <pre><code>...</code></pre> and <code>...</code> blocks
	 * using position-based string scanning (not regex).
	 *
	 * This avoids regex issues with nested HTML tags inside code blocks.
	 * Returns the HTML with placeholders, and populates $blocks with
	 * placeholder => escaped HTML mappings.
	 *
	 * @param array<string, string> $blocks Populated by reference
	 */
	function kb_extract_code_blocks(string $html, array &$blocks): string
	{
		$result = '';
		$pos = 0;
		$len = strlen($html);

		while ($pos < $len) {
			// Look for <pre><code> first (it's more specific)
			$preCodePos = stripos($html, '<pre><code>', $pos);
			$codePos = stripos($html, '<code>', $pos);

			// No more code tags — append rest and break
			if ($preCodePos === false && $codePos === false) {
				$result .= substr($html, $pos);
				break;
			}

			// Determine which comes first
			$isPreCode = false;
			$matchPos = PHP_INT_MAX;

			if ($preCodePos !== false) {
				$matchPos = $preCodePos;
				$isPreCode = true;
			}
			if ($codePos !== false && $codePos < $matchPos) {
				$matchPos = $codePos;
				$isPreCode = ($preCodePos !== false && $preCodePos === $codePos);
			}

			// Append everything before this match
			$result .= substr($html, $pos, $matchPos - $pos);

			if ($isPreCode) {
				// Extract <pre><code>...</code></pre>
				$openTag = '<pre><code>';
				$closeTag = '</code></pre>';
				$contentStart = $matchPos + strlen($openTag);
				$closePos = stripos($html, $closeTag, $contentStart);

				if ($closePos === false) {
					// Unclosed — escape everything from here to end
					$content = substr($html, $contentStart);
					$placeholder = '%%PREBLOCK_' . count($blocks) . '%%';
					$blocks[$placeholder] = '<pre><code>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</code></pre>';
					$result .= $placeholder;
					break;
				}

				$content = substr($html, $contentStart, $closePos - $contentStart);
				$placeholder = '%%PREBLOCK_' . count($blocks) . '%%';
				$blocks[$placeholder] = '<pre><code>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</code></pre>';
				$result .= $placeholder;
				$pos = $closePos + strlen($closeTag);
			} else {
				// Extract <code>...</code>
				$openTag = '<code>';
				$closeTag = '</code>';
				$contentStart = $matchPos + strlen($openTag);
				$closePos = stripos($html, $closeTag, $contentStart);

				if ($closePos === false) {
					$content = substr($html, $contentStart);
					$placeholder = '%%CODEINLINE_' . count($blocks) . '%%';
					$blocks[$placeholder] = '<code>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</code>';
					$result .= $placeholder;
					break;
				}

				$content = substr($html, $contentStart, $closePos - $contentStart);
				$placeholder = '%%CODEINLINE_' . count($blocks) . '%%';
				$blocks[$placeholder] = '<code>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</code>';
				$result .= $placeholder;
				$pos = $closePos + strlen($closeTag);
			}
		}

		return $result;
	}
}
