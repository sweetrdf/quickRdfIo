<?php

/*
 * The MIT License
 *
 * Copyright 2022 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace quickRdfIo;

use RuntimeException;

/**
 * A wrapper for a resource providing it with a PSR-7 StreamInterface API.
 *
 * @author zozlak
 */
class ResourceWrapper implements \Psr\Http\Message\StreamInterface {

    /**
     * 
     * @var resource
     */
    private $res;

    /**
     * 
     * @param resource $resource
     */
    public function __construct($resource) {
        $this->res = $resource;
    }

    public function __toString(): string {
        try {
            $this->rewind();
        } catch (RuntimeException $ex) {
            
        }
        return $this->getContents();
    }

    public function close(): void {
        fclose($this->res);
    }

    /**
     * 
     * @return resource
     */
    public function detach() {
        $res = $this->res;
        unset($this->res);
        return $res;
    }

    public function eof(): bool {
        return feof($this->res);
    }

    public function getContents(): string {
        return stream_get_contents($this->res) ?: throw new RuntimeException("Reading from resource/stream failed");
    }

    /**
     * 
     * @param string | null $key
     * @return mixed
     */
    public function getMetadata($key = null): mixed {
        $meta = stream_get_meta_data($this->res);
        if ($key === null) {
            return $meta;
        }
        return $meta[$key] ?? null;
    }

    public function getSize(): int | null {
        $uri = $this->getMetadata('uri');
        if ($uri !== null && file_exists($uri)) {
            return filesize($uri) ?: null;
        }
        $remain = $this->getMetadata('unread_bytes');
        if ($remain === null) {
            return null;
        }
        return $this->tell() + (int) $remain;
    }

    public function isReadable(): bool {
        return str_contains($this->getMetadata('mode') ?: '', 'r');
    }

    public function isSeekable(): bool {
        return $this->getMetadata('seekable') ?: false;
    }

    public function isWritable(): bool {
        return str_contains($this->getMetadata('mode') ?: '', 'w');
    }

    /**
     * 
     * @param int $length
     * @return string
     */
    public function read($length): string {
        return stream_get_contents($this->res, $length) ?: throw new RuntimeException("Reading from resource/stream failed");
    }

    public function rewind(): void {
        $this->seek(0);
    }

    /**
     * 
     * @param int $offset
     * @param int $whence
     * @return void
     * @throws RuntimeException
     */
    public function seek($offset, $whence = SEEK_SET): void {
        if (!$this->isSeekable()) {
            throw new RuntimeException("The underlaying resource/stream isn't seekable");
        }
        fseek($this->res, $offset, $whence);
    }

    public function tell(): int {
        $pos = ftell($this->res);
        if ($pos === false) {
            throw new RuntimeException("The underlaying resource/stream doesn't support ftell()");
        }
        return $pos;
    }

    /**
     * 
     * @param string $string
     * @return int
     */
    public function write($string): int {
        $ret = fwrite($this->res, $string);
        if ($ret === false) {
            throw new RuntimeException("Writing to resource/stream failed");
        }
        return $ret;
    }
}
