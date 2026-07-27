<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

/**
 * Loads knowledge/error_codes.json and decodes Litter-Robot 4 conditions.
 *
 * The catalog is keyed twice over:
 *   * by integer, for the coarse signal the bridge DTO carries (`error`: 0 = no
 *     fault, 1 = a fault the bridge could not name), and
 *   * by LR4 status code (`BR`, `CSF`, `OTF`, `DFS`, ...), which is where the
 *     real condition lives.
 *
 * The bridge collapses every fault to `error = 1` and keeps the condition in
 * `status` / `status_label`, so callers should pass the status string alongside
 * the int: a status-code hit always wins over the generic int entry.
 */
class ErrorDecoderService
{
	/**
	 * Normalized bridge status vocabulary -> the LR4 catalog code that describes
	 * it. The bridge DTO reports the *normalized* status (`cleaning`, not `CCP`),
	 * so this table lets the same catalog serve both spellings.
	 *
	 * @var array<string, string>
	 */
	public const STATUS_CODE_ALIASES = [
		'cleaning' => 'CCP',
		'emptying' => 'EC',
		'paused' => 'P',
		'drawer_full' => 'DFS',
		'sleeping' => 'SLEEP',
		'offline' => 'OFFLINE',
	];

	private ?array $catalog = null;

	public function __construct(
		private ?string $catalogPath = null,
	) {
		$this->catalogPath ??= dirname(__DIR__, 2) . '/knowledge/error_codes.json';
	}

	/**
	 * @param int $error coarse fault flag from the bridge DTO (0 = clear)
	 * @param int $notReady legacy numeric "busy" register (LR4 has none; pass 0)
	 * @param string|null $status LR4 status code or normalized bridge status
	 * @return array{code:int|string,kind:string,title:string,detail:string,action:string,status_code:?string}
	 */
	public function decode(int $error, int $notReady = 0, ?string $status = null): array
	{
		$catalog = $this->load();
		$code = $this->catalogKey($status);

		if ($error !== 0) {
			// A named status code beats the generic "error 1" entry.
			if ($code !== null && is_array($catalog['errors'][$code] ?? null)) {
				return $this->entry('error', $code, $catalog['errors'][$code], $code);
			}
			if (is_array($catalog['errors'][(string) $error] ?? null)) {
				return $this->entry('error', $error, $catalog['errors'][(string) $error], $code);
			}
			return [
				'code' => $error,
				'kind' => 'error',
				'title' => 'Unknown fault ' . $error,
				'detail' => 'No catalog entry for this condition.',
				'action' => "Check Alfred's status ring and the Whisker app, then consult docs/OPERATOR.md.",
				'status_code' => $code,
			];
		}

		// Error flag clear, but the status code may still name something worth
		// saying: a transient busy state, or a blocking condition the LR4 does not
		// count as a mechanical fault (a full drawer, an offline unit).
		if ($code !== null && is_array($catalog['not_ready'][$code] ?? null)) {
			return $this->entry('not_ready', $code, $catalog['not_ready'][$code], $code);
		}
		if ($code !== null && is_array($catalog['errors'][$code] ?? null)) {
			return $this->entry('error', $code, $catalog['errors'][$code], $code);
		}
		if ($notReady !== 0) {
			$entry = $catalog['not_ready'][(string) $notReady] ?? null;
			if (is_array($entry)) {
				return $this->entry('not_ready', $notReady, $entry, $code);
			}
			// Unknown busy codes are almost always benign, transient states that
			// clear on their own, so frame it reassuringly rather than as a defect.
			return [
				'code' => $notReady,
				'kind' => 'not_ready',
				'title' => 'Just a moment',
				'detail' => 'Alfred is briefly occupied and not ready for that request yet.',
				'action' => 'Wait a few seconds and try again.',
				'status_code' => $code,
			];
		}

		return [
			'code' => 0,
			'kind' => 'none',
			'title' => '',
			'detail' => '',
			'action' => '',
			'status_code' => $code,
		];
	}

	/**
	 * Resolve a raw LR4 code or a normalized bridge status to a catalog key.
	 *
	 * Direct catalog keys (`BR`, `CCP`, ...) pass straight through; normalized
	 * statuses (`cleaning`, `drawer_full`, ...) are translated. `ready` and
	 * `fault` deliberately resolve to null — neither names a condition, so the
	 * caller falls back to the integer entry.
	 */
	public function catalogKey(?string $status): ?string
	{
		$raw = trim((string) $status);
		if ($raw === '') {
			return null;
		}
		$catalog = $this->load();
		$upper = strtoupper($raw);
		if (isset($catalog['errors'][$upper]) || isset($catalog['not_ready'][$upper])) {
			return $upper;
		}
		return self::STATUS_CODE_ALIASES[strtolower($raw)] ?? null;
	}

	/** @return array{errors:array<string,array>,not_ready:array<string,array>} */
	public function load(): array
	{
		if ($this->catalog !== null) {
			return $this->catalog;
		}
		$path = $this->catalogPath ?? '';
		if ($path === '' || !is_file($path)) {
			$this->catalog = ['errors' => [], 'not_ready' => []];
			return $this->catalog;
		}
		$raw = file_get_contents($path);
		$data = json_decode($raw !== false ? $raw : '{}', true);
		if (!is_array($data)) {
			$this->catalog = ['errors' => [], 'not_ready' => []];
			return $this->catalog;
		}
		$this->catalog = [
			'errors' => is_array($data['errors'] ?? null) ? $data['errors'] : [],
			'not_ready' => is_array($data['not_ready'] ?? null) ? $data['not_ready'] : [],
		];
		return $this->catalog;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{code:int|string,kind:string,title:string,detail:string,action:string,status_code:?string}
	 */
	private function entry(string $kind, int|string $code, array $entry, ?string $statusCode): array
	{
		return [
			'code' => $code,
			'kind' => $kind,
			'title' => (string) ($entry['title'] ?? (string) $code),
			'detail' => (string) ($entry['detail'] ?? ''),
			'action' => (string) ($entry['action'] ?? ''),
			'status_code' => $statusCode,
		];
	}
}
