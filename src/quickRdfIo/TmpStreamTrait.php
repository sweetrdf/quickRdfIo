<?php

/*
 * The MIT License
 *
 * Copyright 2021 zozlak.
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

use rdfInterface\QuadIterator as iQuadIterator;

/**
 * Description of TmpStreamTrait
 *
 * @author zozlak
 */
trait TmpStreamTrait {

    /**
     *
     * @var resource|null
     */
    private $tmpStream;

    public function parse(string $input): iQuadIterator {
        $this->closeTmpStream();
        $tmp = fopen('php://memory', 'r+');
        if ($tmp === false) {
            throw new RdfIoException('Failed to convert input to stream');
        }
        $this->tmpStream = $tmp;
        fwrite($this->tmpStream, $input);
        rewind($this->tmpStream);
        return $this->parseStream($this->tmpStream);
    }

    private function closeTmpStream(): void {
        if (is_resource($this->tmpStream)) {
            fclose($this->tmpStream);
            $this->tmpStream = null;
        }
    }
}
