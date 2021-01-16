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

use ArrayIterator;
use pietercolpaert\hardf\Util;
use pietercolpaert\hardf\TriGParser as Parser;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\Parser as iParser;
use rdfInterface\Quad as iQuad;
use quickRdfIo\DataFactory as DF;

/**
 * Description of Parser
 *
 * @author zozlak
 */
class TriGParser implements iParser, iQuadIterator {

    private const CHUNK_SIZE = 8192;

    /**
     *
     * @var array<mixed>
     */
    private array $options;

    /**
     *
     * @var \pietercolpaert\hardf\TriGParser
     */
    private Parser $parser;

    /**
     *
     * @var resource
     */
    private $input;

    /**
     *
     * @var ArrayIterator<int, iQuad>
     */
    private ArrayIterator $quadsBuffer;
    private int $n;

    /**
     *
     * @var resource|null
     */
    private $tmpStream;

    /**
     *
     * @var callable|null
     */
    private $prefixCallback;

    /**
     *
     * @param array<mixed> $options
     * @param callable|null $prefixCallback
     */
    public function __construct(
        array $options = [], callable | null $prefixCallback = null
    ) {
        $this->options        = $options;
        $this->prefixCallback = $prefixCallback;
    }

    public function __destruct() {
        $this->closeTmpStream();
    }

    public function parse(string $input): iQuadIterator {
        $this->closeTmpStream();
        $tmp = fopen('php://memory', 'r+');
        if ($tmp === false) {
            throw new RdfException('Failed to convert input to stream');
        }
        $this->tmpStream = $tmp;
        fwrite($this->tmpStream, $input);
        rewind($this->tmpStream);
        return $this->parseStream($this->tmpStream);
    }

    public function parseStream($input): iQuadIterator {
        if (!is_resource($input)) {
            throw new RdfException("Input has to be a resource");
        }

        $this->input       = $input;
        $this->n           = -1;
        $this->quadsBuffer = new ArrayIterator();
        $this->parser      = new Parser($this->options, null, $this->prefixCallback);
        return $this;
    }

    public function current(): iQuad {
        return $this->quadsBuffer->current();
    }

    public function key() {
        return $this->n;
    }

    public function next(): void {
        $this->quadsBuffer->next();
        if (!$this->quadsBuffer->valid()) {
            $this->quadsBuffer = new ArrayIterator();
            $this->parser->setTripleCallback(function (
                ?\Exception $e, ?array $quad
            ): void {
                if ($e) {
                    throw $e;
                }
                if ($quad) {
                    $sbj  = Util::isBlank($quad['subject']) ?
                        DF::BlankNode($quad['subject']) : DF::NamedNode($quad['subject']);
                    $prop = DF::NamedNode($quad['predicate']);
                    if (substr($quad['object'], 0, 1) !== '"') {
                        $obj = Util::isBlank($quad['object']) ?
                            DF::BlankNode($quad['object']) : DF::NamedNode($quad['object']);
                    } else {
                        // as Util::getLiteralValue() doesn't work for multiline values
                        $value    = substr($quad['object'], 1, strrpos($quad['object'], '"') - 1);
                        $lang     = Util::getLiteralLanguage($quad['object']);
                        $datatype = empty($lang) ? Util::getLiteralType($quad['object']) : '';
                        $obj      = DF::Literal($value, $lang, $datatype);
                    }
                    $graph               = !empty($quad['graph']) ?
                        DF::namedNode($quad['graph']) : DF::defaultGraph();
                    $this->quadsBuffer[] = DF::quad($sbj, $prop, $obj, $graph);
                }
            });
            while (!feof($this->input) && $this->quadsBuffer->count() === 0) {
                $this->parser->parseChunk(fgets($this->input, self::CHUNK_SIZE));
            }
            if (feof($this->input)) {
                $this->parser->end();
            }
        }
        $this->n++;
    }

    public function rewind(): void {
        $ret = rewind($this->input);
        if ($ret !== true) {
            throw new RdfException("Can't seek in the input stream");
        }
        $this->next();
    }

    public function valid(): bool {
        return $this->quadsBuffer->valid();
    }

    private function closeTmpStream(): void {
        if (is_resource($this->tmpStream)) {
            fclose($this->tmpStream);
            $this->tmpStream = null;
        }
    }
}
