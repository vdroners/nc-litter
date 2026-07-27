<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

/**
 * Loads knowledge/maintenance_thresholds.json and emits advisory hints.
 *
 * Every LR4 rule is a `metric_state` comparison against one key of the metric
 * map the caller assembles from the bridge DTO (`drawer_level_pct`,
 * `litter_level_pct`, `cycles_since_empty`).
 */
class MaintenanceHintService
{
	private ?array $thresholds = null;

	public function __construct(
		private ?string $catalogPath = null,
	) {
		$this->catalogPath ??= dirname(__DIR__, 2) . '/knowledge/maintenance_thresholds.json';
	}

	/**
	 * @param array<string, mixed> $metrics values keyed by the rules' `metric_state`
	 * @return list<array{id:string,severity:string,title:string,detail:string,action:string}>
	 */
	public function hintsFor(array $metrics): array
	{
		$hints = [];
		foreach ($this->load() as $rule) {
			if (!is_array($rule) || empty($rule['id'])) {
				continue;
			}
			if ($this->matches($rule, $metrics)) {
				$hints[] = [
					'id' => (string) $rule['id'],
					'severity' => (string) ($rule['severity'] ?? 'info'),
					'title' => (string) ($rule['title'] ?? $rule['id']),
					'detail' => (string) ($rule['detail'] ?? ''),
					'action' => (string) ($rule['action'] ?? ''),
				];
			}
		}
		return $hints;
	}

	/** @return list<array<string, mixed>> */
	public function load(): array
	{
		if ($this->thresholds !== null) {
			return $this->thresholds;
		}
		$path = $this->catalogPath ?? '';
		if ($path === '' || !is_file($path)) {
			$this->thresholds = [];
			return $this->thresholds;
		}
		$raw = file_get_contents($path);
		$data = json_decode($raw !== false ? $raw : '{}', true);
		$list = is_array($data['thresholds'] ?? null) ? $data['thresholds'] : [];
		$this->thresholds = array_values(array_filter($list, 'is_array'));
		return $this->thresholds;
	}

	/**
	 * A rule fires only when its metric is present and numeric (or, for
	 * `equals`, string-comparable). A missing metric never fires a hint, so an
	 * unsupported sensor stays silent instead of shouting.
	 *
	 * @param array<string, mixed> $rule
	 * @param array<string, mixed> $metrics
	 */
	private function matches(array $rule, array $metrics): bool
	{
		$key = (string) ($rule['metric_state'] ?? '');
		if ($key === '' || !array_key_exists($key, $metrics)) {
			return false;
		}
		$value = $metrics[$key];
		if ($value === null) {
			return false;
		}
		if (array_key_exists('equals', $rule)) {
			return (string) $value === (string) $rule['equals'];
		}
		if (!is_numeric($value)) {
			return false;
		}
		$n = (float) $value;
		if (array_key_exists('lte', $rule)) {
			return $n <= (float) $rule['lte'];
		}
		if (array_key_exists('lt', $rule)) {
			return $n < (float) $rule['lt'];
		}
		if (array_key_exists('gte', $rule)) {
			return $n >= (float) $rule['gte'];
		}
		if (array_key_exists('gt', $rule)) {
			return $n > (float) $rule['gt'];
		}
		return false;
	}
}
