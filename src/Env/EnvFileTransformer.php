<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

use EncryptedEnv\Crypto\AesGcmCipher;
use RuntimeException;

final class EnvFileTransformer
{
    public function __construct(
        private readonly AesGcmCipher $cipher
    ) {
    }

    public function transform(string $content, bool $encrypt = true): string
    {
        return preg_replace_callback(
            '/^([ \t]*[A-Za-z_][A-Za-z0-9_.]*[ \t]*=[ \t]*)([^\r\n]*)(\r?)$/m',
            function (array $matches) use ($encrypt): string {
                $prefix = $matches[1];
                $value = $matches[2];
                $carriageReturn = $matches[3];

                if ($value === '' || str_starts_with(ltrim($value), '#')) {
                    return $matches[0];
                }

                $comment = '';

                $quote = null;
                $escaped = false;

                for ($i = 0; $i < strlen($value); $i++) {
                    $char = $value[$i];

                    if ($escaped) {
                        $escaped = false;
                        continue;
                    }

                    if ($char === chr(92) && $quote !== null) {
                        $escaped = true;
                        continue;
                    }

                    if ($quote !== null) {
                        if ($char === $quote) {
                            $quote = null;
                        }
                        continue;
                    }

                    if ($char === '"' || $char === "'") {
                        $quote = $char;
                        continue;
                    }

                    if ($char === '#' && $i > 0 && ctype_space($value[$i - 1])) {
                        $start = $i - 1;
                        while ($start > 0 && ctype_space($value[$start - 1])) {
                            $start--;
                        }

                        $comment = substr($value, $start);
                        $value = substr($value, 0, $start);
                        break;
                    }
                }

                $quoted = strlen($value) >= 2
                    && (($value[0] === '"' && substr($value, -1) === '"')
                    || ($value[0] === "'" && substr($value, -1) === "'"));

                $plainValue = $quoted ? substr($value, 1, -1) : $value;

                if ($encrypt) {
                    if (str_starts_with($plainValue, 'enc:v1:')) {
                        return $matches[0];
                    }

                    $result = $this->cipher->encrypt($plainValue);
                } else {
                    if (!str_starts_with($plainValue, 'enc:v1:')) {
                        return $matches[0];
                    }

                    $result = $this->cipher->decrypt($plainValue);
                }

                if ($quoted) {
                    $result = $value[0] . $result . $value[0];
                }

                return $prefix . $result . $comment . $carriageReturn;
            },
            $content
        ) ?? throw new RuntimeException('Unable to transform environment file.');
    }
}
