<?php

/*
 * The MIT License
 *
 * Copyright 2026 zozlak.
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

use ArrayIterator;
use rdfInterface\DatasetIteratorInterface as iDatasetIterator;
use quickRdf\Dataset;

/**
 * Wrapper over the TriGParser class implementing the DatasetIteratorInterface
 *
 * @author zozlak
 */
class TriGMessageIterator implements iDatasetIterator {

    private TriGParser $parser;
    private Dataset $message;
    private int $n;

    public function __construct(TriGParser $parser) {
        $this->parser = $parser;
    }

    public function current(): Dataset {
        return $this->message;
    }

    public function key(): int {
        return $this->n;
    }

    public function next(): void {
        $this->n++;
        $this->message = new Dataset();
        while ($this->parser->valid()) {
            $n = $this->parser->getMessageCounter();
            if ($n !== $this->n) {
                break;
            }
            $this->message->add($this->parser->current());
            $this->parser->next();
        }
    }

    public function rewind(): void {
        $this->parser->rewind();
        $this->n = -1;
        $this->next();
    }

    public function valid(): bool {
        return count($this->message) > 0;
    }
}
