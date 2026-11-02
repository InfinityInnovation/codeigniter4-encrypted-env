<?php

declare(strict_types=1);

namespace EncryptedEnv\Env;

final class EnvFileManager
{
    public function __construct(
        private readonly EnvFileTransformer $transformer
    ) {
    }

    public function process(string $path, bool $encrypt = true): void
    {
        (new EnvFileTransaction())->transform(
            $path,
            fn (string $content): string => $this->transformer->transform($content, $encrypt),
            $encrypt ? 'encrypt' : 'decrypt',
            ! $encrypt,
            ! $encrypt
        );
    }
}
