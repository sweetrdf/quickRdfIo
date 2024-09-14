<?php

/*
 * The MIT License
 *
 * Copyright 2024 zozlak.
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

use Psr\Http\Message\StreamInterface;

/**
 * Description of StreamSkipBomTrait
 *
 * @author zozlak
 */
trait StreamSkipBomTrait {

    private $invalidBoms2B = [
        "\xEF\xFF" => "UTF-16 BE",
        "\xFF\xFE" => "UTF-16 LE",
    ];
    private $invalidBoms3B = [
        "\x2B\x2F\x76" => "UTF-7",
        "\xF7\x64\x4C" => "UTF-1",
        "\x0E\xFE\xFF" => "SCSU",
        "\xFB\xEE\x28" => "BOCU-1",
    ];
    private $invalidBoms4B = [
        "\x00\x00\xFE\xFF" => "UTF-32 BE",
        "\xFF\xFE\x00\x00" => "UTF-32 LE",
        "\xDD\x73\x66\x73" => "UTF-EBCDIC",
        "\x84\x31\x95\x33" => "GB18030",
    ];
    private $bomUtf8       = "\xEF\xBB\xBF";

    private function skipBom(StreamInterface $stream): void {
        if ($stream->isSeekable()) {
            $bom = $stream->read(4);
            if (isset($this->invalidBoms4B[$bom])) {
                throw new RdfIoException("Input stream has wrong encoding " . $this->invalidBoms4B[$bom]);
            }
            $bom = substr($bom, 0, 3);
            if ($bom === $this->bomUtf8) {
                $stream->seek(-1, SEEK_CUR);
                return;
            }
            if (isset($this->invalidBoms3B[$bom])) {
                throw new RdfIoException("Input stream has wrong encoding " . $this->invalidBoms3B[$bom]);
            }
            $bom = substr($bom, 0, 2);
            if (isset($this->invalidBoms2B[$bom])) {
                throw new RdfIoException("Input stream has wrong encoding " . $this->invalidBoms2B[$bom]);
            }
            // no BOM recognized - rewind
            $stream->seek(-4, SEEK_CUR);
        }
    }
}
