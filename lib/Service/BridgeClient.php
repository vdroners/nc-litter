<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * HTTP client to the private nc-litter-bridge (Docker DNS).
 */
class BridgeClient
{
	private const CONNECT_TIMEOUT = 5;
	private const TIMEOUT = 30;

	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function getBaseUrl(): string
	{
		$url = trim($this->config->getAppValue(
			Application::APP_ID,
			'bridge_url',
			Application::DEFAULT_BRIDGE_URL,
		));
		return rtrim($url !== '' ? $url : Application::DEFAULT_BRIDGE_URL, '/');
	}

	/** @return array{ok:bool,status:int,body:?array,raw:string,error:?string} */
	public function health(): array
	{
		return $this->request('GET', '/health');
	}

	/** @return array{ok:bool,status:int,body:?array,raw:string,error:?string} */
	public function getState(int $robotId = 1): array
	{
		return $this->request('GET', '/state', ['robot_id' => $robotId]);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function action(string $name, int $robotId = 1, array $payload = []): array
	{
		$body = array_merge(['robot_id' => $robotId], $payload);
		return $this->request('POST', '/action/' . rawurlencode($name), null, $body);
	}

	/** @return array{ok:bool,status:int,body:?array,raw:string,error:?string} */
	public function getSchedule(int $robotId = 1): array
	{
		return $this->request('GET', '/schedule', ['robot_id' => $robotId]);
	}

	/**
	 * @param array<string, mixed> $week
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function setSchedule(array $week, int $robotId = 1): array
	{
		return $this->request('POST', '/schedule', null, [
			'robot_id' => $robotId,
			'week' => $week,
		]);
	}

	/** @return array{ok:bool,status:int,body:?array,raw:string,error:?string} */
	public function getPreferences(int $robotId = 1): array
	{
		return $this->request('GET', '/preferences', ['robot_id' => $robotId]);
	}

	/**
	 * @param array<string, mixed> $prefs
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function setPreferences(array $prefs, int $robotId = 1): array
	{
		return $this->request('POST', '/preferences', null, [
			'robot_id' => $robotId,
			'preferences' => $prefs,
		]);
	}

	/**
	 * @param array<string, mixed> $opts
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function discover(array $opts = []): array
	{
		// Subnet TCP scan + public-info probes can exceed the default 30s budget.
		return $this->request('POST', '/discover', null, $opts, 90);
	}

	/**
	 * Hold-HOME onboarding: ask bridge to fetch BLID/password from robot.
	 *
	 * @param array{ip?:string,timeout?:int} $opts
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function getPassword(array $opts): array
	{
		return $this->request('POST', '/onboard/get-password', null, $opts);
	}

	/**
	 * Soft-AP Wi-Fi scan via host wifi-helper (Litter-Robot-* SSIDs).
	 *
	 * @param array<string, mixed> $opts
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function softapScan(array $opts = []): array
	{
		return $this->request('POST', '/onboard/softap-scan', null, $opts, 60);
	}

	/**
	 * Soft-AP provision (join robot AP → wlcfg → LAN discover). Long-running.
	 *
	 * @param array<string, mixed> $opts
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function softapProvision(array $opts): array
	{
		return $this->request('POST', '/onboard/softap-provision', null, $opts, 240);
	}

	/** @return array{ok:bool,status:int,body:?array,raw:string,error:?string} */
	public function softapStatus(): array
	{
		return $this->request('GET', '/onboard/softap-status', null, null, 10);
	}

	/**
	 * @param array{blid:string,password:string,ip:string,name?:string} $creds
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function connect(array $creds): array
	{
		return $this->request('POST', '/connect', null, $creds);
	}

	/** @return array{ok:bool,status:int,body:?array,raw:string,error:?string} */
	public function connectTest(int $robotId = 1): array
	{
		return $this->request('POST', '/connect-test', null, ['robot_id' => $robotId]);
	}

	/**
	 * Proxy the bridge SSE stream to the current PHP output buffer.
	 * Caller should have already sent SSE response headers.
	 *
	 * @return int HTTP status from upstream (0 on transport failure)
	 */
	public function proxyStream(int $robotId = 1, int $timeoutSeconds = 0): int
	{
		$url = $this->getBaseUrl() . '/stream?robot_id=' . $robotId;
		$ch = curl_init($url);
		if ($ch === false) {
			return 0;
		}
		curl_setopt_array($ch, [
			CURLOPT_HTTPHEADER => ['Accept: text/event-stream', 'Cache-Control: no-cache'],
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT => $timeoutSeconds > 0 ? $timeoutSeconds : 0,
			CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk): int {
				echo $chunk;
				if (function_exists('ob_flush')) {
					@ob_flush();
				}
				flush();
				return strlen($chunk);
			},
			CURLOPT_FOLLOWLOCATION => false,
		]);
		$ok = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($ok === false) {
			$this->logger->warning('BridgeClient SSE proxy failed: {err}', [
				'err' => curl_error($ch),
			]);
			$status = 0;
		}
		curl_close($ch);
		return $status;
	}

	/**
	 * @param array<string, scalar>|null $query
	 * @param array<string, mixed>|null $jsonBody
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function request(string $method, string $path, ?array $query = null, ?array $jsonBody = null, ?int $timeoutSeconds = null): array
	{
		$url = $this->getBaseUrl() . $path;
		if ($query !== null && $query !== []) {
			$url .= '?' . http_build_query($query);
		}

		$ch = curl_init($url);
		if ($ch === false) {
			return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => 'curl_init failed'];
		}

		$headers = ['Accept: application/json'];
		$opts = [
			CURLOPT_CUSTOMREQUEST => strtoupper($method),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT => $timeoutSeconds ?? self::TIMEOUT,
			CURLOPT_FOLLOWLOCATION => false,
		];
		if ($jsonBody !== null) {
			$payload = json_encode($jsonBody, JSON_THROW_ON_ERROR);
			$headers[] = 'Content-Type: application/json';
			$opts[CURLOPT_POSTFIELDS] = $payload;
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);

		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = $errno !== 0 ? curl_error($ch) : null;
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false) {
			$this->logger->warning('BridgeClient request failed {method} {path}: {err}', [
				'method' => $method,
				'path' => $path,
				'err' => $error ?? 'unknown',
			]);
			return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => $error ?? 'request failed'];
		}

		$body = null;
		$decoded = json_decode((string) $raw, true);
		if (is_array($decoded)) {
			$body = $decoded;
		}

		return [
			'ok' => $status >= 200 && $status < 300,
			'status' => $status,
			'body' => $body,
			'raw' => (string) $raw,
			'error' => $status >= 200 && $status < 300 ? null : ($body['error'] ?? $body['message'] ?? 'bridge_error'),
		];
	}
}
