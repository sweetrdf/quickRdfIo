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
use Psr\Http\Message\StreamInterface;
use pietercolpaert\hardf\Util;
use pietercolpaert\hardf\TriGParser as Parser;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\Parser as iParser;
use rdfInterface\Quad as iQuad;
use rdfInterface\DataFactory as iDataFactory;

/**
 * Description of Parser
 *
 * @author zozlak
 */
class TriGParser implements iParser, iQuadIterator {

    use TmpStreamTrait;

    private const CHUNK_SIZE = 8192;

    private iDataFactory $dataFactory;

    /**
     *
     * @var array<mixed>
     */
    private array $options;

    /**
     *
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
     * @var callable|null
     */
    private $prefixCallback;

    /**
     *
     * @param iDataFactory $dataFactory factory to be used to generate RDF terms.
     * @param array<mixed> $options
     * @param callable|null $prefixCallback
     */
    public function __construct(iDataFactory $dataFactory, array $options = [],
                                callable | null $prefixCallback = null
    ) {
        $this->dataFactory    = $dataFactory;
        $this->options        = $options;
        $this->prefixCallback = $prefixCallback;
    }

    public function __destruct() {
        $this->closeTmpStream();
    }

    public function parseStream($input): iQuadIterator {
        if (!is_resource($input) && !($input instanceof StreamInterface)) {
            throw new RdfIoException("Input has to be a resource or a Psr\Http\Message\StreamInterface");
        }
        if ($input instanceof StreamInterface) {
            $input = \GuzzleHttp\Psr7\StreamWrapper::getResource($input);
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
                    $df   = $this->dataFactory;
                    $sbj  = Util::isBlank($quad['subject']) ?
                        $df::BlankNode($quad['subject']) : $df::NamedNode($quad['subject']);
                    $prop = $df::NamedNode($quad['predicate']);
                    if (substr($quad['object'], 0, 1) !== '"') {
                        $obj = Util::isBlank($quad['object']) ?
                            $df::BlankNode($quad['object']) : $df::NamedNode($quad['object']);
                    } else {
                        // as Util::getLiteralValue() doesn't work for multiline values
                        $value    = substr($quad['object'], 1, strrpos($quad['object'], '"') - 1);
                        $lang     = Util::getLiteralLanguage($quad['object']);
                        $datatype = empty($lang) ? Util::getLiteralType($quad['object']) : '';
                        $obj      = $df::Literal($value, $lang, $datatype);
                    }
                    $graph               = !empty($quad['graph']) ?
                        $df::namedNode($quad['graph']) : $df::defaultGraph();
                    $this->quadsBuffer[] = $df::quad($sbj, $prop, $obj, $graph);
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
        if (ftell($this->input) !== 0) {
            $ret = rewind($this->input);
            if ($ret !== true) {
                throw new RdfIoException("Can't seek in the input stream");
            }
        }
        $this->next();
    }

    public function valid(): bool {
        return $this->quadsBuffer->valid();
    }
}
