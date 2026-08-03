<?php

declare(strict_types=1);

namespace OCA\NcLitter\Service;

use OCA\NcLitter\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * HTTP client to the private nc-litter-bridge (Docker DNS).
 *
 * Mirrors bridge/app.py exactly:
 *   GET  /health                -> {ok,connected,mock,version,error,device_present}
 *   GET  /state                 -> {ok,state:{DTO}}
 *   GET  /stream                -> text/event-stream, `event: state` frames
 *   POST /action/{name}          -> {ok,result,error}
 *   GET  /settings              -> {ok,settings}
 *   POST /settings              -> {ok,settings}
 *   POST /onboard/login         -> {ok,devices:[{id,name,model,serial}]}
 *   POST /connect               -> {ok,connected,mock,version,error,device_present}
 *
 * The bridge process binds exactly one Litter-Robot, so `$deviceId` is advisory:
 * it is echoed on GET requests to make the app-side device visible in bridge
 * access logs, and deliberately kept out of POST bodies (FastAPI forwards
 * unknown action-body keys straight into the pylitterbot call as kwargs).
 */
class BridgeClient
{
	private const CONNECT_TIMEOUT = 5;
	private const TIMEOUT = 30;
	/** Whisker cloud login is slower than a local call — give it room. */
	private const LOGIN_TIMEOUT = 60;

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
	public function getState(int $deviceId = 1): array
	{
		return $this->request('GET', '/state', ['device_id' => $deviceId]);
	}

	/**
	 * @param array<string, mixed> $params extra action arguments (e.g. wait_time)
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function action(string $name, int $deviceId = 1, array $params = []): array
	{
		return $this->request(
			'POST',
			'/action/' . rawurlencode($name),
			['device_id' => $deviceId],
			$params,
		);
	}

	/** @return array{ok:bool,status:int,body:?array,raw:string,error:?string} */
	public function getSettings(int $deviceId = 1): array
	{
		return $this->request('GET', '/settings', ['device_id' => $deviceId]);
	}

	/**
	 * @param array<string, mixed> $settings patch; only present keys are applied
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function setSettings(array $settings, int $deviceId = 1): array
	{
		return $this->request('POST', '/settings', ['device_id' => $deviceId], $settings);
	}

	/**
	 * Enumerate the Litter-Robot 4 units on a Whisker account. Does not bind or
	 * persist anything bridge-side.
	 *
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function onboardLogin(string $email, string $password): array
	{
		return $this->request('POST', '/onboard/login', null, [
			'email' => $email,
			'password' => $password,
		], self::LOGIN_TIMEOUT);
	}

	/**
	 * Bind the active device for the bridge process.
	 *
	 * @param string $deviceId Whisker device id / serial ('' = first LR4 found)
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function connect(string $email, string $password, string $deviceId = ''): array
	{
		$body = ['email' => $email, 'password' => $password];
		if ($deviceId !== '') {
			$body['device_id'] = $deviceId;
		}
		return $this->request('POST', '/connect', null, $body, self::LOGIN_TIMEOUT);
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
		// No curl_close(): the handle is freed when it goes out of scope. The call has
		// been a no-op since PHP 8.0 and is deprecated in 8.5, which the Nextcloud
		// image now ships -- it was emitting a deprecation on every bridge request.

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
