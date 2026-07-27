<?php

declare(strict_types=1);

namespace OCA\NcLitter\Middleware;

use OCA\NcLitter\Exception\ForbiddenException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;

class ForbiddenMiddleware extends Middleware
{
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function afterException($controller, $methodName, \Exception $exception): JSONResponse
	{
		if ($exception instanceof ForbiddenException) {
			$this->logger->warning('Access denied on {controller}::{method}: {msg}', [
				'controller' => get_class($controller),
				'method' => $methodName,
				'msg' => $exception->getMessage(),
			]);
			return new JSONResponse([
				'error' => 'forbidden',
				'message' => $exception->getMessage(),
			], 403);
		}

		throw $exception;
	}
}
