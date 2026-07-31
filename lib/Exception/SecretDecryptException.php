<?php

declare(strict_types=1);

namespace OCA\NcLitter\Exception;

/**
 * A stored `enc:v1:` secret could not be decrypted — almost always because the
 * Nextcloud instance secret was rotated after the value was written.
 *
 * This exists so the failure cannot be mistaken for a value: an earlier revision
 * returned the ciphertext, which the app then posted to Whisker as the account
 * password and reported back as "Whisker rejected those credentials". The
 * operator would have re-typed a correct password over and over.
 */
class SecretDecryptException extends \RuntimeException
{
}
