<?php

declare(strict_types=1);

namespace OCP;

interface IConfig
{
	public function getAppValue(string $appName, string $key, string $default = ''): string;

	public function setAppValue(string $appName, string $key, string $value): void;

	public function deleteAppValue(string $appName, string $key): void;

	public function deleteAppValues(string $appName): void;

	/** @param mixed $default */
	public function getSystemValue(string $key, $default = '');
}

interface IGroupManager
{
	public function isAdmin(string $uid): bool;

	public function isInGroup(string $uid, string $group): bool;
}

interface IUser
{
	public function getUID(): string;
}

interface IUserSession
{
	public function getUser(): ?IUser;
}

namespace OCP\Security;

interface ICrypto
{
	public function encrypt(string $plaintext, string $password = ''): string;

	public function decrypt(string $authenticatedCiphertext, string $password = ''): string;
}

namespace OCP\AppFramework;

class App
{
	public function __construct(string $appName)
	{
	}
}

namespace OCP\AppFramework\Bootstrap;

interface IBootstrap
{
}

interface IRegistrationContext
{
}

interface IBootContext
{
}

namespace OCP;

interface IDBConnection
{
}

namespace OCP\DB\QueryBuilder;

interface IQueryBuilder
{
	public const PARAM_INT = 1;
	public const PARAM_STR = 2;
	public const PARAM_NULL = 0;
	public const PARAM_INT_ARRAY = 101;
}

namespace OCP\AppFramework\Db;

class DoesNotExistException extends \Exception
{
}

/**
 * Minimal stand-in for Nextcloud's Entity: the magic get/set accessors, the
 * declared-type coercion and the dirty-field bookkeeping the app relies on.
 * Enough to exercise the services without a database.
 */
class Entity
{
	protected $id;

	/** @var array<string,string> */
	private array $fieldTypes = [];

	/** @var array<string,bool> */
	private array $updatedFields = [];

	public function __call(string $method, array $args)
	{
		if (str_starts_with($method, 'set')) {
			$this->assign(lcfirst(substr($method, 3)), $args[0] ?? null);
			return null;
		}
		if (str_starts_with($method, 'get')) {
			return $this->read(lcfirst(substr($method, 3)));
		}
		if (str_starts_with($method, 'is')) {
			return (bool) $this->read(lcfirst(substr($method, 2)));
		}
		throw new \BadFunctionCallException($method . ' does not exist');
	}

	protected function addType(string $field, string $type): void
	{
		$this->fieldTypes[$field] = $type;
	}

	private function assign(string $field, mixed $value): void
	{
		if (!property_exists($this, $field)) {
			throw new \BadFunctionCallException($field . ' is not a field of ' . static::class);
		}
		$this->updatedFields[$field] = true;
		if ($value === null) {
			$this->$field = null;
			return;
		}
		$type = $this->fieldTypes[$field] ?? null;
		if ($type !== null && in_array($type, ['integer', 'float', 'string', 'boolean'], true)) {
			settype($value, $type);
		}
		$this->$field = $value;
	}

	private function read(string $field): mixed
	{
		if (!property_exists($this, $field)) {
			throw new \BadFunctionCallException($field . ' is not a field of ' . static::class);
		}
		return $this->$field;
	}

	/** @return array<string,bool> */
	public function getUpdatedFields(): array
	{
		return $this->updatedFields;
	}

	public function resetUpdatedFields(): void
	{
		$this->updatedFields = [];
	}
}

/**
 * QBMapper stub with an all-optional constructor, so a test double can extend a
 * real mapper (the services type-hint the concrete classes) without a database.
 */
abstract class QBMapper
{
	protected $db;
	protected string $tableName = '';
	protected ?string $entityClass = null;

	public function __construct($db = null, string $tableName = '', ?string $entityClass = null)
	{
		$this->db = $db;
		$this->tableName = $tableName;
		$this->entityClass = $entityClass;
	}

	public function getTableName(): string
	{
		return $this->tableName;
	}
}

namespace OCP;

class Util
{
	public static function addStyle(string $app, string $name): void
	{
	}

	public static function addScript(string $app, string $name): void
	{
	}
}

namespace Psr\Log;

interface LoggerInterface
{
	public function emergency($message, array $context = []): void;

	public function alert($message, array $context = []): void;

	public function critical($message, array $context = []): void;

	public function error($message, array $context = []): void;

	public function warning($message, array $context = []): void;

	public function notice($message, array $context = []): void;

	public function info($message, array $context = []): void;

	public function debug($message, array $context = []): void;

	public function log($level, $message, array $context = []): void;
}

namespace OCP;

interface ITempManager
{
	public function getTempBaseDirectory(): string;
}

namespace OCP\Files;

interface IAppData
{
	/** @return mixed */
	public function getFolder(string $name);

	/** @return mixed */
	public function newFolder(string $name);
}
