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
 * `status` / `status_label`, so callers should pass the status alongside the int.
 * Since the bridge DTO gained `status_code` (the raw LR4 code) the caller can
 * hand us the precise code; a status-code hit always wins over the generic int
 * entry.
 *
 * A catalog entry may carry an explicit `"kind"` to override the section it sits
 * in. That is how `RDY` lives in the catalog — documented, resolvable, quotable —
 * while still decoding to `kind: none` so a healthy unit raises nothing.
 */
class ErrorDecoderService
{
	/**
	 * Normalized bridge status vocabulary -> the LR4 catalog code that describes
	 * it. The bridge DTO reports the *normalized* status (`cleaning`, not `CCP`)
	 * as well as the raw code, so this table lets the same catalog serve both
	 * spellings — and keeps working for the history rows, which persist only the
	 * normalized `status_final`.
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
	 * @param string|null $status raw LR4 status code (`BR`) or the normalized
	 *   bridge status (`drawer_full`); either spelling resolves
	 * @return array{code:int|string,kind:string,title:string,detail:string,action:string,status_code:?string}
	 */
	public function decode(int $error, ?string $status = null): array
	{
		$catalog = $this->load();
		$code = $this->catalogKey($status);

		if ($error !== 0) {
			// A named status code beats the generic "error 1" entry.
			if ($code !== null && is_array($catalog['errors'][$code] ?? null)) {
				return $this->entry('error', $code, $catalog['errors'][$code], $code);
			}
			if ($code !== null && is_array($catalog['not_ready'][$code] ?? null)) {
				return $this->entry('not_ready', $code, $catalog['not_ready'][$code], $code);
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
	 * An entry's own `kind` wins over the section it was found in. `RDY` needs
	 * that: it belongs in the catalog (it is a real LR4 code operators see in
	 * logs) but it must decode to `none`, or every healthy poll would present the
	 * unit as "not ready".
	 *
	 * @param array<string, mixed> $entry
	 * @return array{code:int|string,kind:string,title:string,detail:string,action:string,status_code:?string}
	 */
	private function entry(string $kind, int|string $code, array $entry, ?string $statusCode): array
	{
		$declared = isset($entry['kind']) ? (string) $entry['kind'] : '';
		return [
			'code' => $code,
			'kind' => $declared !== '' ? $declared : $kind,
			'title' => (string) ($entry['title'] ?? (string) $code),
			'detail' => (string) ($entry['detail'] ?? ''),
			'action' => (string) ($entry['action'] ?? ''),
			'status_code' => $statusCode,
		];
	}
}
