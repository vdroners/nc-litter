<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

/**
 * Loads knowledge/maintenance_thresholds.json and emits advisory hints.
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
	 * @param array<string, mixed> $bbrun
	 * @param array<string, mixed> $state
	 * @return list<array{id:string,severity:string,title:string,detail:string,action:string}>
	 */
	public function hintsFor(array $bbrun, array $state = []): array
	{
		$hints = [];
		foreach ($this->load() as $rule) {
			if (!is_array($rule) || empty($rule['id'])) {
				continue;
			}
			if ($this->matches($rule, $bbrun, $state)) {
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
	 * @param array<string, mixed> $rule
	 * @param array<string, mixed> $bbrun
	 * @param array<string, mixed> $state
	 */
	private function matches(array $rule, array $bbrun, array $state): bool
	{
		if (isset($rule['metric_state'])) {
			$key = (string) $rule['metric_state'];
			$value = $state[$key] ?? null;
			if (array_key_exists('equals', $rule)) {
				return (string) $value === (string) $rule['equals'];
			}
			if (array_key_exists('lte', $rule) && is_numeric($value)) {
				return (float) $value <= (float) $rule['lte'];
			}
			if (array_key_exists('gte', $rule) && is_numeric($value)) {
				return (float) $value >= (float) $rule['gte'];
			}
			return false;
		}

		$metric = (string) ($rule['metric'] ?? '');
		if ($metric === '' || !isset($bbrun[$metric]) || !is_numeric($bbrun[$metric])) {
			return false;
		}
		$value = (float) $bbrun[$metric];

		if (isset($rule['ratio_gt'], $rule['per_hours_metric'])) {
			$hoursKey = (string) $rule['per_hours_metric'];
			$hours = isset($bbrun[$hoursKey]) && is_numeric($bbrun[$hoursKey])
				? (float) $bbrun[$hoursKey]
				: 0.0;
			if ($hours <= 0.0) {
				return false;
			}
			return ($value / $hours) > (float) $rule['ratio_gt'];
		}
		if (array_key_exists('gte', $rule)) {
			return $value >= (float) $rule['gte'];
		}
		if (array_key_exists('gt', $rule)) {
			return $value > (float) $rule['gt'];
		}
		return false;
	}
}
