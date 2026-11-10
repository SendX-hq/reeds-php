<?php

declare(strict_types=1);

namespace Reeds\Core;

/** A downloaded file: a delivery label or an invoice PDF. */
final class FileResponse
{
    public function __construct(
        /** The filename Reeds suggests (`Content-Disposition`), when it sends one. */
        public readonly ?string $filename,
        public readonly string $contentType,
        /** The file's bytes. */
        public readonly string $data,
    ) {
    }
}
