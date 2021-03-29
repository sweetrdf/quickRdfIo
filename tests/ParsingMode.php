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

use quickRdfIo\NQuadsParser as NQP;

/**
 * Description of ParsingMode
 *
 * @author zozlak
 */
class ParsingMode {

    public bool $strict;
    public int $mode;

    public function __construct(bool $strict, int $mode) {
        $this->strict = $strict;
        $this->mode   = $mode;
    }

    public function __toString() {
        $modes = [
            NQP::MODE_TRIPLES      => 'Triples',
            NQP::MODE_QUADS        => 'Quads',
            NQP::MODE_TRIPLES_STAR => 'TriplesStar',
            NQP::MODE_QUADS_STAR   => 'QuadsStar',
        ];
        $ret   = "Parsing mode: " . $modes[$this->mode];
        $ret   .= $this->strict ? 'Strict' : 'Relaxed';
        return $ret;
    }

    public function isStar(): bool {
        return $this->mode === NQP::MODE_TRIPLES_STAR || $this->mode === NQP::MODE_QUADS_STAR;
    }

    public function isStrict(): bool {
        return $this->strict;
    }

    public function isQuads(): bool {
        return $this->mode === NQP::MODE_QUADS || $this->mode === NQP::MODE_QUADS_STAR;
    }
}
