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

use Exception;
use ArrayIterator;
use Psr\Http\Message\StreamInterface;
use pietercolpaert\hardf\Util;
use pietercolpaert\hardf\TriGParser as Parser;
use rdfInterface\QuadIteratorInterface as iQuadIterator;
use rdfInterface\ParserInterface as iParser;
use rdfInterface\QuadInterface as iQuad;
use rdfInterface\DatasetIteratorInterface as iDatasetIterator;
use rdfInterface\DataFactoryInterface as iDataFactory;

/**
 * Description of Parser
 *
 * @author zozlak
 */
class TriGParser implements iParser, iQuadIterator {

    use TmpStreamParserTrait;
    use StreamSkipBomTrait;
    use CreateBlankNodeTrait;

    const CHUNK_SIZE = 8192;

    private iDataFactory $dataFactory;

    /**
     *
     * @var array<mixed>
     */
    private array $options;
    private Parser $parser;
    private StreamInterface $input;
    private string $chunk;

    /**
     * Container for {quad, messageCounter} pairs
     * @var ArrayIterator<array{0: iQuad, 1: int|null}>
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
     * @param array<mixed> $options options to be passed to the pietercolpaert\hardf\TriGParser
     *   constructor
     * @param callable|null $prefixCallback a callable for handling prefixes
     *   to be passed to the pietercolpaert\hardf\TriGParser constructor
     * @see \pietercolpaert\hardf\TriGParser::__construct()
     */
    public function __construct(iDataFactory $dataFactory, array $options = [],
                                callable | null $prefixCallback = null
    ) {
        $this->dataFactory         = $dataFactory;
        $this->options             = $options;
        $this->options['messages'] = true;
        $this->prefixCallback      = $prefixCallback;
    }

    public function __destruct() {
        $this->closeTmpStream();
    }

    /**
     * 
     * @param resource | StreamInterface $input
     * @return iQuadIterator
     */
    public function parseStream($input, string $baseUri = ''): iQuadIterator {
        $this->init($input, $baseUri);
        return $this;
    }

    public function parseMessages(string $input, string $baseUri = ''): iDatasetIterator {
        $this->initTmpStream($input);
        return $this->parseMessagesStream($this->tmpStream, $baseUri);
    }

    public function parseMessagesStream($input, string $baseUri = ''): iDatasetIterator {
        $this->init($input, $baseUri);
        return new TriGMessageIterator($this);
    }

    public function current(): iQuad | null {
        return $this->quadsBuffer->current()[0] ?? null;
    }

    public function key(): mixed {
        return $this->n;
    }

    public function next(): void {
        $this->quadsBuffer->next();
        if (!$this->quadsBuffer->valid()) {
            $this->quadsBuffer = new ArrayIterator();
            $this->parser->setTripleCallback(function (
                ?Exception $e, ?array $quad, ?array $prefixes = null,
                ?int $counter = null
            ): void {
                if ($e) {
                    throw $e;
                }
                if ($quad) {
                    $df   = $this->dataFactory;
                    $sbj  = Util::isBlank($quad['subject']) ?
                        $this->createBlankNode($quad['subject']) : $df::namedNode($quad['subject']);
                    $prop = $df::namedNode($quad['predicate']);
                    if (substr($quad['object'], 0, 1) !== '"') {
                        $obj = Util::isBlank($quad['object']) ?
                            $this->createBlankNode($quad['object']) : $df::namedNode($quad['object']);
                    } else {
                        // as Util::getLiteralValue() doesn't work for multiline values
                        $value    = substr($quad['object'], 1, strrpos($quad['object'], '"') - 1);
                        $lang     = Util::getLiteralLanguage($quad['object']);
                        $datatype = empty($lang) ? Util::getLiteralType($quad['object']) : '';
                        $obj      = $df::Literal($value, $lang, $datatype);
                    }
                    $graph               = !empty($quad['graph']) ?
                        $df::namedNode($quad['graph']) : $df::defaultGraph();
                    $this->quadsBuffer[] = [
                        $df::quad($sbj, $prop, $obj, $graph),
                        $counter
                    ];
                }
            });
            while (!$this->input->eof() && $this->quadsBuffer->count() === 0) {
                $this->chunk .= $this->input->read(self::CHUNK_SIZE);
                $p           = strlen($this->chunk) - 1;
                $cp          = ord($this->chunk[$p]);
                if ($cp < 127) {
                    // chunk ends with a single-byte UTF-8 character - just parse whole chunk
                    $this->parser->parseChunk($this->chunk);
                    $this->chunk = '';
                } else {
                    // find the position of the first byte of the UTF-8 character ending the chunk
                    // parse the chunk part excluding it
                    while ($cp < 192) {
                        $p--;
                        $cp = ord($this->chunk[$p]);
                    }
                    $this->parser->parseChunk(substr($this->chunk, 0, $p));
                    $this->chunk = substr($this->chunk, $p);
                }
            }
            if ($this->input->eof()) {
                $this->parser->end();
            }
        }
        $this->n++;
    }

    public function rewind(): void {
        $this->n = -1;
        if ($this->input->tell() !== 0) {
            $this->input->rewind();
        }
        $this->skipBom($this->input);
        $this->next();
    }

    public function valid(): bool {
        return $this->quadsBuffer->valid();
    }

    public function getMessageCounter(): int | null {
        return $this->quadsBuffer->current()[1] ?? null;
    }

    /**
     * @param resource | \Psr\Http\Message\StreamInterface $input
     */
    private function init($input, string $baseUri): void {
        if (is_resource($input)) {
            $input = new ResourceWrapper($input);
        }
        if (!($input instanceof StreamInterface)) {
            throw new RdfIoException("Input has to be a resource or " . StreamInterface::class . " object");
        }

        $this->input       = $input;
        $this->n           = -1;
        $this->chunk       = '';
        $this->quadsBuffer = new ArrayIterator();
        $this->baseUri     = $baseUri;
        $this->parser      = new Parser($this->options, null, $this->prefixCallback);
    }
}
