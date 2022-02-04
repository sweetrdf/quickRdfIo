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

use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\RdfNamespace as iRdfNamespace;

/**
 * Description of TmpStreamSerializerTrait
 *
 * @author zozlak
 */
trait TmpStreamSerializerTrait {

    public function serialize(iQuadIterator $graph, ?iRdfNamespace $nmsp = null): string {
        $output = '';
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RdfIoException('Failed to convert input to stream');
        }
        $this->serializeStream($stream, $graph, $nmsp);
        $len = ftell($stream);
        if ($len === false) {
            throw new RdfIoException('Failed to seek in output streem');
        }
        rewind($stream);
        $output = fread($stream, $len);
        if ($output === false) {
            throw new RdfIoException('Failed to read from output streem');
        }
        fclose($stream);
        return $output;
    }
}
